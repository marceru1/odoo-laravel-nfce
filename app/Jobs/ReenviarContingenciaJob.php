<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use App\Services\FocusNfceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ReenviarContingenciaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Configuração de Retentativas (Backoff Exponencial)
     * 
     * Tentativa 1: +30s
     * Tentativa 2: +1min
     * Tentativa 3: +3min
     * Tentativa 4: +5min
     * Tentativa 5: +10min
     * Tentativa 6: +15min
     * Tentativa 7: +30min
     */
    public $tries = 7;
    public $backoff = [30, 60, 180, 300, 600, 900, 1800];
    public $timeout = 60;

    public function __construct(
        public FiscalDocument $documento
    ) {}

    public function handle(): void
    {
        // 1. PROTEÇÃO: Se já foi autorizada, encerra
        $this->documento->refresh();

        if ($this->documento->status === 'autorizado') {
            Log::info("✅ Job Contingência: Doc #{$this->documento->numero_pedido} já autorizado. Encerrando.");
            return;
        }

        // 2. PROTEÇÃO: Se não está em contingência, algo deu errado
        if (!$this->documento->em_contingencia) {
            Log::warning("⚠️ Job Contingência: Doc #{$this->documento->numero_pedido} não está marcado como contingência. Abortando.");
            return;
        }

        // 3. PROTEÇÃO: Verifica se tem dados offline
        $dadosOffline = $this->documento->xml_offline;

        // 👇 CORREÇÃO: Se vier string (JSON), decodifica na marra
        if (is_string($dadosOffline)) {
            $dadosOffline = json_decode($dadosOffline, true);
        }

        // Proteção extra: Se depois de tentar decodificar ainda não for array...
        if (!is_array($dadosOffline)) {
            Log::error("❌ Job Contingência: 'xml_offline' inválido ou corrompido para Doc #{$this->documento->id}.");
            
            $this->documento->update([
                'status' => 'erro_fiscal',
                'mensagem_sefaz' => 'Dados offline corrompidos (formato inválido)',
            ]);
            return;
        }
        Log::info("🔄 Job Contingência: Tentando transmitir Doc #{$this->documento->numero_pedido} (Tentativa {$this->attempts()}/{$this->tries})");

        try {
            $this->transmitirParaFocus($dadosOffline);

        } catch (Throwable $e) {
            Log::warning("⚠️ Job Contingência: Falha na tentativa {$this->attempts()}: {$e->getMessage()}");
            
            // Se foi a última tentativa, marca como erro
            if ($this->attempts() >= $this->tries) {
                $this->documento->update([
                    'status' => 'erro_fiscal',
                    'mensagem_sefaz' => 'Falha ao transmitir contingência após ' . $this->tries . ' tentativas: ' . Str::limit($e->getMessage(), 500),
                ]);

                $this->registrarEvento('erro', 'falha_transmissao_final', [
                    'tentativas' => $this->tries,
                    'ultimo_erro' => Str::limit($e->getMessage(), 2000),
                ]);

                Log::error("❌ Job Contingência: Esgotadas todas as tentativas para Doc #{$this->documento->numero_pedido}");
            }

            // Relança para o Laravel agendar próxima tentativa
            throw $e;
        }
    }

    /**
     * Transmite a nota para a Focus com os dados da contingência offline.
     */
    private function transmitirParaFocus(array $dadosOffline): void
    {
        $baseUrl = config('services.focus.url');
        $token = config('services.focus.token');

        // URL especial: forma_emissao=offline informa à Focus que a nota JÁ FOI IMPRESSA
        $url = "{$baseUrl}/v2/nfce?ref={$this->documento->numero_pedido}&forma_emissao=offline";

        // Prepara o payload combinando dados originais + dados offline
        $payload = $this->documento->payload_envio;

        // Sobrescreve com os dados EXATOS usados na contingência
        $payload['numero'] = $dadosOffline['numero'];
        $payload['serie']  = $dadosOffline['serie'];
        $payload['data_emissao'] = $dadosOffline['data_emissao'];

        // ✅ Focus doc: o campo correto para offline é 'codigo_unico' (tag cNF da NF-e).
        // 'codigo_aleatorio' é ignorado pela Focus no modo offline — ela gera seu próprio
        // cNF se não receber 'codigo_unico', causando divergência de chave no cupom.
        if (isset($dadosOffline['codigo_unico'])) {
            $payload['codigo_unico'] = str_pad((string) $dadosOffline['codigo_unico'], 8, '0', STR_PAD_LEFT);
            unset($payload['codigo_aleatorio']); // Remove campo incorreto se vier do payload original
        }

        // Tipo de emissão = 9 (Contingência Offline)
        $payload['tipo_emissao'] = 9;

        Log::info("📤 [CONTINGENCIA-JOB] Enviando para Focus:", [
            'url'             => $url,
            'numero'          => $payload['numero'],
            'serie'           => $payload['serie'],
            'codigo_unico'    => $payload['codigo_unico'] ?? 'NAO_DEFINIDO',
            'tipo_emissao'    => $payload['tipo_emissao'],
            'chave_esperada'  => $this->documento->chave_acesso,
        ]);

        // Envia para a Focus
        $response = Http::withBasicAuth($token, '')
            ->timeout($this->timeout)
            ->post($url, $payload);

        // Trata a resposta
        $this->processarResposta($response);
    }

    /**
     * Processa a resposta da Focus.
     */
    private function processarResposta($response): void
    {
        // ERRO 4xx - Problema no payload (não adianta retentar)
        if ($response->clientError()) {
            $body = $response->json();

            // Caso especial: Nota já processada
            if (($body['codigo'] ?? '') === 'already_processed') {
                Log::info("Job Contingência: Nota já processada anteriormente. Consultando status...", [
                    'ref'           => $this->documento->numero_pedido,
                    'body_focus'    => $body,
                    'status_http'   => $response->status(),
                    'chave_acesso'  => $this->documento->chave_acesso,
                ]);
                $this->consultarStatus();
                return;
            }

            // Rejeição definitiva
            $mensagem = $body['mensagem_sefaz'] ?? ($body['mensagem'] ?? 'Erro de validação');
            
            $this->documento->update([
                'status' => 'rejeitado',
                'codigo_sefaz' => $body['codigo_sefaz'] ?? null,
                'mensagem_sefaz' => Str::limit($mensagem, 5000),
                'payload_resposta' => $body,
            ]);

            $this->registrarEvento('retorno', 'rejeitado', $body);
            NotificarOdooJob::dispatch($this->documento->id);

            Log::error("❌ Job Contingência: Rejeitado pela Focus/SEFAZ: {$mensagem}");
            return; // Não relança - encerra com "sucesso" mas status rejeitado
        }

        // ERRO 5xx - Problema no servidor (retentar)
        if ($response->serverError()) {
            throw new \Exception("Servidor Focus indisponível (HTTP {$response->status()})");
        }

        // ERRO de conexão/timeout - já é tratado como exception pelo Http

        // SUCESSO - Processa resposta
        if ($response->successful()) {
            $body = $response->json();
            $statusFocus = $body['status'] ?? 'desconhecido';

            Log::info("Job Contingência: Resposta da Focus recebida", [
                'status' => $statusFocus,
                'codigo_sefaz' => $body['status_sefaz'] ?? null,
            ]);

            $this->registrarEvento('retorno', $statusFocus, $body);

            // Delega para o Service processar a lógica da SEFAZ
            app(FocusNfceService::class)->processarLogicaSefaz($this->documento, $body);
            
            return;
        }

        // Qualquer outro caso - tenta novamente
        throw new \Exception("Resposta inesperada da Focus (HTTP {$response->status()})");
    }

    /**
     * Consulta o status de uma nota já processada.
     */
    private function consultarStatus(): void
    {
        try {
            $baseUrl = config('services.focus.url');
            $token   = config('services.focus.token');
            $ref     = $this->documento->numero_pedido;

            $consultaUrl = "{$baseUrl}/v2/nfce/{$ref}";
            Log::info("🔍 [CONTINGENCIA-JOB] Consultando nota na Focus:", ['url' => $consultaUrl]);

            $response = Http::withBasicAuth($token, '')
                ->timeout(15)
                ->get($consultaUrl);

            Log::info("🔍 [CONTINGENCIA-JOB] Resposta da consulta Focus:", [
                'status_http' => $response->status(),
                'body'        => $response->json(),
            ]);

            if ($response->successful()) {
                app(FocusNfceService::class)->processarLogicaSefaz($this->documento, $response->json());
            } else {
                Log::error("🔍 [CONTINGENCIA-JOB] Consulta falhou na Focus.", [
                    'status_http' => $response->status(),
                    'body'        => $response->body(),
                ]);
                throw new \Exception("Consulta Focus falhou HTTP {$response->status()}: {$response->body()}");
            }

        } catch (Throwable $e) {
            Log::error("Erro ao consultar nota já processada: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Registra evento no log fiscal.
     */
    private function registrarEvento(string $tipo, string $status, ?array $payload = null): void
    {
        \App\Models\FiscalEvent::create([
            'fiscal_document_id' => $this->documento->id,
            'tipo' => $tipo,
            'status' => Str::limit($status, 100),
            'payload' => $payload,
            'meta' => [
                'job' => 'ReenviarContingenciaJob',
                'tentativa' => $this->attempts(),
            ],
        ]);
    }

    /**
     * Executado quando o job falhar definitivamente.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("❌ Job Contingência FALHOU definitivamente para Doc #{$this->documento->numero_pedido}", [
            'erro' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->documento->update([
            'status' => 'erro_fiscal',
            'mensagem_sefaz' => 'Esgotadas todas as tentativas de transmissão: ' . Str::limit($exception->getMessage(), 500),
        ]);

        $this->registrarEvento('erro', 'falha_definitiva', [
            'erro' => Str::limit($exception->getMessage(), 2000),
            'tentativas_totais' => $this->tries,
        ]);
    }
}
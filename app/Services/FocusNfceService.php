<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\FiscalEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use App\Jobs\NotificarOdooJob;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class FocusNfceService
{
    protected string $baseUrl;
    protected string $token;

    public function __construct()
    {
        $this->baseUrl = config('services.focus.url');
        $this->token   = config('services.focus.token');
    }

    public function enviar(FiscalDocument $documento): void
    {
        Log::info("[FOCUS-API] Iniciando envio da NFCe de ID {$documento->id} (Pedido #{$documento->numero_pedido})...");
        
        $documento->update([
            'status' => 'processando',
            'tentativas' => $documento->tentativas + 1,
            'ultima_tentativa_em' => now(),
        ]);

        try {
            $urlEnvio = "{$this->baseUrl}/v2/nfce?ref={$documento->numero_pedido}";
            Log::info("[FOCUS-API] URL de Destino: {$urlEnvio}");
            Log::debug("[FOCUS-API] Payload enviado:", $documento->payload_envio);

            $response = Http::withBasicAuth($this->token, '')
                ->timeout(30)
                ->post($urlEnvio, $documento->payload_envio);

            Log::info("[FOCUS-API] RESPOSTA RECEBIDA - Status HTTP: " . $response->status());
            Log::debug("[FOCUS-API] Corpo da Resposta:", [$response->body()]);

            // JÁ PROCESSADO
            if ($response->status() === 422) {
                $body = $response->json();
                if (($body['codigo'] ?? '') === 'already_processed') {
                    Log::info("Nota já processada. Consultando...");
                    $this->consultar($documento);
                    return;
                }
            }

            if ($response->failed()) {
                $erroMsg = Str::limit($response->body(), 1000);
                throw new \Exception("HTTP Focus [{$response->status()}]: {$erroMsg}");
            }

            $body = $response->json();
            Log::info("[FOCUS-API] Decode JSON com sucesso. Status Focus: " . ($body['status'] ?? 'N/A'));
            
            $this->registrarEvento($documento, 'retorno', $body['status'] ?? 'desconhecido', $body);
            
            Log::info("[FOCUS-API] Chamando lógica de tratamento do Retorno SEFAZ...");
            $this->processarLogicaSefaz($documento, $body);
            Log::info("[FOCUS-API] Lógica de Retorno SEFAZ concluída.");

        } catch (ConnectionException $e) {
            // ✅ SEM CONEXÃO - ATIVA CONTINGÊNCIA
            Log::warning("⚠️ ConnectionException capturada! Ativando contingência offline.", [
                'erro' => $e->getMessage(),
                'pedido' => $documento->numero_pedido,
            ]);

            $this->registrarEvento($documento, 'erro', 'sem_conexao', null, [
                'erro' => Str::limit($e->getMessage(), 2000),
            ]);

            // Ativa contingência
            app(ContingenciaOfflineService::class)->ativar($documento);
            
            // ✅ IMPORTANTE: Retorna sem relançar exceção
            return;

        } catch (Throwable $e) {
            // Outros erros
            Log::error("❌ Erro ao enviar: " . $e->getMessage());
            
            $erroCurto = Str::limit($e->getMessage(), 2000);

            $this->registrarEvento($documento, 'erro', 'erro_comunicacao', null, [
                'erro' => $erroCurto
            ]);

            $documento->update(['status' => 'erro_comunicacao']);
            
            // ✅ Relança para o Job marcar como falha
            throw $e;
        }
    }

    public function processarLogicaSefaz(FiscalDocument $documento, array $body): void
    {
        $codigoSefaz = $body['status_sefaz'] ?? null;
        $statusFocus = $body['status'] ?? null;
        
        $mensagemSefaz = Str::limit(
            $body['mensagem_sefaz'] ?? ($body['mensagem'] ?? 'Erro desconhecido'),
            5000
        );

        // AUTORIZADO
        if ($codigoSefaz === '100' || $statusFocus === 'autorizado') {
            Log::info("[FOCUS-SEFAZ] NOTA AUTORIZADA com sucesso.");
            $documento->update([
                'status' => 'autorizado',
                'em_contingencia' => false,
                'chave_acesso' => $body['chave_nfe'] ?? $documento->chave_acesso,
                'protocolo' => $body['protocolo'] ?? null,
                'numero_fiscal' => $body['numero'] ?? $documento->numero_fiscal,
                'serie' => $body['serie'] ?? $documento->serie,
                'qr_code_url' => $body['qrcode_url'] ?? $documento->qr_code_url,
                'payload_resposta' => $body,
                'xml_url' => $body['caminho_xml_nota_fiscal'] ?? null,
                'danfe_url' => $body['caminho_danfe'] ?? null,
                'mensagem_sefaz' => $mensagemSefaz,
            ]);
            
            Log::info("[FOCUS-SEFAZ] Disparando NotificarOdooJob por AUTORIZAÇÃO...");
            NotificarOdooJob::dispatch($documento->id);
            return;
        }

        // PROCESSANDO
        if (in_array($statusFocus, ['processando_autorizacao', 'processando'])) {
            Log::info("Nota em processamento. Status: {$statusFocus}");
            
            $documento->update([
                'status' => 'processando',
                'mensagem_sefaz' => 'Aguardando retorno da SEFAZ',
            ]);
            
            throw new \Exception("Nota ainda processando");
        }

        // CONTINGÊNCIA SEFAZ
        if (in_array($codigoSefaz, ['108', '109'])) {
            Log::info("[FOCUS-SEFAZ] Nota em CONTINGÊNCIA SEFAZ (status 108/109).");
            $documento->update([
                'status' => 'contingencia',
                'em_contingencia' => true,
                'contingencia_em' => $documento->contingencia_em ?? now(),
                'payload_resposta' => $body,
                'mensagem_sefaz' => $mensagemSefaz,
            ]);
            
            $this->registrarEvento($documento, 'contingencia', 'ativada_sefaz');
            Log::info("[FOCUS-SEFAZ] Disparando NotificarOdooJob por CONTINGÊNCIA...");
            NotificarOdooJob::dispatch($documento->id);
            return;
        }

        // REJEITADO
        $documento->update([
            'status' => 'rejeitado',
            'codigo_sefaz' => $codigoSefaz,
            'mensagem_sefaz' => $mensagemSefaz,
            'payload_resposta' => $body,
        ]);
        
        NotificarOdooJob::dispatch($documento->id);
    }

    private function registrarEvento(FiscalDocument $doc, string $tipo, string $status, ?array $payload = null, array $meta = [])
    {
        FiscalEvent::create([
            'fiscal_document_id' => $doc->id,
            'tipo' => $tipo,
            'status' => Str::limit($status, 100),
            'payload' => $payload,
            'meta' => $meta,
        ]);
    }

    public function consultar(FiscalDocument $documento): void
    {
        $response = Http::withBasicAuth($this->token, '')
            ->timeout(15)
            ->get("{$this->baseUrl}/v2/nfce/{$documento->numero_pedido}");

        if ($response->successful()) {
            $this->processarLogicaSefaz($documento, $response->json());
        }
    }

    // ... resto dos métodos (cancelar, inutilizar)



    public function cancelar(FiscalDocument $documento, string $justificativa): array
    {
        if (strlen($justificativa) < 15) {
            throw new \Exception("A justificativa deve ter pelo menos 15 caracteres.");
        }

        // Endpoint da Focus para cancelamento é DELETE
        // Mas enviamos um JSON no corpo com a justificativa
        $response = Http::withBasicAuth($this->token, '')
            ->withBody(json_encode(['justificativa' => $justificativa]), 'application/json')
            ->delete("{$this->baseUrl}/v2/nfce/{$documento->numero_pedido}");

        if ($response->failed()) {
             throw new \Exception("Erro ao cancelar na Focus: " . $response->body());
        }

        $body = $response->json();

        // Atualiza o banco local se deu certo
        if (($body['status'] ?? '') === 'cancelado') {
            $documento->update([
                'status' => 'cancelado',
                'meta' => array_merge($documento->meta ?? [], ['cancelamento' => $body])
            ]);
            
            // Opcional: Avisar o Odoo que foi cancelado
            // NotificarOdooJob::dispatch($documento->id); 
        }

        return $body;
    }

    /**
     * Inutiliza uma numeração que foi pulada (falha de sequência)
     */
    public function inutilizar(string $cnpj, string $serie, int $numeroInicial, int $numeroFinal, string $justificativa): array
    {
        $payload = [
            'cnpj' => $cnpj,
            'serie' => $serie,
            'numero_inicial' => $numeroInicial,
            'numero_final' => $numeroFinal,
            'justificativa' => $justificativa
        ];

        $response = Http::withBasicAuth($this->token, '')
            ->post("{$this->baseUrl}/v2/inutilizacao", $payload);

        if ($response->failed()) {
            throw new \Exception("Erro ao inutilizar: " . $response->body());
        }

        return $response->json();
    }
}


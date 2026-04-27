<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use App\Models\FiscalEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class NotificarOdooJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [5, 10, 20, 40, 80];
    public $timeout = 30;
    public $uniqueFor = 60;

    public function __construct(
        public int $documentoId
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->documentoId;
    }

    public function handle(): void
    {
        Log::info("📤 [NOTIFICAR-ODOO] INICIADO - Documento ID: {$this->documentoId}");
        
        try {
            $documento = FiscalDocument::findOrFail($this->documentoId);
            Log::info("📤 [NOTIFICAR-ODOO] Pedido: #{$documento->numero_pedido} | Status Atual: {$documento->status}");

            $payload = $this->montarPayload($documento);
            Log::info("📤 [NOTIFICAR-ODOO] Payload montado com status: " . ($payload['fiscal']['status'] ?? 'N/A'));
            
            $this->enviarParaOdoo($payload, $documento);
        } catch (\Exception $e) {
            Log::error("📤 [NOTIFICAR-ODOO] ERRO NO JOB:", [
                'documento_id' => $this->documentoId,
                'erro' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function montarPayload(FiscalDocument $documento): array
    {
        $base = [
            'documento_id' => $documento->numero_pedido,
            'pdv_id'       => $documento->pdv_id,
        ];

        // ✅ CORRIGIDO: Match mais completo
        $fiscalData = match ($documento->status) {
            'autorizado' => $documento->em_contingencia 
                ? $this->payloadContingenciaAutorizada($documento) // ← NOVO
                : $this->payloadSucesso($documento),
            
            'contingencia' => $this->payloadContingencia($documento),
            
            'rejeitado' => $this->payloadRejeitado($documento), // ← NOVO
            
            'erro_comunicacao', 'erro_fiscal' => $this->payloadErro($documento),
            
            default => $this->payloadProcessando($documento), // ← NOVO (para 'processando', etc)
        };

        $base['fiscal'] = $fiscalData;

        return $base;
    }

    /**
     * ✅ Gera QR Code em Base64 (PNG) usando chillerlan/php-qrcode - PHP puro, sem extensões
     */
    private function gerarQrCodeBase64(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        try {
            $options = new QROptions([
                'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
                'scale'        => 10,
                'imageBase64'  => false,
            ]);

            $qrcode = new QRCode($options);
            $imageData = $qrcode->render($url);
            $base64 = base64_encode($imageData);
            
            Log::info("✅ [QR-CODE] Gerado com sucesso: " . strlen($base64) . " caracteres");
            
            return $base64;
            
        } catch (\Exception $e) {
            Log::error("❌ [QR-CODE] Erro ao gerar: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ Nota autorizada SEM contingência (fluxo normal)
     */
    private function payloadSucesso(FiscalDocument $documento): array
    {
        $qrCodeB64 = $this->gerarQrCodeBase64($documento->qr_code_url);

        return [
            'status'            => 'autorizado',
            'mensagem'          => $documento->mensagem_sefaz ?? 'Autorizado o uso da NFC-e',
            'chave_nfe'         => $documento->chave_acesso,
            'protocolo'         => $documento->protocolo,
            'numero_nota'       => $documento->numero_fiscal,
            'serie'             => $documento->serie ?? '1',
            'qrcode_url'        => $documento->qr_code_url,
            'qrcode_b64'        => $qrCodeB64,
            'url_consulta'      => $documento->qr_code_url,
            'xml_url'           => $documento->xml_url,
            'danfe_url'         => $documento->danfe_url,
            'is_contingencia'   => false,
            'modo_emissao'      => 'normal',
        ];
    }

    /**
     * ✅ NOVO: Nota que foi emitida em contingência mas JÁ FOI AUTORIZADA pela SEFAZ
     * (Sincronização bem-sucedida)
     */
    private function payloadContingenciaAutorizada(FiscalDocument $documento): array
    {
        $qrCodeB64 = $this->gerarQrCodeBase64($documento->qr_code_url);

        return [
            'status'            => 'autorizado',
            'mensagem'          => 'NFC-e autorizada (originalmente emitida em contingência)',
            'chave_nfe'         => $documento->chave_acesso,
            'protocolo'         => $documento->protocolo,
            'numero_nota'       => $documento->numero_fiscal,
            'serie'             => $documento->serie,
            'qrcode_url'        => $documento->qr_code_url,
            'qrcode_b64'        => $qrCodeB64,
            'url_consulta'      => $documento->qr_code_url,
            'xml_url'           => $documento->xml_url,
            'danfe_url'         => $documento->danfe_url,
            
            // ✅ Informa que ERA contingência mas agora está OK
            'is_contingencia'   => false,
            'foi_contingencia'  => true, // ← Flag extra para o Odoo saber o histórico
            'modo_emissao'      => 'contingencia_sincronizada',
        ];
    }

    /**
     * ✅ Nota em contingência AGUARDANDO sincronização
     */
    private function payloadContingencia(FiscalDocument $documento): array
    {
        $qrCodeB64 = $this->gerarQrCodeBase64($documento->qr_code_url);

        return [
            // ✅ Mandamos 'autorizado' para o Odoo liberar a impressão
            'status'            => 'autorizado', 
            'mensagem'          => 'NFC-e emitida em CONTINGÊNCIA OFFLINE (Aguardando sincronização)',
            'chave_nfe'         => $documento->chave_acesso,
            'protocolo'         => null, // Contingência offline não tem protocolo ainda
            'numero_nota'       => $documento->numero_fiscal,
            'serie'             => $documento->serie,
            'qrcode_url'        => $documento->qr_code_url,
            'qrcode_b64'        => $qrCodeB64,
            'url_consulta'      => $documento->qr_code_url,
            'xml_url'           => null, // Ainda não tem XML da Focus
            'danfe_url'         => null,
            
            // ✅ Flags importantes
            'is_contingencia'   => true,
            'modo_emissao'      => 'offline',
        ];
    }

    /**
     * ✅ NOVO: Nota rejeitada pela SEFAZ
     */
    private function payloadRejeitado(FiscalDocument $documento): array
    {
        return [
            'status'            => 'rejeitado',
            'mensagem'          => $documento->mensagem_sefaz ?? 'Rejeitada pela SEFAZ',
            'codigo_erro_sefaz' => $documento->codigo_sefaz,
            'chave_nfe'         => $documento->chave_acesso,
            'numero_nota'       => $documento->numero_fiscal,
            'serie'             => $documento->serie,
            'qrcode_b64'        => null,
            'is_contingencia'   => false,
        ];
    }

    /**
     * ✅ Erros de comunicação ou erros fiscais
     */
    private function payloadErro(FiscalDocument $documento): array
    {
        return [
            'status'            => 'erro',
            'mensagem'          => $documento->mensagem_sefaz ?? 'Erro ao processar NFC-e',
            'codigo_erro_sefaz' => $documento->codigo_sefaz,
            'chave_nfe'         => $documento->chave_acesso,
            'qrcode_b64'        => null,
            'is_contingencia'   => false,
        ];
    }

    /**
     * ✅ NOVO: Status intermediário (processando, recebido, etc)
     */
    private function payloadProcessando(FiscalDocument $documento): array
    {
        return [
            'status'            => 'processando',
            'mensagem'          => 'NFC-e em processamento...',
            'chave_nfe'         => $documento->chave_acesso,
            'numero_nota'       => $documento->numero_fiscal,
            'qrcode_b64'        => null,
            'is_contingencia'   => $documento->em_contingencia,
        ];
    }

    /**
     * ✅ Envia para o Odoo
     */
    private function enviarParaOdoo(array $payload, FiscalDocument $documento): void
    {
        $url = config('services.odoo.url');

        if (empty($url)) {
            Log::warning("⚠️ URL do Odoo não configurada. Pulando notificação.");
            return;
        }

        try {
            Log::info("📤 [NOTIFICAR-ODOO] Tentando POST em: {$url}");
            Log::debug("📤 [NOTIFICAR-ODOO] Payload completo:", $payload);

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);

            Log::info("📤 [NOTIFICAR-ODOO] RESPOSTA ODOO - Status HTTP: " . $response->status());
            Log::debug("📤 [NOTIFICAR-ODOO] Corpo da Resposta:", [$response->body()]);

            if ($response->failed()) {
                $errorBody = $response->body();
                Log::error("❌ Falha ao notificar Odoo (HTTP {$response->status()})", [
                    'pedido' => $documento->numero_pedido,
                    'status_code' => $response->status(),
                    'body' => substr($errorBody, 0, 500),
                ]);
                
                throw new \Exception("Odoo retornou erro HTTP {$response->status()}");
            }

            $responseData = $response->json();
            
            Log::info("✅ Odoo notificado com sucesso!", [
                'pedido' => $documento->numero_pedido,
                'status_enviado' => $payload['fiscal']['status'],
                'resposta_odoo' => $responseData,
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Erro ao notificar Odoo: " . $e->getMessage(), [
                'pedido' => $documento->numero_pedido,
            ]);
            
            // ✅ Relança para retentar (Laravel vai usar o backoff)
            throw $e; 
        }
    }

    /**
     * ✅ NOVO: Hook quando o job falha definitivamente
     */
    public function failed(Throwable $exception): void
    {
        Log::error("❌ NotificarOdooJob FALHOU definitivamente", [
            'documento_id' => $this->documentoId,
            'erro' => $exception->getMessage(),
            'tentativas' => $this->tries,
        ]);

        // Opcional: Registrar evento de falha
        try {
            $documento = FiscalDocument::find($this->documentoId);
            
            if ($documento) {
                FiscalEvent::create([
                    'fiscal_document_id' => $documento->id,
                    'tipo'               => 'erro',
                    'status'             => 'falha_notificacao_odoo',
                    'meta'               => [
                        'erro'       => substr($exception->getMessage(), 0, 2000),
                        'tentativas' => $this->tries,
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Erro ao registrar falha: {$e->getMessage()}");
        }
    }
}
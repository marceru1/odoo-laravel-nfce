<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\FiscalEvent;
use App\Jobs\NotificarOdooJob;
use App\Jobs\ReenviarContingenciaJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ContingenciaOfflineService
{
    /**
     * Ativa o modo offline quando não há conexão com a Focus/SEFAZ.
     * Gera chave de acesso local e libera o PDV imediatamente.
     */
    public function ativar(FiscalDocument $documento): void
    {
        try {
            Log::warning("🛠️ CONTINGÊNCIA OFFLINE ativada para Pedido #{$documento->numero_pedido}");

            // ⚠️ A TRANSAÇÃO AGORA ENVOLVE TUDO: DA LEITURA AO SALVAMENTO
            DB::transaction(function () use ($documento) {
                
                // 1. Configurações Fiscais
                $ambiente = config('services.focus.env', 'homologacao');
                $uf = config('services.fiscal.uf', '13'); // Amazonas
                $modelo = '65';
                $tipoEmissao = '9';
                
                $cnpj = preg_replace('/\D/', '', $documento->payload_envio['cnpj_emitente'] ?? '');
                
                if (strlen($cnpj) !== 14) {
                    throw new \Exception("CNPJ do emitente inválido: {$cnpj}");
                }

                // -------------------------------------------------------
                // Série de contingência por PDV:
                //   Dev  (APP_ENV=local): 600 (fixo)
                //   Prod               : 600 + pdv_id  (PDV1=601, PDV2=602…)
                // Cada caixa tem sua própria série → sem conflito de numeração.
                // -------------------------------------------------------
                if (app()->environment('local')) {
                    $serie = '600';
                } else {
                    $pdvId = (int) ($documento->pdv_id ?? 1);
                    $serie = (string) (600 + $pdvId);
                }
                
                // -------------------------------------------------------
                // DADOS DA CONTINGÊNCIA (Tipo B vs Tipo A)
                // -------------------------------------------------------
                $contingenciaPayloadStr = $documento->payload_envio['contingencia']['payload'] ?? null;
                $contingenciaData = $contingenciaPayloadStr ? json_decode($contingenciaPayloadStr, true) : null;
                
                $dataEmissao = now();

                if ($contingenciaData && isset($contingenciaData['numero'], $contingenciaData['codigoUnico'])) {
                    // TIPO B (PDV Offline) - O Odoo já gerou a numeração
                    $numero = (int) $contingenciaData['numero'];
                    $serie = (string) ($contingenciaData['serie'] ?? $serie);
                    $codigoUnico = (int) $contingenciaData['codigoUnico'];
                    
                    if (isset($contingenciaData['dataEmissao'])) {
                        try {
                            $dataEmissao = \Carbon\Carbon::parse($contingenciaData['dataEmissao']);
                        } catch (\Exception $e) {
                            // Ignora e usa now()
                        }
                    }

                    Log::info("CONTINGÊNCIA TIPO B (PDV Offline): Usando dados do Odoo.", [
                        'numero' => $numero,
                        'serie' => $serie,
                        'codigoUnico' => $codigoUnico
                    ]);
                } else {
                    // TIPO A (SEFAZ Offline) ou Fallback - Middleware gera a numeração
                    Log::warning("CONTINGÊNCIA TIPO A / Fallback: Gerando número e cNF localmente para série {$serie}.");

                    // Pega o registro com maior número, forçando a leitura como INT
                    $ultimoRegistro = FiscalDocument::where('serie', $serie)
                        ->lockForUpdate() // Trava a tabela para outros caixas
                        ->orderByRaw('CAST(numero_fiscal AS UNSIGNED) DESC') // Lê '10' como maior que '9'
                        ->first();

                    $numero = $ultimoRegistro ? ((int) $ultimoRegistro->numero_fiscal) + 1 : 1;
                    $codigoUnico = random_int(10000000, 99999999); 
                    
                    Log::debug("Próximo número garantido para série {$serie}: {$numero}");
                }
                
                // 4. Geração da Chave
                $chaveAcesso = $this->gerarChaveAcesso(
                    $uf, $dataEmissao, $cnpj, $modelo, $serie, $numero, $tipoEmissao, $codigoUnico
                );

                // 5. URL (Odoo POS JS já gera o QR Code correto com CSC, aqui geramos a URL básica para salvar)
                $qrCodeUrl = $this->gerarQrCodeUrl($chaveAcesso, $ambiente);

                // 6. Dados Offline
                $dadosOffline = [
                    'numero' => $numero,
                    'serie' => $serie,
                    'codigo_unico' => $codigoUnico,
                    'data_emissao' => $dataEmissao->toIso8601String(),
                    'chave_acesso' => $chaveAcesso,
                    'tipo_emissao' => (int) $tipoEmissao,
                    'uf' => $uf,
                    'modelo' => $modelo,
                    'ambiente' => $ambiente,
                    'payload_original' => $documento->payload_envio,
                ];

                // 7. 🚀 O UPDATE ACONTECE DENTRO DA TRANSAÇÃO (Segura o Lock!)
                $documento->update([
                    'status' => 'contingencia',
                    'em_contingencia' => true,
                    'contingencia_em' => $dataEmissao,
                    'chave_acesso' => $chaveAcesso,
                    'numero_fiscal' => $numero,
                    'serie' => $serie,
                    'qr_code_url' => $qrCodeUrl,
                    'mensagem_sefaz' => 'NFC-e emitida em CONTINGÊNCIA OFFLINE - Aguardando transmissão',
                    'xml_offline' => json_encode($dadosOffline),
                ]);

                // 8. Evento
                $this->registrarEvento($documento, 'contingencia', 'gerada_offline', null, [
                    'chave' => $chaveAcesso,
                    'serie' => $serie,
                    'numero' => $numero,
                    'motivo' => 'sem_conexao_internet',
                ]);

            }); // <--- FIM DA TRANSAÇÃO: Aqui o banco salva e libera o lock para o próximo caixa.

            // 9 e 10. Jobs são disparados FORA da transação para evitar atrasos na fila
            NotificarOdooJob::dispatch($documento->id);

            ReenviarContingenciaJob::dispatch($documento)
                ->delay(now()->addSeconds(30))
                ->onQueue('contingencia');

            Log::info("✅ Contingência offline gerada com sucesso.");

        } catch (\Throwable $e) {
            Log::error("❌ ERRO CRÍTICO ao gerar contingência offline: {$e->getMessage()}", [
                'documento_id' => $documento->id,
            ]);

            $documento->update([
                'status' => 'erro_fiscal',
                'mensagem_sefaz' => 'Falha ao gerar contingência: ' . Str::limit($e->getMessage(), 500),
            ]);

            $this->registrarEvento($documento, 'erro', 'falha_contingencia', null, [
                'erro' => Str::limit($e->getMessage(), 2000),
            ]);

            throw $e;
        }
    }

    /**
     * Gera a chave de acesso de 44 dígitos.
     */
    private function gerarChaveAcesso(
        string $uf,
        \Carbon\Carbon $dataEmissao,
        string $cnpj,
        string $modelo,
        string $serie,
        int $numero,
        string $tipoEmissao,
        int $codigoUnico
    ): string {
        // Estrutura: cUF(2)+AAMM(4)+CNPJ(14)+mod(2)+serie(3)+nNF(9)+tpEmis(1)+cNF(8) = 43 chars + cDV(1) = 44
        // Referência: NT 2013.005 / SEFAZ item 8.2
        $chaveBase = sprintf(
            '%02d%02d%02d%s%02d%03d%09d%d%08d',
            (int) $uf,
            (int) $dataEmissao->format('y'), // Ano (2 dígitos)
            (int) $dataEmissao->format('m'), // Mês
            $cnpj,
            (int) $modelo,
            (int) $serie,
            $numero,        // 9 dígitos (nNF) — spec SEFAZ exige 9, não 8
            (int) $tipoEmissao,
            $codigoUnico    // 8 dígitos (cNF = codigo_aleatorio)
        );

        // Valida tamanho (deve ter 43 caracteres)
        if (strlen($chaveBase) !== 43) {
            throw new \Exception("Chave base inválida (esperado 43, obtido " . strlen($chaveBase) . "): {$chaveBase}");
        }

        // Calcula dígito verificador
        $dv = $this->calcularDigitoVerificador($chaveBase);
        
        $chaveCompleta = $chaveBase . $dv;

        Log::debug("Chave de acesso gerada: {$chaveCompleta}");

        return $chaveCompleta;
    }

    /**
     * Calcula o Dígito Verificador usando Módulo 11.
     */
    private function calcularDigitoVerificador(string $chave43): int
    {
        $multiplicadores = [2,3,4,5,6,7,8,9,2,3,4,5,6,7,8,9,2,3,4,5,6,7,8,9,2,3,4,5,6,7,8,9,2,3,4,5,6,7,8,9,2,3,4];
        
        $soma = 0;
        for ($i = 0; $i < 43; $i++) {
            $soma += (int) $chave43[$i] * $multiplicadores[$i];
        }

        $resto = $soma % 11;
        $dv = 11 - $resto;

        // Se DV >= 10, usa 0
        return ($dv >= 10) ? 0 : $dv;
    }

    /**
     * Gera código numérico único de 8 dígitos (cNF).
     */
    private function gerarCodigoUnico(): int
    {
        return random_int(10000000, 99999999);
    }

    /**
     * Gera URL do QR Code para consulta.
     */
    private function gerarQrCodeUrl(string $chaveAcesso, string $ambiente): string
    {
        // URLs da SEFAZ da respectiva UF (buscado via .env)
        $baseUrl = ($ambiente === 'producao')
            ? config('services.fiscal.qrcode_url_producao')
            : config('services.fiscal.qrcode_url_homologacao');

        // Formato básico (sem CSC pois é contingência)
        // Em produção real, você precisaria incluir o hash do CSC
        return "{$baseUrl}?p={$chaveAcesso}|2|2";
    }

    /**
     * Registers a fiscal event in the audit log.
     */
    private function registrarEvento(
        FiscalDocument $doc,
        string $tipo,
        string $status,
        ?array $payload = null,
        array $meta = []
    ): void {
        FiscalEvent::create([
            'fiscal_document_id' => $doc->id,
            'tipo' => $tipo,
            'status' => Str::limit($status, 100),
            'payload' => $payload,
            'meta' => $meta,
        ]);
    }

    /**
     * Verifica se um documento pode ser transmitido.
     */
    public function podeTransmitir(FiscalDocument $documento): bool
    {
        return $documento->status === 'contingencia' 
            && $documento->em_contingencia 
            && !empty($documento->xml_offline);
    }
}
<?php

namespace App\Services;

use App\Jobs\EnvioJob;
use App\Jobs\ReenviarContingenciaJob;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Log;

class OdooVendaService
{
    /**
     * Processa a venda recebida do Odoo, realiza a tradução de dados
     * para o padrão da Sefaz (Focus NFe) e agenda a emissão no sistema.
     *
     * @param array $dadosVenda Payload recebido do sistema Odoo
     * @return void
     * @throws \Exception
     */
    public function processar(array $dadosVenda): void
    {
        Log::info('[SERVICE-ODOO] Iniciando tratamento dos dados para o padrão da API Focus NFe');

        try {
            $confirma = $dadosVenda['confirmacao_venda'] ?? false;
            $contingencia = $dadosVenda['contingencia'] ?? [];
            
            $dadosTratados = $this->tratamento($dadosVenda);
            
            Log::info('[SERVICE-ODOO] Dados traduzidos com sucesso. Criando registro local (FiscalDocument).');
            
            $documento = FiscalDocument::create([
                'origem' => 'odoo',
                'tipo'   => 'nfce',
                'status' => 'recebido',
                'payload_envio'  => $dadosTratados,
                'numero_pedido'  => $dadosVenda['venda']['numero_ordem'] ?? null,
                'pdv_id'         => $dadosVenda['venda']['numero_caixa'] ?? null,
                'tentativas'     => 0,
            ]);

            // === FLUXO CONTINGÊNCIA OFFLINE (internet voltou) ===
            if (!empty($contingencia['ativa']) && !empty($contingencia['payload'])) {
                Log::info("[SERVICE-ODOO] Venda em CONTINGÊNCIA OFFLINE detectada. Restaurando dados gerados pelo PDV.");
                
                $dadosOffline = json_decode($contingencia['payload'], true);
                
                if (is_array($dadosOffline)) {
                    $documento->update([
                        'status'          => 'contingencia',
                        'em_contingencia' => true,
                        'contingencia_em' => $dadosOffline['data_emissao'] ?? now(),
                        'chave_acesso'    => $dadosOffline['chave_acesso'] ?? null,
                        'numero_fiscal'   => $dadosOffline['numero'] ?? null,
                        'serie'           => $dadosOffline['serie'] ?? '600',
                        'xml_offline'     => $dadosOffline, // Array com numero/serie/codigo_unico/data_emissao
                        'mensagem_sefaz'  => 'NFC-e emitida em CONTINGÊNCIA OFFLINE pelo PDV — Aguardando transmissão',
                    ]);
                    
                    Log::info("[SERVICE-ODOO] xml_offline populado. Despachando ReenviarContingenciaJob (forma_emissao=offline).");
                    ReenviarContingenciaJob::dispatch($documento)->onQueue('contingencia');
                } else {
                    Log::error("[SERVICE-ODOO] payload da contingência inválido. Pulando reenvio offline.");
                }

            // === FLUXO NORMAL (online) ===
            } elseif ($confirma === true) {
                Log::info("[SERVICE-ODOO] Venda confirmada. Disparando envio para Documento ID: {$documento->id}");
                EnvioJob::dispatch($documento);
            } else {
                Log::warning("[SERVICE-ODOO] Venda registrada (ID: {$documento->id}), porém não confirmada no pdv (confirmacao_venda = false). Emissão em espera.");
            }
            
        } catch (\Exception $e) {
            Log::error("[SERVICE-ODOO] Erro no processamento da Venda Odoo:", [
                'mensagem' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Traduz o payload específico do Odoo para a estrutura exigida pela FocusNFe.
     * Aplica regras de formatação numérica e tributária.
     *
     * @param  array $dados Payload original da Venda Odoo
     * @return array  Payload estruturado para envio à API
     */
    private function tratamento(array $dados): array
    {
        $itensFocus = [];
        $cpfCliente = $dados['cliente']['cpf'] ?? null;

        // 1. Processamento da lista de produtos e regras tributárias base
        foreach ($dados['produtos'] as $index => $produto) {
            
            $valorDesconto = isset($produto['valor_desconto']) ? $produto['valor_desconto'] : 0.0;

            // Remove pontuações do NCM vindo do cadastro do Odoo
            $ncmPuro = $produto['codigo_ncm'];
            $ncmLimpo = preg_replace('/[^0-9]/', '', $ncmPuro);

            $itensFocus[] = [
                'numero_item'                  => $produto['numero_item'],
                'codigo_ncm'                   => $ncmLimpo ?? null,
                'quantidade_comercial'         => number_format((float) $produto['quantidade_comercial'], 2, '.', ''),
                'quantidade_tributavel'        => number_format((float) $produto['quantidade_tributavel'], 2, '.', ''),
                'cfop'                         => $produto['cfop'],
                'valor_unitario_comercial'     => number_format((float) $produto['valor_unitario_comercial'], 2, '.', ''),
                'valor_unitario_tributavel'    => number_format((float) $produto['valor_unitario_tributavel'], 2, '.', ''),
                'valor_desconto'               => number_format((float) $valorDesconto, 2, '.', ''),
                'descricao'                    => $produto['descricao'],
                'codigo_produto'               => $produto['codigo_produto'],
                'icms_origem'                  => $produto['icms_origem'] ?? '0',
                'icms_situacao_tributaria'     => $produto['icms_situacao_tributaria'],
                'pis_situacao_tributaria'      => $produto['pis_situacao_tributaria'] ?? '07',
                'cofins_situacao_tributaria'   => $produto['cofins_situacao_tributaria'] ?? '07',
                'unidade_comercial'            => 'UN',
                'unidade_tributavel'           => 'UN',
            ];
        }

        // 2. Processamento das formas de pagamento da transação
        $formasPagamento = [];
        if (!empty($dados['pagamentos'])) {
            foreach ($dados['pagamentos'] as $pagamento) {
                // O Odoo registra o troco como um valor de pagamento negativo. 
                // Ignoramos o lançamento negativo pois a Sefaz lida com troco implicitamente.
                if ($pagamento['valor'] < 0) {
                    continue;
                }

                $formasPagamento[] = [
                    "forma_pagamento" => $this->deParaFormaPagamento($pagamento['tipo']),
                    "valor_pagamento" => number_format((float)$pagamento['valor'], 2, '.', '')
                ];
            }
        }
        
        // Formas de pagamento são enviadas pelo Odoo, mas se todas zerarem na trava do negativo
        // Assume pagamento zerado em dinheiro como fallback para não quebrar contrato do XML
        if (empty($formasPagamento)) {
             $formasPagamento[] = ["forma_pagamento" => "01", "valor_pagamento" => 0.00];
        }

        // Recupera o CNPJ da filial recebido do Odoo. Fallback para .env caso não exista.
        $cnpjOdoo = isset($dados['fiscal']['cnpj_emitente']) ? preg_replace('/[^0-9]/', '', $dados['fiscal']['cnpj_emitente']) : null;
        $cnpjFinal = !empty($cnpjOdoo) ? $cnpjOdoo : config('services.fiscal.cnpj_emitente');

        // 3. Montagem do payload raiz da requisição fiscal
        $payload = [
            'cnpj_emitente' => $cnpjFinal,
            'data_emissao'  => now()->setTimezone(config('services.fiscal.timezone') ?: 'America/Manaus')->format('c'), // Fuso horário fiscal
            'indicador_inscricao_estadual_destinatario' => "9", // 9 = Não Contribuinte
            'modalidade_frete' => "9", // 9 = Sem Frete
            'local_destino' => "1", // 1 = Operação interna
            'presenca_comprador' => "1", // 1 = Operação presencial
            'natureza_operacao' => "VENDA AO CONSUMIDOR",
            'items' => $itensFocus,
            'formas_pagamento' => $formasPagamento,
        ];

        // Anexa o CPF apenas se fornecido limpo e válido do ERP.
        if ($cpfCliente) {
            $payload['cpf_destinatario'] = $cpfCliente;
        }

        return $payload;
    }

    /**
     * Maps the Odoo payment method name to official SEFAZ FPAG codes.
     */
    private function deParaFormaPagamento(string $tipoOdoo): string
    {
        $tipoOdoo = strtolower($tipoOdoo);
        
        if (str_contains($tipoOdoo, 'dinheiro')) return '01'; // Sefaz: Dinheiro
        if (str_contains($tipoOdoo, 'crédito')) return '03'; // Sefaz: Cartão de Crédito
        if (str_contains($tipoOdoo, 'débito')) return '04'; // Sefaz: Cartão de Débito
        if (str_contains($tipoOdoo, 'pix')) return '17'; // Sefaz: PIX Dinâmico/Estático
        
        return '99'; // Sefaz: Outros
    }
}
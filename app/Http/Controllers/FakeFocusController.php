<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FakeFocusController extends Controller
{
    public function emitir (Request $request) {
        
        Log::info('api FAKE recebeu o nfc-e' , $request->all());
        
        $refString = $request->input('ref', '0');
        
        // Extrai apenas números do pedido
        $numeroPedido = (int) preg_replace('/[^0-9]/', '', $refString);

        // DADOS DA FOTO OBTIDOS DO CONFIG
        $cnpjEmitente = config('services.fiscal.cnpj_emitente');
        $urlConsulta  = config('services.fiscal.url_consulta');
        
        // Gera uma chave dinâmica, usando o UF configurado e Ano 25
        $uf = config('services.fiscal.uf');
        $chaveNfe = "{$uf}2512" . $cnpjEmitente . "65001" . str_pad((string) $numeroPedido, 9, '0', STR_PAD_LEFT) . "123456781";

        // Usa a URL configurada como base
        $qrCodeUrl = config('services.fiscal.qrcode_url_homologacao') . "?p={$chaveNfe}|2|1|1|FE9F8563E6BBCA485D334D66A8A38CC9FCC4CBFA";

        // =================================================================
        // CENÁRIO 1: CONTINGÊNCIA OFFLINE (Múltiplos de 5 - ex: 5, 10, 15...)
        // =================================================================
        if ($numeroPedido > 0 && $numeroPedido % 5 === 0) {
            return response()->json([
                "cnpj_emitente" => $cnpjEmitente,
                "ref" => $refString,
                "status" => "autorizado", 
                "status_sefaz" => "100",
                "mensagem_sefaz" => "Autorizado o uso da NF-e",
                "chave_nfe" => $chaveNfe,
                "numero" => (string) $numeroPedido,
                "serie" => "1",
                
                // Caminhos simulados
                "caminho_xml_nota_fiscal" => "/arquivos/xml/teste-offline.xml",
                "caminho_danfe" => "/notas/teste-offline.html",
                
                // Dados visuais da nota
                "qrcode_url" => $qrCodeUrl,
                "url_consulta_nf" => $urlConsulta,
                
                // Contingência ativada
                "contingencia_offline" => true,
                "contingencia_offline_efetivada" => false
            ], 200);
        }

        // =================================================================
        // CENÁRIO 2: ERRO DE AUTORIZAÇÃO (Pares restantes - ex: 2, 4, 6...)
        // =================================================================
        if ($numeroPedido % 2 === 0) {
            return response()->json([
                "cnpj_emitente" => $cnpjEmitente,
                "ref" => $refString,
                "status" => "erro_autorizacao",
                "status_sefaz" => "591",
                "mensagem_sefaz" => "Rejeição: Duplicidade de NF-e [nRec:{$numeroPedido}]"
            ], 200); 
        }

        // =================================================================
        // CENÁRIO 3: SUCESSO PADRÃO (Ímpares restantes - ex: 1, 3, 7...)
        // =================================================================
        return response()->json([
            "cnpj_emitente" => $cnpjEmitente,
            "ref" => $refString,
            "status" => "autorizado",
            "status_sefaz" => "100",
            "mensagem_sefaz" => "Autorizado o uso da NF-e",
            
            // Aqui usamos a chave e protocolo parecidos com a foto
            "chave_nfe" => $chaveNfe, 
            "protocolo" => "113253469722453", // Protocolo fixo da foto para teste visual
            "numero" => (string) $numeroPedido,
            "serie" => "1",
            
            "caminho_xml_nota_fiscal" => "/arquivos/xml/teste.xml",
            "caminho_danfe" => "/notas/teste.html",
            
            // URL do QR Code e Consulta do AM
            "qrcode_url" => $qrCodeUrl,
            "url_consulta_nf" => $urlConsulta
        ], 200);
    }   
}
<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendaWebhookRequest;
use App\Services\OdooVendaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OdooWebhookController extends Controller
{
    /**
     * Receives and processes the webhook fired by Odoo when a sale is closed.
     * This is the main entry point for the Odoo ↔ Middleware integration.
     *
     * The payload is validated by {@see VendaWebhookRequest} before reaching here.
     */
    public function receberVenda(VendaWebhookRequest $request, OdooVendaService $service): JsonResponse
    {
        // validated() confirms required fields exist; all() passes the full payload
        // (including extra product fields like codigo_barras, valor_bruto, etc.)
        $idPedido   = $request->validated()['venda']['numero_ordem'];
        $dadosVenda = $request->all();

        Log::info("[WEBHOOK] Pedido {$idPedido} recebido. Iniciando processamento.");

        try {
            $service->processar($dadosVenda);

            Log::info("[WEBHOOK] Pedido {$idPedido} processado com sucesso.");

            return response()->json(['status' => 'sucesso', 'pedido' => $idPedido]);
        } catch (\Exception $e) {
            Log::error('[WEBHOOK] Falha ao processar pedido.', [
                'pedido' => $idPedido,
                'erro'   => $e->getMessage(),
            ]);

            return response()->json(['status' => 'erro', 'message' => $e->getMessage()], 500);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use App\Models\FiscalEvent;
use App\Services\FocusNfceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnvioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 60;

    public function __construct(
        public readonly FiscalDocument $documento,
    ) {}

    public function handle(FocusNfceService $service): void
    {
        Log::info("🚀 [ENVIO-JOB] INICIADO - Documento ID: {$this->documento->id} | Pedido: {$this->documento->numero_pedido}");

        try {
            Log::info("🚀 [ENVIO-JOB] Chamando FocusNfceService->enviar()...");
            $service->enviar($this->documento);
            Log::info("🚀 [ENVIO-JOB] FocusNfceService->enviar() concluído.");
        } catch (Throwable $e) {
            Log::error("❌ [ENVIO-JOB] ERRO DURANTE EXECUÇÃO DO JOB:", [
                'documento_id' => $this->documento->id,
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("❌ EnvioJob FALHOU", [
            'documento_id' => $this->documento->id,
            'erro' => $exception->getMessage(),
        ]);

        FiscalEvent::create([
            'fiscal_document_id' => $this->documento->id,
            'tipo' => 'erro',
            'status' => 'job_falhou',
            'meta' => ['erro' => substr($exception->getMessage(), 0, 2000)],
        ]);
    }
}
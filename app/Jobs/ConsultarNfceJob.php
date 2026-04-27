<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use App\Services\FocusNfceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queries the Focus NFe API for the current status of a fiscal document.
 * Dispatched when a document is stuck in 'processando' state.
 */
class ConsultarNfceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $documentoId
    ) {}

    public function handle(FocusNfceService $service): void
    {
        $doc = FiscalDocument::find($this->documentoId);

        if (! $doc) {
            Log::warning("[CONSULTAR-JOB] Documento ID {$this->documentoId} não encontrado. Abortando.");

            return;
        }

        $service->consultar($doc);
    }
}
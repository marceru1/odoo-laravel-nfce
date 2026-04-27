<?php

namespace App\Console\Commands;
use App\Services\FocusNfceService;
use Illuminate\Console\Command;
use App\Models\FiscalDocument;
class SincronizarNotasContingencia extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sincronizar-notas-contingencia';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
public function handle(FocusNfceService $service)
{
    $notasPendentes = FiscalDocument::where('status', 'contingencia_pendente')
        ->where('sincronizado_focus', false)
        ->get();

    foreach ($notasPendentes as $nota) {
        $this->info("Sincronizando nota {$nota->numero_pedido}...");
        $service->sincronizarContingencia($nota);
    }
}
}

<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocument;
use App\Services\FocusNfceService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PainelFiscalController extends Controller
{
    /**
     * Displays the fiscal monitoring dashboard with optional filters.
     */
    public function index(Request $request): mixed
    {
        $dataSelecionada = $request->query('dataSelecionada', now()->format('Y-m-d'));
        $statusFilter = $request->query('statusFilter', 'todos');
        $search = $request->query('search', '');

        $query = FiscalDocument::query()
            ->whereDate('created_at', $dataSelecionada)
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('numero_pedido', 'like', "%{$search}%")
                        ->orWhere('chave_acesso', 'like', "%{$search}%")
                        ->orWhere('numero_fiscal', 'like', "%{$search}%")
                        ->orWhere('pdv_id', 'like', "%{$search}%");
                });
            })
            ->when($statusFilter === 'pendente_sync', fn($q) =>
                $q->where('status', 'contingencia')
            )
            ->when($statusFilter === 'processando', fn($q) =>
                $q->where('status', 'processando')
            )
            ->when($statusFilter === 'erro', fn($q) =>
                $q->whereIn('status', ['erro','rejeitado','erro_comunicacao','erro_fiscal'])
            )
            ->when($statusFilter === 'autorizados', fn($q) =>
                $q->where('status', 'autorizado')
            )
            ->latest();

        $documents = $query->paginate(10)->withQueryString();
        
        $stats = $this->getStats($dataSelecionada);

        // Se for uma requisição AJAX para auto-refresh, podemos retornar apenas a tabela
        if ($request->ajax()) {
            return view('painel.partials.table', compact('documents'))->render();
        }

        return view('painel.index', compact('documents', 'stats', 'dataSelecionada', 'statusFilter', 'search'));
    }

    private function getStats(string $dataSelecionada): array
    {
        return [
            'total_dia' => FiscalDocument::whereDate('created_at', $dataSelecionada)->count(),
            
            'pendentes' => FiscalDocument::whereDate('created_at', $dataSelecionada)
                            ->where('status', 'contingencia')->count(),
            
            'erros' => FiscalDocument::whereDate('created_at', $dataSelecionada)
                        ->whereIn('status', ['erro','rejeitado','erro_fiscal'])->count(),
            
            'autorizados' => FiscalDocument::whereDate('created_at', $dataSelecionada)
                ->where('status', 'autorizado')
                ->count(),
        ];
    }

    public function cancelarNota(Request $request, FocusNfceService $focusService): mixed
    {
        $request->validate([
            'documento_id' => 'required|exists:fiscal_documents,id',
            'justificativa' => 'required|min:15|max:255'
        ]);

        try {
            $doc = FiscalDocument::findOrFail($request->documento_id);
            $focusService->cancelar($doc, $request->justificativa);

            return redirect()->back()->with('success', 'Nota cancelada com sucesso!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Erro ao cancelar a nota: ' . $e->getMessage());
        }
    }
}

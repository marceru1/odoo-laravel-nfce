<table class="w-full text-left">
    <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">Pedido</th>
            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">Status</th>
            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase">Data/Hora</th>
            <th class="px-6 py-4 text-xs font-bold text-gray-500 uppercase text-center">Ações</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
        @forelse ($documents as $doc)
            <tr class="hover:bg-gray-50 transition-colors">
                
                <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full bg-blue-50 flex items-center justify-center text-blue-500 border border-blue-100">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12h6m-6 4h6M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-gray-900">{{ $doc->numero_pedido }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                Operador: <span class="font-semibold text-gray-700">{{ $doc->pdv_id ?? 'N/A' }}</span>
                            </p>
                        </div>
                    </div>
                </td>

                <td class="px-6 py-4">
                    @php
                        $statusAtual = strtolower($doc->status);
                    @endphp

                    @if($statusAtual === 'autorizado')
                        <span class="px-2 py-1 text-xs font-bold bg-green-100 text-green-700 rounded border border-green-200">
                            AUTORIZADO
                        </span>
                    @elseif($statusAtual === 'processando')
                        <span class="px-2 py-1 text-xs font-bold bg-gray-100 text-gray-600 rounded border border-gray-200">
                            PROCESSANDO
                        </span>
                    @elseif(in_array($statusAtual, ['contingencia', 'contingencia_pendente', 'aguardando_transmissao']))
                        <span class="px-2 py-1 text-xs font-bold bg-gray-100 text-gray-600 rounded border border-gray-200">
                            CONTINGÊNCIA
                        </span>
                    @elseif(in_array($statusAtual, ['rejeitado', 'erro', 'erro_comunicacao', 'erro_fiscal']))
                        <span class="px-2 py-1 text-xs font-bold bg-red-100 text-red-700 rounded border border-red-200">
                            {{ strtoupper($statusAtual) }}
                        </span>
                    @elseif($statusAtual === 'cancelado')
                        <span class="px-2 py-1 text-xs font-bold bg-gray-200 text-gray-500 rounded border border-gray-300">
                            CANCELADO
                        </span>
                    @else
                        <span class="px-2 py-1 text-xs font-bold bg-gray-100 text-gray-600 rounded border border-gray-200">
                            {{ strtoupper($statusAtual) }}
                        </span>
                    @endif
                </td>

                <td class="px-6 py-4 text-sm text-gray-500">
                    {{ $doc->created_at->format('d/m/Y H:i:s') }}
                </td>

                <td class="px-6 py-4 text-center">
                    <div class="flex items-center justify-center gap-3">
                        @if(strtolower($doc->status) == 'autorizado')
                            <button type="button" onclick="abrirModalCancelar({{ $doc->id }})" 
                                    class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all"
                                    title="Cancelar Nota">
                                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </button>
                        @endif
                        
                        @if($doc->status === 'cancelado')
                            <span class="text-xs font-bold text-gray-400 cursor-not-allowed select-none">
                                CANCELADO
                            </span>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="px-6 py-12 text-center text-gray-400">
                    Nenhum documento encontrado para esta data.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="bg-gray-50 px-6 py-3 border-t border-gray-200 pagination-container">
    {{ $documents->links() }}
</div>

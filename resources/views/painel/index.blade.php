@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-gray-50 p-4 md:p-8 font-sans relative">
    
    @if(session('success'))
    <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
        <span class="block sm:inline">{{ session('success') }}</span>
    </div>
    @endif

    @if(session('error'))
    <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
        <span class="block sm:inline">{{ session('error') }}</span>
    </div>
    @endif

    <form id="filter-form" action="{{ route('painel.index') }}" method="GET">
        <div class="max-w-7xl mx-auto mb-6">
            <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-3 flex flex-col md:flex-row items-center justify-between gap-4">
                
                <div class="flex items-center gap-2 text-gray-500 text-sm font-medium">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span>Filtrar por Data</span>
                </div>

                <div class="flex items-center bg-gray-50 rounded-md border border-gray-200 p-1">
                    
                    <button type="button" onclick="mudarData(-1)"
                            class="p-1.5 text-gray-500 hover:text-blue-600 hover:bg-white rounded shadow-sm transition-all focus:outline-none">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>

                    <input type="date" name="dataSelecionada" id="dataSelecionada" value="{{ $dataSelecionada }}" onchange="submitForm()"
                           class="bg-transparent border-none focus:ring-0 text-gray-700 font-bold text-sm px-4 py-0 cursor-pointer">

                    <button type="button" onclick="mudarData(1)"
                            class="p-1.5 text-gray-500 hover:text-blue-600 hover:bg-white rounded shadow-sm transition-all focus:outline-none">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>

                <div class="w-full md:w-auto flex justify-end">
                    @if($dataSelecionada !== now()->format('Y-m-d'))
                        <button type="button" onclick="irParaHoje()" 
                                class="text-xs font-semibold text-blue-600 hover:text-blue-800 hover:underline transition">
                            Voltar para Hoje
                        </button>
                    @else
                        <span class="text-xs text-gray-400 font-medium cursor-default">
                            Hoje
                        </span>
                    @endif
                </div>

            </div>
        </div>

        <div class="max-w-7xl mx-auto mb-6 flex flex-col md:flex-row gap-4">
            <input type="hidden" name="statusFilter" id="statusFilter" value="{{ $statusFilter }}">
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="setStatusFilter('todos')" class="px-4 py-2 rounded text-sm font-medium border {{ $statusFilter === 'todos' ? 'bg-blue-50 border-blue-200 text-blue-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">Todos</button>
                <button type="button" onclick="setStatusFilter('autorizados')" class="px-4 py-2 rounded text-sm font-medium border {{ $statusFilter === 'autorizados' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">Autorizados</button>
                <button type="button" onclick="setStatusFilter('pendente_sync')" class="px-4 py-2 rounded text-sm font-medium border {{ $statusFilter === 'pendente_sync' ? 'bg-amber-50 border-amber-200 text-amber-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">Contingência</button>
                <button type="button" onclick="setStatusFilter('erro')" class="px-4 py-2 rounded text-sm font-medium border {{ $statusFilter === 'erro' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50' }}">Erros</button>
            </div>
            <div class="flex-1 flex justify-end">
                <input type="text" name="search" id="search" value="{{ $search }}" oninput="debounceSearch()" placeholder="Buscar pedido, pdv, chave..." class="w-full md:w-64 border-gray-300 rounded shadow-sm px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>
    </form>

    <div class="max-w-7xl mx-auto mb-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 ">
            <p class="text-xs font-bold text-gray-400 uppercase">Total na Data</p>
            <p class="text-3xl font-bold text-gray-900">{{ $stats['total_dia'] }}</p>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100  relative overflow-hidden">
           
            <p class="text-xs font-bold text-green-600 uppercase">Autorizadas na Data</p>
            <p class="text-3xl font-bold text-gray-900">{{ $stats['autorizados'] }}</p>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100  relative overflow-hidden">
            <div class="absolute right-0 top-0 h-full w-1 bg-amber-500"></div>
            <p class="text-xs font-bold text-amber-600 uppercase">Contingencia</p>
            <div class="flex items-center gap-2">
                <p class="text-3xl font-bold text-gray-900">{{ $stats['pendentes'] }}</p>

            </div>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
            <p class="text-xs font-bold text-red-500 uppercase">Erros / Rejeições</p>
            <p class="text-3xl font-bold text-red-600">{{ $stats['erros'] }}</p>
        </div>
    </div>

    <div class="max-w-7xl mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-6">
        <div class="overflow-x-auto" id="table-container">
            @include('painel.partials.table')
        </div>
    </div>

    <div class="flex justify-center mt-4">
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-gray-200 text-xs text-gray-400 shadow-sm">
            <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
            Atualizando em tempo real
        </span>
    </div>

    <!-- Modal Cancelar -->
    <div id="modal-cancelar" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 backdrop-blur-sm px-4 hidden">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden transform transition-all">
            <div class="bg-red-50 px-6 py-4 border-b border-red-100 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-red-900">Cancelar Nota Fiscal</h3>
            </div>
            <form action="{{ route('painel.cancelar') }}" method="POST">
                @csrf
                <input type="hidden" name="documento_id" id="cancel_documento_id">
                <div class="p-6">
                    <p class="text-gray-600 text-sm mb-4">Você tem certeza que deseja cancelar esta nota? Esta ação é irreversível.</p>
                    <label class="block text-xs font-bold text-gray-700 uppercase mb-2">Justificativa (Mín. 15 caracteres)</label>
                    <textarea name="justificativa" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-red-500 focus:ring-red-500 text-sm p-2" rows="3" placeholder="Ex: Erro na digitação dos valores..." required minlength="15"></textarea>
                </div>
                <div class="bg-gray-50 px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
                    <button type="button" onclick="fecharModal()" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm font-medium transition">Voltar</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-bold shadow-md hover:shadow-lg transition flex items-center gap-2">
                        Confirmar
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function submitForm() {
            document.getElementById('filter-form').submit();
        }

        function mudarData(dias) {
            let input = document.getElementById('dataSelecionada');
            let data = new Date(input.value);
            data.setDate(data.getDate() + dias + 1); // JS Date handles timezone offset weirdly sometimes, simple approach for YYYY-MM-DD
            
            // Simpler date math
            let current = input.value;
            if (!current) return;
            
            let d = new Date(current + 'T12:00:00'); // Use noon to avoid timezone issues
            d.setDate(d.getDate() + dias);
            input.value = d.toISOString().split('T')[0];
            
            submitForm();
        }

        function irParaHoje() {
            let input = document.getElementById('dataSelecionada');
            let d = new Date();
            let month = '' + (d.getMonth() + 1);
            let day = '' + d.getDate();
            let year = d.getFullYear();

            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;

            input.value = [year, month, day].join('-');
            submitForm();
        }

        function setStatusFilter(status) {
            document.getElementById('statusFilter').value = status;
            submitForm();
        }

        let searchTimeout;
        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                submitForm();
            }, 500);
        }

        function abrirModalCancelar(id) {
            document.getElementById('cancel_documento_id').value = id;
            document.getElementById('modal-cancelar').classList.remove('hidden');
        }

        function fecharModal() {
            document.getElementById('modal-cancelar').classList.add('hidden');
        }

        // Auto-refresh logic (fetch only the table)
        setInterval(() => {
            const formData = new FormData(document.getElementById('filter-form'));
            const params = new URLSearchParams(formData).toString();
            const url = `{{ route('painel.index') }}?${params}`;

            fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.text())
            .then(html => {
                document.getElementById('table-container').innerHTML = html;
            })
            .catch(err => console.error('Error refreshing table:', err));
        }, 5000);

        // Intercept pagination clicks to avoid full page reload
        document.addEventListener('click', function(e) {
            let target = e.target.closest('.pagination-container a');
            if(target) {
                e.preventDefault();
                let url = target.href;
                // update browser url
                window.history.pushState({}, '', url);
                
                // Fetch new table
                fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(response => response.text())
                .then(html => {
                    document.getElementById('table-container').innerHTML = html;
                });
            }
        });
    </script>
</div>
@endsection

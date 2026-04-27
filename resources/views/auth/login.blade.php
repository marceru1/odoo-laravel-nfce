<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Painel Fiscal</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4 font-sans">

    <div class="max-w-md w-full bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden">
        <div class="bg-gray-50 border-b border-gray-100 p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-100 rounded-full mb-4">
                <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-800">Acesso Restrito</h2>
            <p class="text-sm text-gray-500 mt-1">Painel Fiscal NFC-e</p>
        </div>

        <form action="{{ url('/login') }}" method="POST" class="p-6">
            @csrf
            
            @if($errors->any())
                <div class="mb-4 bg-red-50 border-l-4 border-red-500 p-3 rounded">
                    <p class="text-xs text-red-700 font-bold">{{ $errors->first() }}</p>
                </div>
            @endif

            <div class="mb-4">
                <label for="email" class="block text-xs font-bold text-gray-700 uppercase mb-2">E-mail</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                    class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm p-3">
            </div>

            <div class="mb-6">
                <label for="password" class="block text-xs font-bold text-gray-700 uppercase mb-2">Senha</label>
                <input type="password" name="password" id="password" required
                    class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm p-3">
            </div>

            <button type="submit" class="w-full bg-blue-600 text-white rounded-lg hover:bg-blue-700 py-3 font-bold shadow-md hover:shadow-lg transition-all">
                Entrar
            </button>
        </form>
        <div class="bg-gray-50 p-4 text-center border-t border-gray-100">
            <p class="text-xs text-gray-400">Ambiente protegido e monitorado.</p>
        </div>
    </div>

</body>
</html>

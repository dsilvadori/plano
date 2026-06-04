<x-guest-layout>
    <div class="mb-6">
        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Verificar e-mail</p>
        <h1 class="mt-3 text-2xl font-semibold text-white">Falta só confirmar seu e-mail.</h1>
        <p class="mt-2 text-sm text-slate-300">Enviamos um link de verificação para sua caixa de entrada. Abra o e-mail e confirme seu acesso antes de continuar.</p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm font-medium text-emerald-200">
            Um novo link de verificação foi enviado para o seu e-mail.
        </div>
    @endif

    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    Reenviar e-mail de verificação
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-slate-300 hover:text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-300">
                Sair
            </button>
        </form>
    </div>
</x-guest-layout>

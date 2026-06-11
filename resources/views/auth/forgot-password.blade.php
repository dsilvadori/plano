<x-guest-layout>
    <div class="mb-6">
        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Primeiro acesso</p>
        <h1 class="mt-3 text-2xl font-semibold text-white">Receba o link para criar sua senha.</h1>
        <p class="mt-2 text-sm text-slate-300">Informe o e-mail cadastrado na plataforma. Você receberá um link para criar ou redefinir sua senha.</p>
    </div>

    @if (session('status'))
        <x-auth-session-status class="mb-4" :status="session('status')" />
    @else
        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div>
                <x-input-label for="email" :value="'E-mail'" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-primary-button>
                    Enviar link de acesso
                </x-primary-button>
            </div>
        </form>
    @endif
</x-guest-layout>

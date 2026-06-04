<x-guest-layout>
    <div class="mb-6">
        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Recuperar acesso</p>
        <h1 class="mt-3 text-2xl font-semibold text-white">Vamos enviar um link para redefinir sua senha.</h1>
        <p class="mt-2 text-sm text-slate-300">Informe o e-mail usado no seu acesso ao plano. Você receberá um link para criar uma nova senha.</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div>
            <x-input-label for="email" :value="'E-mail'" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                Enviar link de redefinição
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>

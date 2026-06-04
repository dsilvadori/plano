<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="mb-6">
        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Acesso do aluno</p>
        <h1 class="mt-3 text-2xl font-semibold text-white">Entre para continuar seu ciclo.</h1>
        <p class="mt-2 text-sm text-slate-300">Seu plano ajustado à sua realidade fica aqui.</p>
    </div>

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div>
            <x-input-label for="email" :value="'E-mail'" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="'Senha'" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-white/20 bg-slate-950 text-amber-300 shadow-sm focus:ring-amber-300" name="remember">
                <span class="ms-2 text-sm text-slate-300">Manter conectado</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-slate-300 hover:text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-300" href="{{ route('password.request') }}">
                    Esqueceu sua senha?
                </a>
            @endif

            <x-primary-button class="ms-3">
                Entrar
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>

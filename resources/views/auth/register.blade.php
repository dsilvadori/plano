<x-guest-layout>
    <div class="mb-6">
        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Criar acesso</p>
        <h1 class="mt-3 text-2xl font-semibold text-white">Cadastre-se para começar seu ciclo.</h1>
        <p class="mt-2 text-sm text-slate-300">Preencha seus dados para entrar no Plano de Estudos da Vencendo Concursos.</p>
    </div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div>
            <x-input-label for="name" :value="'Nome'" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="email" :value="'E-mail'" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="'Senha'" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="'Confirmar senha'" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-slate-300 hover:text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-300" href="{{ route('login') }}">
                Já tem acesso?
            </a>

            <x-primary-button class="ms-4">
                Criar conta
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>

<x-guest-layout>
    <div class="mb-6">
        <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Confirmação</p>
        <h1 class="mt-3 text-2xl font-semibold text-white">Confirme sua senha para continuar.</h1>
        <p class="mt-2 text-sm text-slate-300">Esta é uma área protegida. Antes de avançar, valide sua senha atual.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div>
            <x-input-label for="password" :value="'Senha'" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex justify-end mt-4">
            <x-primary-button>
                Confirmar
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>

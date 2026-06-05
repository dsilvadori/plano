@php
    $navigationPlans = Auth::user()?->isStudent()
        ? Auth::user()->studyPlans()->with('course')->where('status', 'active')->latest()->get()
        : collect();
    $currentStudyPlan = request()->route('studyPlan');
@endphp

<nav x-data="{ open: false }">
    <div class="border-b border-white/10 bg-slate-950/70 backdrop-blur lg:hidden">
        <div class="flex items-center justify-between px-4 py-4">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                <x-application-logo class="h-10 w-auto object-contain" />
                <div>
                    <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Plano VC</p>
                    <p class="text-sm font-semibold text-slate-100">Seu ciclo de estudo</p>
                </div>
            </a>

            <button @click="open = !open" class="rounded-2xl border border-white/10 bg-white/5 p-3 text-slate-200">
                <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                    <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    <aside class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-72 lg:flex-col lg:border-r lg:border-white/10 lg:bg-slate-950/70 lg:backdrop-blur">
        <div class="flex items-center gap-4 px-6 py-8">
            <x-application-logo class="h-14 w-auto object-contain" />
            <div>
                <p class="text-xs uppercase tracking-[0.35em] text-slate-400">Vencendo Concursos</p>
                <p class="text-lg font-semibold text-slate-50">Plano de Estudos</p>
            </div>
        </div>

        <div class="px-4">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-4">
                <p class="text-sm font-semibold text-slate-50">{{ Auth::user()->name }}</p>
                <p class="mt-1 text-sm text-slate-400">{{ Auth::user()->email }}</p>
                <p class="mt-3 text-xs uppercase tracking-[0.25em] text-amber-300">
                    {{ Auth::user()->isAdmin() ? 'Admin' : 'Aluno' }}
                </p>
            </div>
        </div>

        <div class="mt-8 flex-1 space-y-2 px-4">
            <a href="{{ route('dashboard') }}" class="nav-pill {{ request()->routeIs('dashboard') ? 'nav-pill-active' : '' }}">Início</a>
            @if (Auth::user()->isStudent())
                @if (session()->has('admin_preview_user_id'))
                    <a href="{{ route('admin.preview-test-student.exit') }}" class="nav-pill">Voltar ao admin</a>
                @endif
                <a href="{{ route('study-plans.create') }}" class="nav-pill {{ request()->routeIs('study-plans.create') ? 'nav-pill-active' : '' }}">Criar plano</a>
                @if ($navigationPlans->isNotEmpty())
                    <div class="pt-4">
                        <p class="px-4 text-xs uppercase tracking-[0.25em] text-slate-500">Seus planos</p>
                        <div class="mt-2 space-y-2">
                            @foreach ($navigationPlans as $plan)
                                <a href="{{ route('study-plans.show', $plan) }}"
                                   class="nav-pill text-sm {{ request()->routeIs('study-plans.show') && $currentStudyPlan?->id === $plan->id ? 'nav-pill-active' : '' }}">
                                    Plano - {{ $plan->course->name }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
            @if (Auth::user()->isAdmin())
                <a href="/admin" class="nav-pill">Painel admin</a>
            @endif
            <a href="{{ route('profile.edit') }}" class="nav-pill">Perfil</a>
        </div>

        <div class="px-4 pb-4">
            <button id="install-app-button" type="button" class="hidden w-full rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                Instalar aplicativo
            </button>
            <p id="ios-install-hint" class="hidden mt-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-300">
                No iPhone ou iPad, toque em compartilhar e depois em “Adicionar à Tela de Início”.
            </p>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="px-4 pb-6">
            @csrf
            <button type="submit" class="w-full rounded-2xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm font-semibold text-amber-200 transition hover:bg-amber-400/20">
                Sair da conta
            </button>
        </form>
    </aside>

        <div :class="{'block': open, 'hidden': ! open}" class="hidden border-b border-white/10 bg-slate-950/95 px-4 pb-4 lg:hidden">
        <div class="space-y-2">
            <a href="{{ route('dashboard') }}" class="nav-pill {{ request()->routeIs('dashboard') ? 'nav-pill-active' : '' }}">Início</a>
            @if (Auth::user()->isStudent())
                @if (session()->has('admin_preview_user_id'))
                    <a href="{{ route('admin.preview-test-student.exit') }}" class="nav-pill">Voltar ao admin</a>
                @endif
                <a href="{{ route('study-plans.create') }}" class="nav-pill {{ request()->routeIs('study-plans.create') ? 'nav-pill-active' : '' }}">Criar plano</a>
                @foreach ($navigationPlans as $plan)
                    <a href="{{ route('study-plans.show', $plan) }}"
                       class="nav-pill {{ request()->routeIs('study-plans.show') && $currentStudyPlan?->id === $plan->id ? 'nav-pill-active' : '' }}">
                        Plano - {{ $plan->course->name }}
                    </a>
                @endforeach
            @endif
            @if (Auth::user()->isAdmin())
                <a href="/admin" class="nav-pill">Painel admin</a>
            @endif
            <a href="{{ route('profile.edit') }}" class="nav-pill">Perfil</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-pill w-full text-left">Sair</button>
            </form>
        </div>
    </div>
</nav>

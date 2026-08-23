@php
    $impersonatorName = session(\App\Services\UserImpersonation::SESSION_NAME_KEY);
@endphp

<nav x-data="{ open: false }">
    <div class="border-b border-white/10 bg-slate-950/70 backdrop-blur lg:hidden">
        <div class="flex items-center justify-between px-4 py-4">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                <x-application-logo class="h-10 w-auto object-contain" />
                <div>
                    <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Plataforma VC</p>
                    <p class="text-sm font-semibold text-slate-100">Seu ciclo de estudo</p>
                </div>
            </a>

            <div class="flex items-center gap-2">
                <x-theme-toggle class="theme-toggle-icon-only" />

                <button @click="open = !open" class="rounded-2xl border border-white/10 bg-white/5 p-3 text-slate-200">
                    <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <aside class="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-72 lg:flex-col lg:border-r lg:border-white/10 lg:bg-slate-950/70 lg:backdrop-blur">
        <div class="flex items-center gap-4 px-6 py-8">
            <x-application-logo class="h-14 w-auto object-contain" />
            <div>
                <p class="text-xs uppercase tracking-[0.35em] text-slate-400">Vencendo Concursos</p>
                <p class="text-lg font-semibold text-slate-50">Plataforma Vencendo Concursos</p>
            </div>
        </div>

        <div class="sidebar-scroll min-h-0 flex-1 overflow-y-auto px-4 pb-6">
            <div>
                <div class="user-card rounded-3xl border border-white/10 bg-white/5 p-4">
                    <p class="text-sm font-semibold text-slate-50">{{ Auth::user()->name }}</p>
                    <p class="mt-1 text-sm text-slate-400">{{ Auth::user()->email }}</p>
                    <p class="mt-3 text-xs uppercase tracking-[0.25em] text-amber-300">
                        {{ Auth::user()->isAdmin() ? 'Admin' : 'Aluno' }}
                    </p>
                </div>

                @if ($impersonatorName)
                    <div class="mt-3 rounded-2xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                        <p class="font-semibold">Visualizando como aluno</p>
                        <p class="mt-1 text-xs text-amber-100/80">Admin: {{ $impersonatorName }}</p>
                        <form method="POST" action="{{ route('admin.impersonation.stop') }}" class="mt-3">
                            @csrf
                            <button type="submit" class="w-full rounded-xl border border-amber-200/30 bg-amber-200/10 px-3 py-2 text-sm font-semibold text-amber-50 transition hover:bg-amber-200/20">
                                Voltar ao admin
                            </button>
                        </form>
                    </div>
                @endif
            </div>

            <div class="mt-8 space-y-2">
                @if (Auth::user()->canAccessStudentArea())
                    <a href="{{ route('dashboard') }}" class="nav-pill {{ request()->routeIs('dashboard', 'courses.index', 'courses.show', 'courses.lessons.show') ? 'nav-pill-active' : '' }}">Início</a>
                    <a href="{{ route('courses.mine') }}" class="nav-pill {{ request()->routeIs('courses.mine') ? 'nav-pill-active' : '' }}">Meus cursos</a>
                    <a href="{{ route('study-plans.dashboard') }}" class="nav-pill {{ request()->routeIs('study-plans.dashboard') ? 'nav-pill-active' : '' }}">Plano de Estudos</a>
                    <a href="{{ route('questions.index') }}" class="nav-pill {{ request()->routeIs('questions.*') ? 'nav-pill-active' : '' }}">Banco de questões</a>
                @else
                    <a href="{{ route('dashboard') }}" class="nav-pill {{ request()->routeIs('dashboard') ? 'nav-pill-active' : '' }}">Início</a>
                @endif
                @if (Auth::user()->isAdmin())
                    <a href="/admin" class="nav-pill">Painel admin</a>
                @endif
                <a href="{{ route('profile.edit') }}" class="nav-pill">Perfil</a>
            </div>

            <div class="mt-6">
                <x-theme-toggle class="w-full" />

                <button data-install-app-button type="button" class="install-app-button w-full rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                    Instalar aplicativo
                </button>
                <p data-ios-install-hint class="hidden mt-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-300">
                    No iPhone ou iPad, toque em compartilhar e depois em “Adicionar à Tela de Início”.
                </p>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button type="submit" class="w-full rounded-2xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm font-semibold text-amber-200 transition hover:bg-amber-400/20">
                    Sair da conta
                </button>
            </form>
        </div>
    </aside>

    <div :class="{'block': open, 'hidden': ! open}" class="hidden border-b border-white/10 bg-slate-950/95 px-4 pb-4 lg:hidden">
        <div class="space-y-2">
            @if ($impersonatorName)
                <div class="rounded-2xl border border-amber-300/30 bg-amber-300/10 p-4 text-sm text-amber-100">
                    <p class="font-semibold">Visualizando como aluno</p>
                    <p class="mt-1 text-xs text-amber-100/80">Admin: {{ $impersonatorName }}</p>
                    <form method="POST" action="{{ route('admin.impersonation.stop') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="w-full rounded-xl border border-amber-200/30 bg-amber-200/10 px-3 py-2 text-sm font-semibold text-amber-50">
                            Voltar ao admin
                        </button>
                    </form>
                </div>
            @endif
            @if (Auth::user()->canAccessStudentArea())
                <a href="{{ route('dashboard') }}" class="nav-pill {{ request()->routeIs('dashboard', 'courses.index', 'courses.show', 'courses.lessons.show') ? 'nav-pill-active' : '' }}">Início</a>
                <a href="{{ route('courses.mine') }}" class="nav-pill {{ request()->routeIs('courses.mine') ? 'nav-pill-active' : '' }}">Meus cursos</a>
                <a href="{{ route('study-plans.dashboard') }}" class="nav-pill {{ request()->routeIs('study-plans.dashboard') ? 'nav-pill-active' : '' }}">Plano de Estudos</a>
                <a href="{{ route('questions.index') }}" class="nav-pill {{ request()->routeIs('questions.*') ? 'nav-pill-active' : '' }}">Banco de questões</a>
            @else
                <a href="{{ route('dashboard') }}" class="nav-pill {{ request()->routeIs('dashboard') ? 'nav-pill-active' : '' }}">Início</a>
            @endif
            @if (Auth::user()->isAdmin())
                <a href="/admin" class="nav-pill">Painel admin</a>
            @endif
            <x-theme-toggle class="w-full lg:hidden" />
            <button data-install-app-button type="button" class="install-app-button w-full rounded-2xl border border-sky-400/20 bg-sky-400/10 px-4 py-3 text-sm font-semibold text-sky-100">
                Instalar aplicativo
            </button>
            <p data-ios-install-hint class="hidden rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-slate-300">
                No iPhone ou iPad, toque em compartilhar e depois em “Adicionar à Tela de Início”.
            </p>
            <a href="{{ route('profile.edit') }}" class="nav-pill">Perfil</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nav-pill w-full text-left">Sair</button>
            </form>
        </div>
    </div>
</nav>

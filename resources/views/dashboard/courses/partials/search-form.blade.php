@php
    $searchInputId = $searchInputId ?? 'course-search-'.\Illuminate\Support\Str::random(6);
    $searchValue = $searchValue ?? (request()->routeIs('courses.search') ? (string) request()->query('q', '') : '');
@endphp

<form method="GET" action="{{ route('courses.search') }}" role="search" class="{{ $formClass ?? '' }}">
    <label for="{{ $searchInputId }}" class="sr-only">Buscar cursos e aulas</label>
    <div class="flex items-center gap-2 rounded-2xl border border-white/10 bg-slate-950 px-3 py-2 focus-within:border-sky-400/40">
        <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.47 9.77l3.13 3.13a.75.75 0 1 0 1.06-1.06l-3.13-3.13A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
        </svg>
        <input
            id="{{ $searchInputId }}"
            type="search"
            name="q"
            value="{{ $searchValue }}"
            minlength="2"
            maxlength="100"
            required
            autocomplete="off"
            placeholder="{{ $placeholder ?? 'Buscar cursos e aulas' }}"
            class="w-full min-w-0 border-0 bg-transparent p-0 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:ring-0"
        >
        @if ($showButton ?? false)
            <button type="submit" class="shrink-0 rounded-xl border border-sky-400/20 bg-sky-400/10 px-3 py-1.5 text-xs font-semibold text-sky-100 transition hover:bg-sky-400/20">
                Buscar
            </button>
        @endif
    </div>
</form>

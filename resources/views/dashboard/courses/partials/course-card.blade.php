@php
    $hasAccess = $hasAccess ?? false;
    $progress = $progress ?? null;
    $progressPercentage = (int) ($progress['percentage'] ?? 0);
    $thumbnail = $course->thumbnail_display_url;
@endphp

<article class="card-subtle flex h-full flex-col overflow-hidden p-0">
    <div class="relative aspect-video overflow-hidden rounded-t-2xl border-b border-white/10 bg-slate-950 p-3">
        <img src="{{ $thumbnail }}" alt="{{ $course->name }}" class="h-full w-full rounded-xl object-contain" loading="lazy" decoding="async">
        @unless ($hasAccess)
            <div class="absolute right-3 top-3 rounded-full border border-white/20 bg-slate-950/80 px-3 py-1 text-xs font-semibold text-slate-100 shadow-lg">
                Bloqueado
            </div>
        @endunless
    </div>

    <div class="flex flex-1 flex-col p-5">
        <div class="flex flex-wrap gap-2">
            @if ($course->sphere)
                <span class="rounded-full border border-sky-400/20 bg-sky-400/10 px-3 py-1 text-xs font-semibold text-sky-100">{{ $course->sphere->name }}</span>
            @endif
            @if ($course->educationLevel)
                <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-slate-200">{{ $course->educationLevel->name }}</span>
            @endif
        </div>

        <h3 class="mt-4 text-lg font-semibold text-white">{{ $course->name }}</h3>
        <p class="mt-2 line-clamp-3 text-sm text-slate-400">
            {{ $course->short_description ?: $course->description ?: 'Curso completo para organizar seu estudo em uma trilha clara.' }}
        </p>

        <div class="mt-4 flex flex-wrap gap-3 text-xs text-slate-400">
            <span>{{ $course->modules_count }} módulo(s)</span>
            <span>{{ $course->lessons_count }} aula(s)</span>
        </div>

        @if ($hasAccess && $progress)
            <div class="mt-4">
                <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-300">
                    <span>{{ $progress['completed'] }} de {{ $progress['total'] }} aula(s)</span>
                    <span>{{ $progressPercentage }}%</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-sky-400" style="width: {{ min(100, $progressPercentage) }}%"></div>
                </div>
            </div>
        @endif

        <div class="mt-auto pt-5">
            @if ($hasAccess)
                <a href="{{ route('courses.show', $course->slug) }}" class="inline-flex w-full justify-center rounded-2xl bg-amber-300 px-4 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-400/20">
                    {{ $progressPercentage > 0 ? 'Continuar curso' : 'Acessar curso' }}
                </a>
            @elseif ($course->checkout_url)
                <a href="{{ $course->checkout_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-full justify-center rounded-2xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm font-semibold text-amber-200">
                    Comprar acesso
                </a>
            @else
                <a href="{{ route('courses.show', $course->slug) }}" class="inline-flex w-full justify-center rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-200">
                    Ver detalhes
                </a>
            @endif
        </div>
    </div>
</article>

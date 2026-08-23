@php
    $courses = $courses ?? collect();
    $accessibleCourseIds = $accessibleCourseIds ?? collect();
    $courseProgress = $courseProgress ?? [];
    $activePlansByCourse = $activePlansByCourse ?? collect();
    $forceAccess = $forceAccess ?? false;
    $emptyMessage = $emptyMessage ?? 'Nenhum curso publicado no momento.';
@endphp

@if ($courses->isNotEmpty())
    <div x-data="lessonCarousel" class="course-carousel-shell mt-6">
        <div class="mb-3 flex items-center justify-between gap-3">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400 md:hidden">
                Arraste para ver os próximos cursos.
            </p>
        </div>

        <button type="button" @click="scroll(-1)" :disabled="atStart" aria-label="Cursos anteriores" class="course-carousel-arrow course-carousel-arrow-left">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.83 10l3.94 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd" />
            </svg>
        </button>
        <button type="button" @click="scroll(1)" :disabled="atEnd" aria-label="Próximos cursos" class="course-carousel-arrow course-carousel-arrow-right">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
            </svg>
        </button>

        <div x-ref="track" @scroll.debounce.100ms="update" class="course-carousel-track">
            @foreach ($courses as $course)
                <div data-carousel-item class="course-carousel-card flex">
                    @include('dashboard.courses.partials.course-card', [
                        'course' => $course,
                        'hasAccess' => $forceAccess || $accessibleCourseIds->contains($course->id),
                        'progress' => $courseProgress[$course->id] ?? null,
                        'activePlan' => $activePlansByCourse->get($course->id),
                    ])
                </div>
            @endforeach
        </div>

        <div class="mt-1 flex justify-center gap-2 md:hidden" aria-label="Navegação dos cursos">
            @foreach ($courses as $course)
                <button type="button" @click="goTo({{ $loop->index }})" class="h-2.5 rounded-full transition-all" :class="activeIndex === {{ $loop->index }} ? 'w-6 bg-sky-300' : 'w-2.5 bg-slate-600'" aria-label="Ir para curso {{ $loop->iteration }}"></button>
            @endforeach
        </div>
    </div>
@else
    <div class="mt-6 card-subtle">
        <p class="text-sm text-slate-300">{{ $emptyMessage }}</p>
    </div>
@endif

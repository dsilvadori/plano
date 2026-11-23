@php
    $noticeUser = auth()->user();
    $expiredCourses = collect();
    $subscriptionExpired = false;

    if ($noticeUser) {
        $subscriptionExpired = $noticeUser->subscriptionExpired();

        if ($noticeUser->isStudent()) {
            $expiredCourses = $noticeUser->expiredCourses();

            // Um curso vencido pode continuar liberado por outra compra (ex.: combo); esse não entra no aviso.
            if ($expiredCourses->isNotEmpty()) {
                $stillAvailable = $noticeUser->availableCoursesQuery()->pluck('courses.id');
                $expiredCourses = $expiredCourses->reject(fn ($course) => $stillAvailable->contains($course->id));
            }
        }
    }
@endphp

@if ($subscriptionExpired || $expiredCourses->isNotEmpty())
    <div class="mb-6 rounded-2xl border border-amber-400/20 bg-amber-400/10 px-5 py-4 text-sm text-amber-100" role="status">
        <p class="font-semibold">Acesso expirado</p>

        @if ($subscriptionExpired)
            <p class="mt-1">Sua assinatura expirou em {{ $noticeUser->access_expires_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}. Renove para voltar a acessar os cursos.</p>
        @endif

        @if ($expiredCourses->isNotEmpty())
            <p class="mt-1">O seu acesso a estes cursos terminou:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($expiredCourses as $course)
                    <li>{{ $course->name }}, em {{ \Illuminate\Support\Carbon::parse($course->pivot->expires_at)->timezone('America/Sao_Paulo')->format('d/m/Y') }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

<div
    x-data="{
        isBuilding: false,
        progress: 18,
        step: 0,
        timer: null,
        steps: [
            'Lendo sua disponibilidade semanal',
            'Organizando matéria básica e conhecimento específico',
            'Distribuindo revisões e questões no ciclo',
            'Validando a viabilidade até a prova',
            'Finalizando seu plano personalizado'
        ],
        startPlanProgress() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            this.isBuilding = true;
            this.progress = 18;
            this.step = 0;
            clearInterval(this.timer);
            this.timer = setInterval(() => {
                if (this.progress < 96) {
                    this.progress += this.progress < 60 ? 11 : 6;
                }

                if (this.step < this.steps.length - 1 && this.progress >= ((this.step + 1) * 20)) {
                    this.step++;
                }
            }, 700);
        },
        stopPlanProgress() {
            clearInterval(this.timer);
            this.progress = 100;
            setTimeout(() => {
                this.isBuilding = false;
            }, 500);
        }
    }"
>
    @if (session('status'))
        <div class="mb-6 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    @if ($courses->isEmpty())
        <div class="rounded-3xl border border-amber-400/20 bg-amber-400/10 p-6 text-amber-100">
            <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Curso ainda não vinculado</p>
            <h2 class="mt-3 text-xl font-semibold text-white">Seu acesso existe, mas ainda não encontramos um curso liberado para este aluno.</h2>
            <p class="mt-3 text-sm text-amber-100/90">Se sua compra acabou de ser aprovada, aguarde o processamento do webhook. Se o problema continuar, peça ao suporte para confirmar o vínculo do seu e-mail com o curso correto.</p>
        </div>
    @else

    <form action="{{ $studyPlan ? route('study-plans.update', $studyPlan) : route('study-plans.store') }}" method="POST" x-on:submit="startPlanProgress()" class="grid gap-6 lg:grid-cols-2">
        @csrf
        @if ($studyPlan)
            @method('PUT')
        @endif
        <div class="card-panel lg:col-span-2">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm uppercase tracking-[0.25em] text-amber-300">{{ $studyPlan ? 'Reconfiguração do ciclo' : 'Modelo oficial do ciclo' }}</p>
                    @if ($studyPlan)
                        <p class="mt-3 max-w-2xl text-sm text-slate-300">Você pode trocar curso, trilha, datas e disponibilidade. Nós reordenamos o plano mantendo a mesma URL para evitar links quebrados.</p>
                    @endif
                </div>
                @if ($studyPlan)
                    <a href="{{ route('study-plans.show', $studyPlan) }}" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-slate-100">
                        Voltar ao plano atual
                    </a>
                @endif
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div class="card-subtle">
                    <p class="text-sm font-semibold text-white">Segunda a sexta</p>
                    <p class="mt-2 text-sm text-slate-300">Blocos de matéria básica e matéria de conhecimento específico, com revisão e questões ficando para o final do dia.</p>
                </div>
                <div class="card-subtle">
                    <p class="text-sm font-semibold text-white">Sábado estratégico</p>
                    <p class="mt-2 text-sm text-slate-300">Revisão geral e resolução de questões para consolidar o que foi estudado durante a semana.</p>
                </div>
                <div class="card-subtle">
                    <p class="text-sm font-semibold text-white">Plano realista</p>
                    <p class="mt-2 text-sm text-slate-300">A intensidade muda a prioridade dos blocos extras, sem ultrapassar o limite de 60 minutos.</p>
                </div>
            </div>
        </div>

        <div class="card-subtle">
            <label class="stat-label">Curso</label>
            <select name="course_id" wire:model.live="course_id" class="mt-3 w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100">
                <option value="">Selecione</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}">{{ $course->name }}</option>
                @endforeach
            </select>
            @error('course_id') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>

        <div class="card-subtle">
            <label class="stat-label">Trilha</label>
            <select name="study_track_id" wire:model="study_track_id" class="mt-3 w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100">
                <option value="">Sem trilha específica</option>
                @foreach ($tracks as $track)
                    <option value="{{ $track->id }}">{{ $track->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="card-subtle">
            <label class="stat-label">Data de início</label>
            <input name="start_date" wire:model="start_date" type="date" min="{{ now()->toDateString() }}" class="mt-3 w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100">
            <p class="mt-2 text-xs text-slate-500">Escolha quando você realmente começa. Não permitimos datas passadas para o plano ficar fiel à sua rotina atual.</p>
            @error('start_date') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>

        <div class="card-subtle">
            <label class="stat-label">Data da prova <span class="text-slate-500 normal-case tracking-normal">(opcional)</span></label>
            <input name="exam_date" wire:model="exam_date" type="date" min="{{ $start_date ?: now()->toDateString() }}" class="mt-3 w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100">
            <p class="mt-2 text-xs text-slate-500">Se o edital ainda não saiu, deixe em branco. O sistema cria um ciclo contínuo e você pode ajustar depois.</p>
            @error('exam_date') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>

        <div class="card-subtle lg:col-span-2">
            <label class="stat-label">Dias disponíveis</label>
            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($dayLabels as $day => $label)
                    <label class="rounded-2xl border border-white/10 bg-slate-950/70 p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-100">{{ $label }}</span>
                            <input name="available_days[]" wire:model.live="available_days" value="{{ $day }}" type="checkbox" class="rounded border-white/20 bg-slate-950 text-amber-300">
                        </div>
                        <div class="mt-3">
                            <input
                                name="available_minutes_by_day[{{ $day }}]"
                                wire:model="available_minutes_by_day.{{ $day }}"
                                type="text"
                                inputmode="numeric"
                                value="{{ $available_minutes_by_day[$day] ?? '00:00' }}"
                                x-on:focus="if ($el.value === '00:00') { $el.select() }"
                                x-on:blur="
                                    let raw = $el.value.trim();
                                    if (raw === '') {
                                        $el.value = '00:00';
                                        $el.dispatchEvent(new Event('input', { bubbles: true }));
                                        return;
                                    }
                                    if (/^\d{1,2}$/.test(raw)) {
                                        $el.value = `${raw.padStart(2, '0')}:00`;
                                        $el.dispatchEvent(new Event('input', { bubbles: true }));
                                        return;
                                    }
                                    if (/^\d{1,2}:\d{2}$/.test(raw)) {
                                        const [hours, minutes] = raw.split(':');
                                        $el.value = `${hours.padStart(2, '0')}:${minutes}`;
                                        $el.dispatchEvent(new Event('input', { bubbles: true }));
                                        return;
                                    }
                                    $el.value = '00:00';
                                    $el.dispatchEvent(new Event('input', { bubbles: true }));
                                "
                                class="w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100"
                            >
                            <p class="mt-2 text-xs text-slate-500">O padrão é `00:00`. Você pode digitar só `2` e sair do campo para virar `02:00`.</p>
                            @error("available_minutes_by_day.$day") <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
                        </div>
                    </label>
                @endforeach
            </div>
            @error('available_days') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>

        <div class="card-subtle lg:col-span-2">
            <label class="stat-label">Intensidade</label>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                @foreach (['light' => 'Leve', 'balanced' => 'Equilibrado', 'intense' => 'Intenso'] as $value => $label)
                    <label class="rounded-2xl border border-white/10 bg-slate-950/70 p-4">
                        <input name="intensity" wire:model="intensity" type="radio" value="{{ $value }}" class="mr-2 text-amber-300">
                        <span class="font-medium text-slate-100">{{ $label }}</span>
                        <p class="mt-2 text-sm text-slate-400">
                            @if ($value === 'light')
                                Mais espaço para revisão e manutenção do ritmo.
                            @elseif ($value === 'intense')
                                Mais blocos extras de conhecimento específico e questões.
                            @else
                                Equilíbrio entre teoria, revisão e prática.
                            @endif
                        </p>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="lg:col-span-2 flex justify-end">
            <button type="submit" class="rounded-2xl bg-amber-300 px-6 py-4 text-sm font-semibold text-slate-950 disabled:cursor-not-allowed disabled:opacity-70">
                {{ $studyPlan ? 'Salvar e reorganizar plano' : 'Gerar plano' }}
            </button>
        </div>
    </form>
    @endif

    <div
        x-show="isBuilding"
        x-transition.opacity
        x-init="$watch('progress', value => { if (value >= 100) { stopPlanProgress() } }); $watch('isBuilding', value => { if (!value) { clearInterval(timer) } })"
        class="fixed inset-0 z-50 items-center justify-center bg-slate-950/85 p-6 backdrop-blur"
        style="display: none;"
    >
        <div class="w-full max-w-2xl rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top,_rgba(250,204,21,0.14),_transparent_24%),linear-gradient(180deg,_rgba(15,23,42,0.98),_rgba(2,6,23,0.98))] p-8 shadow-2xl shadow-black/50">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-[0.3em] text-amber-300">Montando seu plano</p>
                    <h3 class="mt-3 text-2xl font-semibold text-white">Estamos organizando seu ciclo de estudos.</h3>
                <p class="mt-3 max-w-xl text-sm text-slate-300">A ideia aqui é transformar sua rotina em um plano viável, equilibrado e claro até a prova. Quando a montagem terminar, a plataforma segura a transição por um instante para a experiência ficar suave.</p>
                </div>
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl border border-amber-400/20 bg-amber-400/10 text-lg font-semibold text-amber-200">
                    <span x-text="`${Math.min(progress, 99)}%`"></span>
                </div>
            </div>

            <div class="mt-8">
                <div class="h-4 overflow-hidden rounded-full bg-slate-800/90">
                    <div class="progress-glow h-full rounded-full bg-gradient-to-r from-amber-300 via-yellow-300 to-sky-400 transition-all duration-700" :style="`width: ${Math.min(progress, 99)}%`"></div>
                </div>
            </div>

            <div class="mt-8 grid gap-3">
                <template x-for="(message, index) in steps" :key="index">
                    <div class="flex items-center gap-3 rounded-2xl border px-4 py-3 transition"
                         :class="index <= step ? 'border-emerald-400/20 bg-emerald-400/10 text-emerald-100' : 'border-white/10 bg-white/5 text-slate-400'">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold"
                             :class="index < step ? 'bg-emerald-400/20 text-emerald-200' : index === step ? 'bg-amber-400/20 text-amber-200' : 'bg-white/5 text-slate-500'">
                            <span x-text="index < step ? '✓' : index + 1"></span>
                        </div>
                        <p class="text-sm font-medium" x-text="message"></p>
                    </div>
                </template>
            </div>

            <div class="mt-6 rounded-2xl border border-white/10 bg-slate-950/50 p-4">
                <p class="text-xs uppercase tracking-[0.25em] text-slate-500">Etapa atual</p>
                <p class="mt-2 text-sm text-slate-200" x-text="steps[step]"></p>
                <p class="mt-3 text-xs text-slate-500">Ao finalizar a criação, a plataforma aguarda alguns segundos antes de abrir o plano pronto.</p>
            </div>
        </div>
    </div>
</div>

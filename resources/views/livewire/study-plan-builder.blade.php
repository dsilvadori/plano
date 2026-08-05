<div
    x-data="{
        isBuilding: false,
        progress: 0,
        step: 0,
        timer: null,
        finalizing: false,
        canSubmitForm: false,
        selectedCourseId: @js((string) $course_id),
        courseExamDates: @js($courseExamDates),
        examDate: @js($exam_date),
        examDateLocked: @js($exam_date_locked),
        steps: [
            'Lendo sua disponibilidade semanal',
            'Organizando matéria básica e conhecimento específico',
            'Distribuindo revisões e questões no ciclo',
            'Validando a viabilidade até a prova',
            'Finalizando seu plano personalizado'
        ],
        init() {
            this.syncCourseExamDate();
        },
        syncCourseExamDate(clearUnlocked = false) {
            const courseExamDate = this.courseExamDates[String(this.selectedCourseId || '')] || '';

            this.examDateLocked = courseExamDate !== '';

            if (this.examDateLocked) {
                this.examDate = courseExamDate;
                return;
            }

            if (clearUnlocked) {
                this.examDate = '';
            }
        },
        queueSubmit() {
            if (this.canSubmitForm) {
                return true;
            }

            if (! this.validatePlanForm()) {
                return false;
            }

            this.startPlanProgress();
            return false;
        },
        parseMinutes(raw) {
            raw = String(raw || '').trim();

            if (/^\d{1,2}$/.test(raw)) {
                return parseInt(raw, 10) * 60;
            }

            if (/^\d{1,2}:\d{2}$/.test(raw)) {
                const [hours, minutes] = raw.split(':').map((part) => parseInt(part, 10));

                if (Number.isNaN(hours) || Number.isNaN(minutes) || minutes >= 60) {
                    return 0;
                }

                return (hours * 60) + minutes;
            }

            return 0;
        },
        validatePlanForm() {
            const form = this.$refs.planForm;
            const dayInputs = [...form.querySelectorAll('input[name=\'available_days[]\']')];

            form.querySelectorAll('[data-plan-validation]').forEach((input) => input.setCustomValidity(''));

            if (! form.reportValidity()) {
                return false;
            }

            const checkedDays = dayInputs.filter((input) => input.checked);
            if (checkedDays.length === 0) {
                dayInputs[0]?.setCustomValidity('Selecione pelo menos um dia disponível.');
                dayInputs[0]?.reportValidity();
                return false;
            }

            for (const dayInput of checkedDays) {
                const minutesInput = form.querySelector(`[name='available_minutes_by_day[${dayInput.value}]']`);
                const minutes = this.parseMinutes(minutesInput?.value);

                if (! minutesInput || minutes < 30 || minutes > 480) {
                    minutesInput?.setCustomValidity('Informe um tempo entre 00:30 e 08:00 para cada dia selecionado.');
                    minutesInput?.reportValidity();
                    return false;
                }
            }

            return true;
        },
        startPlanProgress() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            this.isBuilding = true;
            this.progress = 0;
            this.step = 0;
            this.finalizing = false;
            this.canSubmitForm = false;
            clearInterval(this.timer);

            const startedAt = Date.now();
            const animationDuration = 10000;
            this.timer = setInterval(() => {
                const elapsed = Date.now() - startedAt;
                const targetProgress = Math.min(99, Math.round((elapsed / animationDuration) * 100));

                if (targetProgress > this.progress) {
                    this.progress = targetProgress;
                }

                if (this.step < this.steps.length - 1 && this.progress >= ((this.step + 1) * 20)) {
                    this.step++;
                }

                if (elapsed >= animationDuration) {
                    clearInterval(this.timer);
                    this.progress = 100;
                    this.step = this.steps.length - 1;
                    this.finalizing = true;
                    this.canSubmitForm = true;
                    setTimeout(() => {
                        this.$refs.planForm.submit();
                    }, 50);
                }
            }, 150);
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
            @if ($hasAvailableCourses)
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Planos já criados</p>
                <h2 class="mt-3 text-xl font-semibold text-white">Você já tem um plano ativo para cada curso liberado.</h2>
                <p class="mt-3 text-sm text-amber-100/90">Para ajustar sua rotina, entre no plano do curso desejado e use a opção de edição.</p>
            @else
                <p class="text-sm uppercase tracking-[0.25em] text-amber-300">Curso ainda não vinculado</p>
                <h2 class="mt-3 text-xl font-semibold text-white">Seu acesso existe, mas ainda não encontramos um curso liberado para este aluno.</h2>
                <p class="mt-3 text-sm text-amber-100/90">Se sua compra acabou de ser aprovada, aguarde o processamento do webhook. Se o problema continuar, peça ao suporte para confirmar o vínculo do seu e-mail com o curso correto.</p>
            @endif
        </div>
    @else

    <form
        x-ref="planForm"
        action="{{ $studyPlan ? route('study-plans.update', $studyPlan) : route('study-plans.store') }}"
        method="POST"
        x-on:submit.prevent="queueSubmit()"
        x-on:input.capture="$event.target.setCustomValidity && $event.target.setCustomValidity('')"
        x-on:change.capture="
            if ($event.target.name === 'available_days[]') {
                $refs.planForm.querySelectorAll('input[name=\'available_days[]\']').forEach((input) => input.setCustomValidity(''));
            }

            $event.target.setCustomValidity && $event.target.setCustomValidity('');
        "
        class="grid gap-6 lg:grid-cols-2"
    >
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

        <div class="card-subtle lg:col-span-2">
            <label class="stat-label">Curso</label>
            <select
                name="course_id"
                x-model="selectedCourseId"
                x-on:change="syncCourseExamDate(true)"
                wire:model.change="course_id"
                wire:change="selectCourse($event.target.value)"
                required
                data-plan-validation
                class="mt-3 w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100"
            >
                <option value="">Selecione</option>
                @foreach ($courses as $course)
                    <option value="{{ $course->id }}" @selected((string) $course_id === (string) $course->id)>{{ $course->name }}</option>
                @endforeach
            </select>
            @error('course_id') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>

        <div class="card-subtle">
            <label class="stat-label">Data de início</label>
            <input name="start_date" wire:model.live="start_date" type="date" min="{{ now()->toDateString() }}" required data-plan-validation x-on:click="$el.showPicker && $el.showPicker()" x-on:focus="$el.showPicker && $el.showPicker()" class="mt-3 w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100 color-scheme-dark">
            <p class="mt-2 text-xs text-slate-500">Escolha quando você realmente começa. Não permitimos datas passadas para o plano ficar fiel à sua rotina atual.</p>
            @error('start_date') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>

        <div class="card-subtle">
            <label class="stat-label">Data da prova <span class="text-slate-500 normal-case tracking-normal" x-text="examDateLocked ? '(definida no curso)' : '(opcional)'">{{ $exam_date_locked ? '(definida no curso)' : '(opcional)' }}</span></label>
            <input type="hidden" x-bind:name="examDateLocked ? 'exam_date' : null" x-model="examDate">
            <input
                x-show="examDateLocked"
                x-bind:value="examDate"
                type="date"
                disabled
                class="mt-3 w-full rounded-2xl border border-white/10 bg-slate-900/70 px-4 py-3 text-slate-300 opacity-80 color-scheme-dark"
            >
            <input
                x-show="! examDateLocked"
                x-bind:name="examDateLocked ? null : 'exam_date'"
                x-model="examDate"
                wire:model.live="exam_date"
                type="date"
                min="{{ $start_date ?: now()->toDateString() }}"
                x-on:click="$el.showPicker && $el.showPicker()"
                x-on:focus="$el.showPicker && $el.showPicker()"
                class="mt-3 w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100 color-scheme-dark"
            >
            <p x-show="examDateLocked" class="mt-2 text-xs text-slate-500">Este curso já tem data de prova cadastrada. O aluno usa essa data como padrão e não pode alterá-la aqui.</p>
            <p x-show="! examDateLocked" class="mt-2 text-xs text-slate-500">Se o edital ainda não saiu, deixe em branco. O sistema cria um ciclo contínuo e você pode ajustar depois.</p>
            @error('exam_date') <p class="mt-2 text-sm text-rose-300">{{ $message }}</p> @enderror
        </div>

        @if ($minimumWeeklySuggestion)
            <div class="card-subtle lg:col-span-2">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="stat-label">Carga mínima sugerida</p>
                        <h3 class="mt-2 text-2xl font-semibold text-white">{{ $minimumWeeklySuggestion['minimum_weekly_label'] }} por semana</h3>
                        <p class="mt-2 text-sm text-slate-300">
                            Inclui {{ \App\Support\StudyTime::formatMinutes($minimumWeeklySuggestion['theory_minutes']) }} de aulas e {{ \App\Support\StudyTime::formatMinutes($minimumWeeklySuggestion['practice_minutes']) }} reservados para revisão e questões até a prova.
                        </p>
                    </div>
                    <div class="rounded-2xl border {{ $minimumWeeklySuggestion['status'] === 'good' ? 'border-emerald-400/20 bg-emerald-400/10 text-emerald-100' : 'border-amber-400/20 bg-amber-400/10 text-amber-100' }} px-4 py-3 text-sm font-semibold">
                        Atual: {{ $minimumWeeklySuggestion['current_weekly_label'] }} / semana
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-3">
                    <div class="rounded-2xl border border-white/10 bg-slate-950/70 p-4">
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Prazo</p>
                        <p class="mt-2 text-lg font-semibold text-white">{{ $minimumWeeklySuggestion['weeks'] }} semana(s)</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-slate-950/70 p-4">
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Meta total</p>
                        <p class="mt-2 text-lg font-semibold text-white">{{ \App\Support\StudyTime::formatMinutes($minimumWeeklySuggestion['required_minutes']) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-slate-950/70 p-4">
                        <p class="text-xs uppercase tracking-[0.2em] text-slate-500">Média por dia</p>
                        <p class="mt-2 text-lg font-semibold text-white">
                            {{ $minimumWeeklySuggestion['minimum_daily_average_label'] ?? 'Selecione dias' }}
                        </p>
                    </div>
                </div>

                @if ($minimumWeeklySuggestion['status'] === 'warning')
                    <p class="mt-4 rounded-2xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">
                        Para dar conta do curso com revisão e questões, aumente pelo menos {{ $minimumWeeklySuggestion['deficit_label'] }} por semana ou selecione mais dias disponíveis.
                    </p>
                @else
                    <p class="mt-4 rounded-2xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100">
                        A disponibilidade informada cobre a carga mínima sugerida até a prova.
                    </p>
                @endif
            </div>
        @endif

        <div class="card-subtle lg:col-span-2">
            <label class="stat-label">Dias disponíveis</label>
            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($dayLabels as $day => $label)
                    <label class="rounded-2xl border border-white/10 bg-slate-950/70 p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-slate-100">{{ $label }}</span>
                            <span class="relative inline-flex h-5 w-5 items-center justify-center">
                                <input
                                    name="available_days[]"
                                    wire:model.live="available_days"
                                    value="{{ $day }}"
                                    type="checkbox"
                                    @checked(in_array($day, $available_days, true))
                                    data-plan-validation
                                    x-on:change="
                                        const input = $refs['minutes_{{ $day }}'];

                                        if (! input) {
                                            return;
                                        }

                                        if ($el.checked && (input.value === '' || input.value === '00:00')) {
                                            input.value = '02:00';
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            return;
                                        }

                                        if (! $el.checked && input.value === '02:00') {
                                            input.value = '00:00';
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                        }
                                    "
                                    class="day-checkbox"
                                >
                                <span class="day-checkmark" aria-hidden="true">
                                    <svg viewBox="0 0 20 20" fill="none">
                                        <path d="M5 10.3 8.3 13.5 15.2 6.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                            </span>
                        </div>
                        <div class="mt-3">
                            <input
                                x-ref="minutes_{{ $day }}"
                                name="available_minutes_by_day[{{ $day }}]"
                                wire:model.live="available_minutes_by_day.{{ $day }}"
                                type="text"
                                inputmode="numeric"
                                data-plan-validation
                                value="{{ $available_minutes_by_day[$day] ?? '00:00' }}"
                                x-on:focus="if ($el.value === '00:00') { $el.select() }"
                                x-on:keydown.arrow-up.prevent="
                                    let raw = $el.value.trim();
                                    let minutes = 0;

                                    if (/^\d{1,2}$/.test(raw)) {
                                        minutes = parseInt(raw, 10) * 60;
                                    } else if (/^\d{1,2}:\d{2}$/.test(raw)) {
                                        let [hours, mins] = raw.split(':').map((part) => parseInt(part, 10));
                                        minutes = (Number.isNaN(hours) || Number.isNaN(mins) || mins >= 60) ? 0 : (hours * 60) + mins;
                                    }

                                    minutes = Math.min(480, Math.ceil(minutes / 15) * 15 + (minutes % 15 === 0 ? 15 : 0));
                                    $el.value = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                                    $el.dispatchEvent(new Event('input', { bubbles: true }));
                                "
                                x-on:keydown.arrow-down.prevent="
                                    let raw = $el.value.trim();
                                    let minutes = 0;

                                    if (/^\d{1,2}$/.test(raw)) {
                                        minutes = parseInt(raw, 10) * 60;
                                    } else if (/^\d{1,2}:\d{2}$/.test(raw)) {
                                        let [hours, mins] = raw.split(':').map((part) => parseInt(part, 10));
                                        minutes = (Number.isNaN(hours) || Number.isNaN(mins) || mins >= 60) ? 0 : (hours * 60) + mins;
                                    }

                                    minutes = Math.max(0, Math.floor(minutes / 15) * 15 - (minutes % 15 === 0 ? 15 : 0));
                                    $el.value = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
                                    $el.dispatchEvent(new Event('input', { bubbles: true }));
                                "
                                x-on:blur="
                                    let raw = $el.value.trim();
                                    if (raw === '') {
                                        $el.value = '00:00';
                                        $el.dispatchEvent(new Event('input', { bubbles: true }));
                                        return;
                                    }
                                    if (/^\d{1,2}$/.test(raw)) {
                                        let hours = Math.min(8, parseInt(raw, 10));
                                        $el.value = `${String(hours).padStart(2, '0')}:00`;
                                        $el.dispatchEvent(new Event('input', { bubbles: true }));
                                        return;
                                    }
                                    if (/^\d{1,2}:\d{2}$/.test(raw)) {
                                        let [hours, minutes] = raw.split(':');
                                        hours = parseInt(hours, 10);
                                        minutes = parseInt(minutes, 10);
                                        if (Number.isNaN(hours) || Number.isNaN(minutes) || minutes >= 60) {
                                            $el.value = '00:00';
                                            $el.dispatchEvent(new Event('input', { bubbles: true }));
                                            return;
                                        }
                                        if (hours > 8 || (hours === 8 && minutes > 0)) {
                                            $el.value = '08:00';
                                            $el.dispatchEvent(new Event('input', { bubbles: true }));
                                            return;
                                        }
                                        $el.value = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
                                        $el.dispatchEvent(new Event('input', { bubbles: true }));
                                        return;
                                    }
                                    $el.value = '00:00';
                                    $el.dispatchEvent(new Event('input', { bubbles: true }));
                                "
                                class="w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-slate-100"
                            >
                            <p class="mt-2 text-xs text-slate-500">Ao marcar o dia, sugerimos `02:00` automaticamente. Você pode alterar para a sua realidade, mas limitamos cada dia a no máximo `08:00`.</p>
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
                        <input name="intensity" wire:model.live="intensity" type="radio" value="{{ $value }}" required data-plan-validation class="mr-2 text-amber-300">
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
        x-init="$watch('isBuilding', value => { if (!value) { clearInterval(timer) } })"
        class="app-modal-overlay fixed inset-0 z-50 items-center justify-center bg-slate-950/85 p-6 backdrop-blur"
        style="display: none;"
    >
        <div class="app-modal-panel w-full max-w-2xl rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top,_rgba(250,204,21,0.14),_transparent_24%),linear-gradient(180deg,_rgba(15,23,42,0.98),_rgba(2,6,23,0.98))] p-8 shadow-2xl shadow-black/50">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm uppercase tracking-[0.3em] text-amber-300">Montando seu plano</p>
                    <h3 class="mt-3 text-2xl font-semibold text-white">Estamos organizando seu ciclo de estudos.</h3>
                <p class="mt-3 max-w-xl text-sm text-slate-300">A ideia aqui é transformar sua rotina em um plano viável, equilibrado e claro até a prova. Vamos manter a criação visível por alguns segundos para você acompanhar cada etapa.</p>
                </div>
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl border border-amber-400/20 bg-amber-400/10 text-lg font-semibold text-amber-200">
                    <span x-text="`${Math.min(progress, 100)}%`"></span>
                </div>
            </div>

            <div class="mt-8">
                <div class="h-4 overflow-hidden rounded-full bg-slate-800/90">
                    <div class="progress-glow h-full rounded-full bg-gradient-to-r from-amber-300 via-yellow-300 to-sky-400 transition-all duration-300" :style="`width: ${Math.min(progress, 100)}%`"></div>
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
                <p class="mt-2 text-sm text-slate-200" x-text="finalizing ? 'Plano pronto. Estamos abrindo sua tela final...' : steps[step]"></p>
            </div>
        </div>
    </div>
</div>

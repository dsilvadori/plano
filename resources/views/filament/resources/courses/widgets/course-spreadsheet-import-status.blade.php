@php
    $colorClasses = [
        'gray' => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700',
        'warning' => 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/20',
        'success' => 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-400/10 dark:text-success-300 dark:ring-success-400/20',
        'danger' => 'bg-danger-50 text-danger-700 ring-danger-200 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/20',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div
            @if (! $run || in_array($run->status, ['queued', 'running'], true))
                wire:poll.5s
            @endif
            class="space-y-4"
        >
            @if ($run?->status === 'finished')
                <div class="rounded-md bg-success-50 px-3 py-2 text-sm font-semibold text-success-700 ring-1 ring-success-200 dark:bg-success-400/10 dark:text-success-300 dark:ring-success-400/20">
                    Importação 100% concluída
                </div>
            @else
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                            Importação da planilha
                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            @if ($run)
                                {{ $problemMessage ?: ($run->latest_message ?: 'Sem atualização recente.') }}
                            @else
                                Nenhuma importação de planilha registrada para este curso.
                            @endif
                        </p>
                    </div>

                    @if ($run)
                        <span class="inline-flex w-fit items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $colorClasses[$statusColor] ?? $colorClasses['gray'] }}">
                            {{ $statusLabel }}
                        </span>
                    @endif
                </div>

                @if ($run)
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div>
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Progresso</div>
                            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $run->progress_label }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Duração</div>
                            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $run->duration_label }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Módulos</div>
                            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $run->processed_modules }}/{{ $run->total_modules }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Trilhas</div>
                            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $run->total_tracks }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Aulas</div>
                            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $run->total_lessons }}</div>
                        </div>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                        <div
                            class="h-full rounded-full transition-all {{ $run->status === 'failed' ? 'bg-danger-500' : 'bg-primary-600' }}"
                            style="width: {{ max(0, min(100, $run->progress_percent)) }}%"
                        ></div>
                    </div>

                    @if (filled($problemMessage))
                        <div class="rounded-md bg-danger-50 px-3 py-2 text-sm text-danger-700 ring-1 ring-danger-200 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/20">
                            {{ $problemMessage }}
                        </div>
                    @elseif (filled($run->error_message))
                        <div class="rounded-md bg-danger-50 px-3 py-2 text-sm text-danger-700 ring-1 ring-danger-200 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/20">
                            {{ $run->error_message }}
                        </div>
                    @endif
                @endif
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

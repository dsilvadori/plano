<?php

namespace App\Filament\Resources\Courses\Widgets;

use App\Models\Course;
use App\Models\CourseSpreadsheetImportRun;
use App\Services\ActiveStudyPlanRefresher;
use App\Services\CourseSpreadsheetImporter;
use Filament\Notifications\Notification;
use Filament\Widgets\Concerns\CanPoll;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;

class CourseSpreadsheetImportStatus extends Widget
{
    use CanPoll;

    protected string $view = 'filament.resources.courses.widgets.course-spreadsheet-import-status';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '5s';

    public Course $record;

    #[On('course-spreadsheet-import-updated')]
    public function refreshImportStatus(): void
    {
        //
    }

    public function refreshStudyPlans(ActiveStudyPlanRefresher $refresher): void
    {
        $refreshed = $refresher->refreshCourseFromNextWeek($this->record->fresh());
        $run = $this->latestRun();

        if ($run) {
            $summary = is_array($run->summary) ? $run->summary : [];
            $summary['study_plan_refresh'] = [
                'refreshed_plans' => $refreshed,
                'refreshed_at' => now()->toIso8601String(),
                'scope' => 'from_next_week',
            ];

            $run->forceFill([
                'latest_message' => 'Importação concluída. Planos atualizados a partir da próxima semana.',
                'summary' => $summary,
            ])->save();
        }

        Notification::make()
            ->title('Planos atualizados.')
            ->body("{$refreshed} plano(s) ativo(s) foram atualizados a partir da próxima semana.")
            ->success()
            ->send();
    }

    protected function getViewData(): array
    {
        $run = $this->latestRun();

        if ($run && in_array($run->status, ['queued', 'running', 'failed'], true)) {
            $run = app(CourseSpreadsheetImporter::class)->processImportRun($run);
        }

        $problemMessage = $run ? $this->problemMessage($run) : null;

        return [
            'run' => $run,
            'statusLabel' => $problemMessage ? 'Atenção' : ($run ? $this->statusLabel((string) $run->status) : null),
            'statusColor' => $problemMessage ? 'danger' : ($run ? $this->statusColor((string) $run->status) : 'gray'),
            'problemMessage' => $problemMessage,
            'plansWereRefreshed' => $run ? $this->plansWereRefreshed($run) : false,
        ];
    }

    protected function latestRun(): ?CourseSpreadsheetImportRun
    {
        return CourseSpreadsheetImportRun::query()
            ->where('course_id', $this->record->id)
            ->latest()
            ->first();
    }

    protected function plansWereRefreshed(CourseSpreadsheetImportRun $run): bool
    {
        return filled(data_get($run->summary, 'study_plan_refresh.refreshed_at'));
    }

    protected function problemMessage(CourseSpreadsheetImportRun $run): ?string
    {
        if ($run->status !== 'queued') {
            return null;
        }

        if (filled($run->stored_path) && ! Storage::disk('local')->exists($run->stored_path)) {
            return 'Esta importação não pode iniciar porque a planilha temporária não existe mais. Reenvie o arquivo.';
        }

        return null;
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'Na fila',
            'running' => 'Rodando',
            'finished' => 'Concluída',
            'failed' => 'Falhou',
            default => $status,
        };
    }

    protected function statusColor(string $status): string
    {
        return match ($status) {
            'queued' => 'gray',
            'running' => 'warning',
            'finished' => 'success',
            'failed' => 'danger',
            default => 'gray',
        };
    }
}

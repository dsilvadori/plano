<?php

namespace App\Filament\Resources\Courses\Widgets;

use App\Models\Course;
use App\Models\CourseSpreadsheetImportRun;
use App\Services\CourseSpreadsheetImporter;
use Filament\Widgets\Concerns\CanPoll;
use Filament\Widgets\Widget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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

    protected function getViewData(): array
    {
        $run = $this->latestRun();

        if ($run && in_array($run->status, ['queued', 'running', 'failed'], true)) {
            $lock = Cache::lock('course-spreadsheet-import-run-'.$run->id, 120);

            if ($lock->get()) {
                try {
                    $run = app(CourseSpreadsheetImporter::class)->processImportRun($run);
                } catch (QueryException $exception) {
                    if (! str_contains($exception->getMessage(), 'database is locked')) {
                        throw $exception;
                    }
                } finally {
                    $lock->release();
                }
            }
        }

        $problemMessage = $run ? $this->problemMessage($run) : null;

        return [
            'run' => $run,
            'statusLabel' => $problemMessage ? 'Atenção' : ($run ? $this->statusLabel((string) $run->status) : null),
            'statusColor' => $problemMessage ? 'danger' : ($run ? $this->statusColor((string) $run->status) : 'gray'),
            'problemMessage' => $problemMessage,
        ];
    }

    protected function latestRun(): ?CourseSpreadsheetImportRun
    {
        return CourseSpreadsheetImportRun::query()
            ->where('course_id', $this->record->id)
            ->latest()
            ->first();
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

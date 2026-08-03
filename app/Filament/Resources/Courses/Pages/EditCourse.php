<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Services\CourseSpreadsheetImporter;
use App\Services\LessonCourseLinker;
use App\Support\FilamentThumbnailUpload;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $previousPath = $this->record->thumbnail_path;
        $hiddenPath = $data['thumbnail_path'] ?? null;
        $uploadState = $data['thumbnail_upload'] ?? null;

        try {
            $data['thumbnail_path'] = self::resolveUploadedPath($uploadState, $hiddenPath ?: $previousPath, 'course-thumbnails');
        } catch (Throwable $exception) {
            self::logCourseDebug('edit.thumbnail_failed', [
                'course_id' => $this->record->id,
                'course_name' => $this->record->name,
                'previous_path' => $previousPath,
                'hidden_path' => $hiddenPath,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        unset($data['thumbnail_upload']);

        self::logCourseDebug('edit.thumbnail_resolved', [
            'course_id' => $this->record->id,
            'course_name' => $this->record->name,
            'previous_path' => $previousPath,
            'hidden_path' => $hiddenPath,
            'resolved_path' => $data['thumbnail_path'] ?? null,
            'has_upload_state' => filled($uploadState),
        ]);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncLessonLinks')
                ->label('Atualizar vínculos')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (LessonCourseLinker $linker): void {
                    try {
                        $stats = $linker->sync($this->record);

                        Notification::make()
                            ->title('Vínculos do curso atualizados.')
                            ->body(self::formatSyncStats($stats))
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível atualizar os vínculos.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('importStructure')
                ->label('Importar estrutura')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Importar estrutura para este curso')
                ->modalDescription('Envie uma planilha .xlsx para adicionar módulos, trilha oficial e aulas ao curso aberto, sem criar um novo curso.')
                ->form([
                    FileUpload::make('spreadsheet')
                        ->label('Planilha do curso')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv',
                            'text/plain',
                        ])
                        ->disk('local')
                        ->directory('imports/courses')
                        ->preserveFilenames()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set, CourseSpreadsheetImporter $importer): void {
                            $set('preview_error', null);
                            $set('preview_course_name', null);
                            $set('preview_module_count', null);
                            $set('preview_module_create_count', null);
                            $set('preview_module_update_count', null);
                            $set('preview_lesson_count', null);
                            $set('preview_lesson_create_count', null);
                            $set('preview_lesson_update_count', null);
                            $set('preview_track_count', null);
                            $set('preview_total_minutes', null);

                            $path = self::resolveUploadedSpreadsheetPath($state);

                            if ($path === null) {
                                return;
                            }

                            try {
                                $preview = $importer->preview(Storage::disk('local')->path($path), $this->record);

                                self::logCourseDebug('import.preview_success', [
                                    'course_id' => $this->record->id,
                                    'course_name' => $this->record->name,
                                    'spreadsheet_path' => $path,
                                    'payload_course_name' => $preview['payload']['course_name'] ?? null,
                                    'module_total' => $preview['modules']['total'] ?? null,
                                    'module_create' => $preview['modules']['create'] ?? null,
                                    'module_update' => $preview['modules']['update'] ?? null,
                                    'lesson_total' => $preview['lessons']['total'] ?? null,
                                ]);

                                $set('preview_course_name', $preview['payload']['course_name']);
                                $set('preview_module_count', $preview['modules']['total']);
                                $set('preview_module_create_count', $preview['modules']['create']);
                                $set('preview_module_update_count', $preview['modules']['update']);
                                $set('preview_lesson_count', $preview['lessons']['total']);
                                $set('preview_lesson_create_count', $preview['lessons']['create']);
                                $set('preview_lesson_update_count', $preview['lessons']['update']);
                                $set('preview_track_count', 1);
                                $set('preview_total_minutes', $preview['total_minutes']);
                            } catch (Throwable $exception) {
                                self::logCourseDebug('import.preview_failed', [
                                    'course_id' => $this->record->id,
                                    'course_name' => $this->record->name,
                                    'spreadsheet_path' => $path,
                                    'error' => $exception->getMessage(),
                                ]);

                                $set('preview_error', $exception->getMessage());
                            }
                        })
                        ->required(),
                    Hidden::make('preview_course_name'),
                    Hidden::make('preview_module_count'),
                    Hidden::make('preview_module_create_count'),
                    Hidden::make('preview_module_update_count'),
                    Hidden::make('preview_lesson_count'),
                    Hidden::make('preview_lesson_create_count'),
                    Hidden::make('preview_lesson_update_count'),
                    Hidden::make('preview_track_count'),
                    Hidden::make('preview_total_minutes'),
                    Hidden::make('preview_error'),
                    Placeholder::make('preview')
                        ->label('Prévia da importação')
                        ->hidden(fn (Get $get): bool => blank($get('preview_course_name')) && blank($get('preview_error')))
                        ->content(function (Get $get): HtmlString {
                            if (filled($get('preview_error'))) {
                                return new HtmlString('<div class="text-sm text-danger-600">Não foi possível ler a planilha: '.e((string) $get('preview_error')).'</div>');
                            }

                            $courseName = (string) ($get('preview_course_name') ?? '');
                            $moduleCount = (int) ($get('preview_module_count') ?? 0);
                            $moduleCreateCount = (int) ($get('preview_module_create_count') ?? 0);
                            $moduleUpdateCount = (int) ($get('preview_module_update_count') ?? 0);
                            $lessonCount = (int) ($get('preview_lesson_count') ?? 0);
                            $lessonCreateCount = (int) ($get('preview_lesson_create_count') ?? 0);
                            $lessonUpdateCount = (int) ($get('preview_lesson_update_count') ?? 0);
                            $trackCount = (int) ($get('preview_track_count') ?? 0);
                            $totalMinutes = (int) ($get('preview_total_minutes') ?? 0);

                            return new HtmlString(implode('', [
                                '<div class="space-y-1 text-sm">',
                                '<div><strong>Curso na planilha:</strong> '.e($courseName).'</div>',
                                '<div><strong>Curso destino:</strong> '.e($this->record->name).'</div>',
                                '<div><strong>Módulos importáveis:</strong> '.e((string) $moduleCount).' ('.e((string) $moduleCreateCount).' novos, '.e((string) $moduleUpdateCount).' atualizados)</div>',
                                '<div><strong>Aulas importáveis:</strong> '.e((string) $lessonCount).' ('.e((string) $lessonCreateCount).' novas, '.e((string) $lessonUpdateCount).' atualizadas)</div>',
                                '<div><strong>Trilhas oficiais atualizadas:</strong> '.e((string) $trackCount).'</div>',
                                '<div><strong>Carga total:</strong> '.e(self::formatPreviewMinutes($totalMinutes)).'</div>',
                                '</div>',
                            ]));
                        }),
                ])
                ->action(function (array $data, CourseSpreadsheetImporter $importer): void {
                    $path = self::resolveUploadedSpreadsheetPath($data['spreadsheet'] ?? null);

                    if ($path === null) {
                        Notification::make()
                            ->title('Selecione uma planilha válida.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        self::logCourseDebug('import.start', [
                            'course_id' => $this->record->id,
                            'course_name' => $this->record->name,
                            'spreadsheet_path' => $path,
                            'modules_before' => $this->record->modules()->count(),
                        ]);

                        $course = $importer->importInto($this->record, Storage::disk('local')->path($path));

                        self::logCourseDebug('import.success', [
                            'course_id' => $course->id,
                            'course_name' => $course->name,
                            'spreadsheet_path' => $path,
                            'modules_after' => $course->modules()->count(),
                            'study_tracks_after' => $course->studyTracks()->count(),
                        ]);

                        Notification::make()
                            ->title('Estrutura importada com sucesso.')
                            ->body("Curso {$course->name} agora tem {$course->modules()->count()} módulos.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        self::logCourseDebug('import.failed', [
                            'course_id' => $this->record->id,
                            'course_name' => $this->record->name,
                            'spreadsheet_path' => $path,
                            'error' => $exception->getMessage(),
                        ]);

                        Notification::make()
                            ->title('Não foi possível importar a estrutura.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                }),
            DeleteAction::make(),
        ];
    }

    protected static function formatPreviewMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours === 0) {
            return "{$minutes} min";
        }

        if ($remainingMinutes === 0) {
            return "{$hours}h";
        }

        return "{$hours}h {$remainingMinutes}min";
    }

    protected static function resolveUploadedSpreadsheetPath(mixed $state): ?string
    {
        return self::resolveUploadedPath($state);
    }

    protected static function resolveUploadedPath(mixed $state, ?string $fallback = null, ?string $directory = null): ?string
    {
        foreach (Arr::wrap($state) as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $path = $directory ? FilamentThumbnailUpload::store($state, $directory) : null;

        if ($path !== null) {
            return $path;
        }

        return $fallback;
    }

    protected static function formatSyncStats(array $stats): string
    {
        return sprintf(
            '%d aula(s) vinculada(s), %d placeholder(s) substituído(s), %d aula(s) publicada(s) e %d plano(s) atualizado(s).',
            (int) ($stats['linked'] ?? 0),
            (int) ($stats['replaced'] ?? 0),
            (int) ($stats['published'] ?? 0),
            (int) ($stats['plans_synced'] ?? 0),
        );
    }

    protected static function logCourseDebug(string $event, array $context = []): void
    {
        try {
            Log::channel('course_debug')->info($event, $context);
        } catch (Throwable) {
            //
        }
    }
}

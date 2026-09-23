<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Models\CourseSpreadsheetImportRun;
use App\Services\CourseSpreadsheetImporter;
use App\Services\LessonCourseLinker;
use App\Support\CourseSpreadsheetUpload;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('syncLessonLinks')
                ->label('Atualizar vínculos')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (LessonCourseLinker $linker): void {
                    try {
                        $stats = $linker->sync();

                        Notification::make()
                            ->title('Vínculos atualizados.')
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
            Action::make('importSpreadsheet')
                ->label('Importar planilha')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Importar curso por planilha')
                ->modalDescription('Envie uma planilha .xlsx. O sistema usa a aba "Nome do Curso" quando existir e, se não existir, usa o nome do arquivo.')
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

                            $absolutePath = self::resolveUploadedSpreadsheetPreviewPath($state);

                            if ($absolutePath === null) {
                                return;
                            }

                            try {
                                $preview = $importer->preview($absolutePath);

                                $set('preview_course_name', $preview['course']['name']);
                                $set('preview_module_count', $preview['modules']['total']);
                                $set('preview_module_create_count', $preview['modules']['create']);
                                $set('preview_module_update_count', $preview['modules']['update']);
                                $set('preview_lesson_count', $preview['lessons']['total']);
                                $set('preview_lesson_create_count', $preview['lessons']['create']);
                                $set('preview_lesson_update_count', $preview['lessons']['update']);
                                $set('preview_track_count', 1);
                                $set('preview_total_minutes', $preview['total_minutes']);
                            } catch (Throwable $exception) {
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
                                return new HtmlString('<div class="text-sm text-danger-600">Não foi possível ler a planilha: ' . e((string) $get('preview_error')) . '</div>');
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
                                '<div><strong>Curso:</strong> ' . e($courseName) . '</div>',
                                '<div><strong>Módulos importáveis:</strong> ' . e((string) $moduleCount) . ' (' . e((string) $moduleCreateCount) . ' novos, ' . e((string) $moduleUpdateCount) . ' atualizados)</div>',
                                '<div><strong>Aulas importáveis:</strong> ' . e((string) $lessonCount) . ' (' . e((string) $lessonCreateCount) . ' novas, ' . e((string) $lessonUpdateCount) . ' atualizadas)</div>',
                                '<div><strong>Trilhas oficiais criadas:</strong> ' . e((string) $trackCount) . '</div>',
                                '<div><strong>Carga total:</strong> ' . e(self::formatPreviewMinutes($totalMinutes)) . '</div>',
                                '</div>',
                            ]));
                        }),
                ])
                ->action(function (array $data, CourseSpreadsheetImporter $importer): void {
                    $path = self::resolveUploadedSpreadsheetPath($data['spreadsheet'] ?? null);

                    if ($path === null) {
                        Notification::make()
                            ->title('Não foi possível preservar a planilha.')
                            ->body('Reenvie o arquivo e tente novamente. A importação só é enfileirada quando a planilha fica salva no storage local.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $previewPath = self::resolveUploadedSpreadsheetPreviewPath($data['spreadsheet'] ?? null);

                    if ($previewPath === null) {
                        Notification::make()
                            ->title('Não foi possível ler a planilha salva.')
                            ->body('Reenvie o arquivo e tente novamente.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $preview = $importer->preview($previewPath);
                    $run = CourseSpreadsheetImportRun::query()->create([
                        'course_name' => $preview['payload']['course_name'] ?? $preview['course']['name'] ?? null,
                        'file_name' => basename($path),
                        'stored_path' => $path,
                        'status' => 'queued',
                        'total_modules' => (int) ($preview['modules']['total'] ?? 0),
                        'processed_modules' => 0,
                        'total_tracks' => self::countPreviewTracks($preview),
                        'total_lessons' => (int) ($preview['lessons']['total'] ?? 0),
                        'total_minutes' => (int) ($preview['total_minutes'] ?? 0),
                        'latest_message' => 'Importação na fila.',
                    ]);

                    Notification::make()
                        ->title('Importação iniciada.')
                        ->body('Acompanhe o progresso em Importações de Planilhas.')
                        ->success()
                        ->send();
                }),
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
        return CourseSpreadsheetUpload::storedPath($state);
    }

    protected static function resolveUploadedSpreadsheetPreviewPath(mixed $state): ?string
    {
        return CourseSpreadsheetUpload::absolutePath($state);
    }

    protected static function countPreviewTracks(array $preview): int
    {
        return collect($preview['payload']['modules'] ?? [])
            ->sum(fn (array $module): int => count($module['tracks'] ?? []));
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
}

<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Services\CourseSpreadsheetImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
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

                            $path = self::resolveUploadedSpreadsheetPath($state);

                            if ($path === null) {
                                return;
                            }

                            try {
                                $preview = $importer->preview(Storage::disk('local')->path($path));

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
                            ->title('Selecione uma planilha válida.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $course = $importer->import(Storage::disk('local')->path($path));

                        Notification::make()
                            ->title('Curso importado com sucesso.')
                            ->body("Curso {$course->name} com {$course->modules()->count()} módulos.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar a planilha.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
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
        if (is_string($state) && $state !== '') {
            return $state;
        }

        if (is_array($state)) {
            $first = Arr::first($state, fn ($value) => is_string($value) && $value !== '');

            return is_string($first) ? $first : null;
        }

        return null;
    }
}

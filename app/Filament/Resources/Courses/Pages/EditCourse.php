<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Services\CourseSpreadsheetImporter;
use App\Services\CourseSpreadsheetParser;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
                        ])
                        ->disk('local')
                        ->directory('imports/courses')
                        ->preserveFilenames()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set, CourseSpreadsheetParser $parser): void {
                            $set('preview_error', null);
                            $set('preview_course_name', null);
                            $set('preview_module_count', null);
                            $set('preview_track_count', null);
                            $set('preview_total_minutes', null);

                            $path = self::resolveUploadedSpreadsheetPath($state);

                            if ($path === null) {
                                return;
                            }

                            try {
                                $payload = $parser->parse(Storage::disk('local')->path($path));

                                $set('preview_course_name', $payload['course_name']);
                                $set('preview_module_count', count($payload['modules']));
                                $set('preview_track_count', 1);
                                $set('preview_total_minutes', array_sum(array_column($payload['modules'], 'workload_minutes')));
                            } catch (Throwable $exception) {
                                $set('preview_error', $exception->getMessage());
                            }
                        })
                        ->required(),
                    Hidden::make('preview_course_name'),
                    Hidden::make('preview_module_count'),
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
                            $trackCount = (int) ($get('preview_track_count') ?? 0);
                            $totalMinutes = (int) ($get('preview_total_minutes') ?? 0);

                            return new HtmlString(implode('', [
                                '<div class="space-y-1 text-sm">',
                                '<div><strong>Curso na planilha:</strong> ' . e($courseName) . '</div>',
                                '<div><strong>Curso destino:</strong> ' . e($this->record->name) . '</div>',
                                '<div><strong>Módulos importáveis:</strong> ' . e((string) $moduleCount) . '</div>',
                                '<div><strong>Trilhas oficiais atualizadas:</strong> ' . e((string) $trackCount) . '</div>',
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
                        $course = $importer->importInto($this->record, Storage::disk('local')->path($path));

                        Notification::make()
                            ->title('Estrutura importada com sucesso.')
                            ->body("Curso {$course->name} agora tem {$course->modules()->count()} módulos.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
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

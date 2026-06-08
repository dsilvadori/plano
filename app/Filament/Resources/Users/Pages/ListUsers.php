<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\UserSpreadsheetImporter;
use App\Services\UserSpreadsheetParser;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
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

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openStudentArea')
                ->label('Visualizar área do aluno')
                ->icon('heroicon-o-eye')
                ->color('warning')
                ->button()
                ->openUrlInNewTab()
                ->url(route('dashboard')),
            Action::make('importStudents')
                ->label('Importar alunos')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Importar alunos por planilha')
                ->modalDescription('Envie uma planilha .xlsx com as colunas ID do Curso, Nome do Curso, E-mail do aluno, Nome do Aluno e Status do Aluno. Somente alunos com status Ativo serão importados.')
                ->form([
                    FileUpload::make('spreadsheet')
                        ->label('Planilha de alunos')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->disk('local')
                        ->directory('imports/users')
                        ->preserveFilenames()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set, UserSpreadsheetParser $parser): void {
                            $set('preview_error', null);
                            $set('preview_total_rows', null);
                            $set('preview_active_rows', null);
                            $set('preview_students_count', null);
                            $set('preview_skipped_inactive_rows', null);
                            $set('preview_invalid_rows', null);

                            $path = self::resolveUploadedSpreadsheetPath($state);

                            if ($path === null) {
                                return;
                            }

                            try {
                                $payload = $parser->parse(Storage::disk('local')->path($path));

                                $set('preview_total_rows', $payload['total_rows']);
                                $set('preview_active_rows', $payload['active_rows']);
                                $set('preview_students_count', count($payload['students']));
                                $set('preview_skipped_inactive_rows', $payload['skipped_inactive_rows']);
                                $set('preview_invalid_rows', $payload['invalid_rows']);
                            } catch (Throwable $exception) {
                                $set('preview_error', $exception->getMessage());
                            }
                        })
                        ->required(),
                    Checkbox::make('send_first_access_email')
                        ->label('Enviar e-mail de primeiro acesso para novos alunos')
                        ->helperText('Deixe desligado se o SMTP ainda estiver em teste ou se a importação for grande.'),
                    Hidden::make('preview_total_rows'),
                    Hidden::make('preview_active_rows'),
                    Hidden::make('preview_students_count'),
                    Hidden::make('preview_skipped_inactive_rows'),
                    Hidden::make('preview_invalid_rows'),
                    Hidden::make('preview_error'),
                    Placeholder::make('preview')
                        ->label('Prévia da importação')
                        ->hidden(fn (Get $get): bool => blank($get('preview_total_rows')) && blank($get('preview_error')))
                        ->content(function (Get $get): HtmlString {
                            if (filled($get('preview_error'))) {
                                return new HtmlString('<div class="text-sm text-danger-600">Não foi possível ler a planilha: ' . e((string) $get('preview_error')) . '</div>');
                            }

                            return new HtmlString(implode('', [
                                '<div class="space-y-1 text-sm">',
                                '<div><strong>Linhas lidas:</strong> ' . e((string) ((int) $get('preview_total_rows'))) . '</div>',
                                '<div><strong>Linhas ativas:</strong> ' . e((string) ((int) $get('preview_active_rows'))) . '</div>',
                                '<div><strong>Alunos únicos importáveis:</strong> ' . e((string) ((int) $get('preview_students_count'))) . '</div>',
                                '<div><strong>Ignorados por status:</strong> ' . e((string) ((int) $get('preview_skipped_inactive_rows'))) . '</div>',
                                '<div><strong>Linhas inválidas:</strong> ' . e((string) ((int) $get('preview_invalid_rows'))) . '</div>',
                                '</div>',
                            ]));
                        }),
                ])
                ->action(function (array $data, UserSpreadsheetImporter $importer): void {
                    $path = self::resolveUploadedSpreadsheetPath($data['spreadsheet'] ?? null);

                    if ($path === null) {
                        Notification::make()
                            ->title('Selecione uma planilha válida.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $stats = $importer->import(
                            Storage::disk('local')->path($path),
                            (bool) ($data['send_first_access_email'] ?? false),
                        );

                        Notification::make()
                            ->title('Alunos importados com sucesso.')
                            ->body("Criados: {$stats['created']}. Atualizados: {$stats['updated']}. Vínculos de curso criados: {$stats['linked_courses']}. Ignorados por status: {$stats['skipped_inactive_rows']}.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar os alunos.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                }),
            CreateAction::make(),
        ];
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

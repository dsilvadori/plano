<?php

namespace App\Filament\Resources\QuestionBanks\Pages;

use App\Filament\Resources\QuestionBanks\QuestionBankResource;
use App\Services\QuestionLessonLinker;
use App\Services\QuestionPdfImporter;
use App\Services\QuestionSpreadsheetImporter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EditQuestionBank extends EditRecord
{
    protected static string $resource = QuestionBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPdf')
                ->label('Importar PDF')
                ->icon('heroicon-o-arrow-up-tray')
                ->requiresConfirmation()
                ->modalHeading('Importar questões do PDF')
                ->modalDescription('As questões identificadas serão criadas ou atualizadas neste banco. Revise enunciados, alternativas, gabaritos e comentários antes de usar com alunos.')
                ->action(function (QuestionPdfImporter $importer): void {
                    try {
                        $batch = $importer->import($this->record, auth()->id());

                        Notification::make()
                            ->title('PDF importado.')
                            ->body("Questões encontradas: {$batch->questions_found}. Questões importadas: {$batch->questions_imported}.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar o PDF.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('importXlsx')
                ->label('Importar XLSX')
                ->icon('heroicon-o-table-cells')
                ->requiresConfirmation()
                ->modalHeading('Substituir questões por XLSX')
                ->modalDescription('A planilha substituirá todas as questões atuais deste banco. Use colunas como numero, enunciado, alternativa_a, alternativa_b, gabarito e comentario.')
                ->form([
                    FileUpload::make('spreadsheet')
                        ->label('Planilha de questões')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->disk('local')
                        ->directory('imports/questions')
                        ->preserveFilenames()
                        ->required(),
                ])
                ->action(function (array $data, QuestionSpreadsheetImporter $importer): void {
                    $path = self::resolveUploadedSpreadsheetPath($data['spreadsheet'] ?? null);

                    if ($path === null) {
                        Notification::make()
                            ->title('Selecione uma planilha válida.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $this->record->forceFill([
                            'source_type' => 'xlsx',
                            'source_file_path' => $path,
                        ])->save();

                        $batch = $importer->import($this->record->fresh(), Storage::disk('local')->path($path), auth()->id());

                        Notification::make()
                            ->title('XLSX importado.')
                            ->body("Questões substituídas: {$batch->questions_imported}.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar o XLSX.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('linkQuestions')
                ->label('Vincular questões ao curso')
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->modalHeading('Vincular questões aos assuntos')
                ->modalDescription('O sistema tentará relacionar as questões deste banco com módulos e aulas do curso pelo assunto, título do banco e título das aulas. Você ainda poderá editar cada vínculo manualmente.')
                ->action(function (QuestionLessonLinker $linker): void {
                    $updated = $linker->linkBank($this->record);

                    Notification::make()
                        ->title('Vinculação concluída.')
                        ->body("Questões vinculadas ou atualizadas: {$updated}.")
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
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

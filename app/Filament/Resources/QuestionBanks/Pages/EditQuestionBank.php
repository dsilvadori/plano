<?php

namespace App\Filament\Resources\QuestionBanks\Pages;

use App\Filament\Resources\QuestionBanks\QuestionBankResource;
use App\Services\QuestionLessonLinker;
use App\Services\QuestionPdfImporter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
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
}

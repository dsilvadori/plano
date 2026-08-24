<?php

namespace App\Filament\Resources\QuestionBanks\Pages;

use App\Filament\Resources\QuestionBanks\QuestionBankResource;
use App\Services\QuestionPdfImporter;
use App\Services\QuestionSpreadsheetImporter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EditQuestionBank extends EditRecord
{
    protected static string $resource = QuestionBankResource::class;

    protected function afterSave(): void
    {
        QuestionBankResource::syncManualLinks($this->record, $this->data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importQuestions')
                ->label('Importar questões')
                ->icon('heroicon-o-arrow-up-tray')
                ->requiresConfirmation()
                ->modalHeading('Importar questões')
                ->modalDescription('O arquivo salvo neste banco será importado. PDF será convertido pela IA para o formato estruturado; XLSX será importado diretamente. As questões atuais serão substituídas.')
                ->action(function (QuestionPdfImporter $pdfImporter, QuestionSpreadsheetImporter $spreadsheetImporter): void {
                    if (blank($this->record->source_file_path)) {
                        Notification::make()
                            ->title('Envie um arquivo antes de importar.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $path = $this->record->source_file_path;
                    $absolutePath = Storage::disk('local')->path($path);
                    $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

                    try {
                        $batch = match ($extension) {
                            'pdf' => $pdfImporter->import($this->record->fresh(), auth()->id()),
                            'xlsx' => $spreadsheetImporter->import($this->record->fresh(), $absolutePath, auth()->id()),
                            default => throw new \RuntimeException('Envie um arquivo PDF ou XLSX.'),
                        };

                        Notification::make()
                            ->title('Questões importadas.')
                            ->body("Questões substituídas: {$batch->questions_imported}.")
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar as questões.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}

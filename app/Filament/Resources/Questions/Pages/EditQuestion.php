<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\QuestionResource;
use App\Services\GeminiQuestionCommentaryGenerator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditQuestion extends EditRecord
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateCommentary')
                ->label('Gerar comentário com IA')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalHeading('Gerar comentário da questão')
                ->modalDescription('O comentário gerado será salvo no campo Comentário e continuará editável pelo admin.')
                ->action(function (GeminiQuestionCommentaryGenerator $generator): void {
                    try {
                        $commentary = $generator->generate($this->record);

                        $this->record->forceFill([
                            'commentary' => $commentary,
                            'commentary_provider' => 'gemini'.($generator->lastModel() ? ':'.$generator->lastModel() : ''),
                        ])->save();

                        $this->refreshFormData(['commentary', 'commentary_provider']);

                        Notification::make()
                            ->title('Comentário gerado.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível gerar o comentário.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}

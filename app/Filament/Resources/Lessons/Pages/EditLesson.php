<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Services\PandaAiResourceActivator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditLesson extends EditRecord
{
    protected static string $resource = LessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activatePandaAi')
                ->label('Gerar Recursos de IA')
                ->icon('heroicon-o-sparkles')
                ->requiresConfirmation()
                ->modalHeading('Gerar recursos de IA em português')
                ->modalDescription('Se já houver uma geração em andamento, a plataforma tentará buscar o resultado. Caso contrário, os recursos atuais serão removidos e uma nova geração em português do Brasil será solicitada.')
                ->visible(fn (): bool => LessonResource::hasPandaVideo($this->record))
                ->action(function (PandaAiResourceActivator $activator): void {
                    try {
                        $result = $activator->generate($this->record);

                        Notification::make()
                            ->title($result['created_artifacts'] > 0 ? 'IA do Panda sincronizada' : 'IA do Panda solicitada em PT-BR')
                            ->body($result['created_artifacts'] > 0
                                ? "{$result['created_artifacts']} recurso(s) de IA foram salvos para esta aula."
                                : ($result['requested']
                                    ? 'Os recursos antigos foram removidos, o Panda recebeu uma nova solicitação em português do Brasil e a sincronização foi agendada.'
                                    : 'A geração já está em andamento no Panda. A plataforma tentou buscar o resultado e vai tentar novamente em background.'))
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Não foi possível ativar a IA do Panda')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}

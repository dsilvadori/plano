<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Services\ActiveStudyPlanRefresher;
use App\Services\LessonPandaVideoImporter;
use App\Services\PandaAiResourceActivator;
use App\Services\PandaTutorActivator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditLesson extends EditRecord
{
    protected static string $resource = LessonResource::class;

    protected array $courseIdsPendingPlanRefresh = [];

    protected function afterSave(): void
    {
        LessonResource::syncPrimaryCatalogLinks($this->record);

        app(ActiveStudyPlanRefresher::class)->refreshCoursesForLesson($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPandaVideo')
                ->label('Importar URL do Panda')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar vídeo do Panda para esta aula')
                ->modalDescription('Cole a URL completa ou o ID do vídeo no Panda. A aula será publicada e a mídia será vinculada a este cadastro.')
                ->form([
                    TextInput::make('panda_video_reference')
                        ->label('URL ou ID do vídeo no Panda')
                        ->placeholder('https://dashboard.pandavideo.com.br/#/videos/...')
                        ->required()
                        ->maxLength(2048),
                ])
                ->action(function (array $data, LessonPandaVideoImporter $importer): void {
                    try {
                        $lesson = $importer->importFromReference($this->record, (string) $data['panda_video_reference']);

                        app(ActiveStudyPlanRefresher::class)->refreshCoursesForLesson($lesson);

                        $this->record = $lesson;
                        $this->refreshFormData([
                            'description',
                            'type',
                            'thumbnail_url',
                            'duration_seconds',
                            'status',
                            'panda_video_id',
                            'panda_status',
                            'panda_embed_url',
                            'panda_player_url',
                            'source_status',
                        ]);

                        Notification::make()
                            ->title('Vídeo do Panda importado.')
                            ->body('A aula foi publicada e a mídia foi vinculada com sucesso.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Não foi possível importar o vídeo do Panda')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
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

                        LessonResource::notifyPandaAiResult($result);
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Não foi possível ativar a IA do Panda')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('activatePandaTutor')
                ->label('Ativar Tutor IA')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->requiresConfirmation()
                ->modalHeading('Ativar Tutor IA do Panda')
                ->modalDescription('A plataforma verificará se o Tutor IA já está disponível no Panda. Se ainda não estiver, solicitará a geração dos recursos necessários e agendará novas verificações.')
                ->visible(fn (): bool => LessonResource::hasPandaVideo($this->record))
                ->action(function (PandaTutorActivator $activator): void {
                    try {
                        $result = $activator->activate($this->record);

                        LessonResource::notifyPandaTutorResult($result);
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Não foi possível ativar o Tutor IA')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make()
                ->before(function (): void {
                    $this->courseIdsPendingPlanRefresh = app(ActiveStudyPlanRefresher::class)->courseIdsForLesson($this->record);
                })
                ->after(fn (): int => app(ActiveStudyPlanRefresher::class)->refreshCoursesByIds($this->courseIdsPendingPlanRefresh)),
        ];
    }
}

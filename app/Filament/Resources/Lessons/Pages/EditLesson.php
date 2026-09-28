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
                ->modalDescription('Cole a URL completa ou o ID do vídeo no Panda. A aula será vinculada ao vídeo e a Biblioteca de Vídeos será sincronizada.')
                ->form([
                    TextInput::make('panda_video_reference')
                        ->label('URL ou ID do vídeo no Panda')
                        ->placeholder('https://dashboard.pandavideo.com.br/#/videos/...')
                        ->required()
                        ->maxLength(2048),
                ])
                ->action(function (array $data, LessonPandaVideoImporter $importer): void {
                    try {
                        $this->record = $importer->importFromReference($this->record, (string) $data['panda_video_reference']);

                        LessonResource::syncPrimaryCatalogLinks($this->record);
                        app(ActiveStudyPlanRefresher::class)->refreshCoursesForLesson($this->record);

                        $this->refreshFormData([
                            'video_id',
                            'type',
                            'thumbnail_url',
                            'duration_seconds',
                            'duration_minutes_preview',
                            'status',
                            'panda_video_id',
                            'panda_status',
                            'panda_embed_url',
                            'panda_player_url',
                            'source_status',
                            'metadata',
                        ]);

                        Notification::make()
                            ->title('Vídeo do Panda importado.')
                            ->body('A aula foi atualizada e vinculada ao vídeo.')
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
                ->modalHeading('Gerar recursos de IA para esta aula')
                ->visible(fn (): bool => LessonResource::hasPandaVideo($this->record))
                ->action(function (PandaAiResourceActivator $activator): void {
                    try {
                        LessonResource::notifyPandaAiResult($activator->reprocess($this->record));
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Não foi possível ativar a IA do Panda')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('clearPandaAiCache')
                ->label('Limpar cache da IA')
                ->icon('heroicon-o-trash')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Limpar recursos de IA desta aula')
                ->visible(fn (): bool => LessonResource::hasPandaVideo($this->record))
                ->action(function (PandaAiResourceActivator $activator): void {
                    $deleted = $activator->clearCachedArtifacts($this->record);

                    Notification::make()
                        ->title('Cache da IA limpo')
                        ->body("{$deleted} recurso(s) de IA foram removidos.")
                        ->success()
                        ->send();
                }),
            Action::make('activatePandaTutor')
                ->label('Ativar Tutor IA')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->requiresConfirmation()
                ->modalHeading('Ativar Tutor IA para esta aula')
                ->visible(fn (): bool => LessonResource::hasPandaVideo($this->record))
                ->action(function (PandaTutorActivator $activator): void {
                    try {
                        LessonResource::notifyPandaTutorResult($activator->activate($this->record));
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

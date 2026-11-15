<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\PandaVideoUploadForm;
use App\Services\LessonDirectVideoUploader;
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

    protected array $courseIdsPendingCacheForget = [];

    protected function afterSave(): void
    {
        LessonResource::syncPrimaryCatalogLinks($this->record);

        LessonResource::forgetCatalogCachesForLesson($this->record);
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
                        LessonResource::forgetCatalogCachesForLesson($this->record);

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
            Action::make('uploadPandaVideo')
                ->label('Enviar vídeo ao Panda')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Enviar vídeo desta aula ao Panda')
                ->modalDescription(fn (): string => trim(
                    'O arquivo é enviado ao Panda em segundo plano. Quando o Panda terminar de processar, a aula e a Biblioteca de Vídeos são atualizadas automaticamente. '
                    .(LessonDirectVideoUploader::lessonHasPandaMedia($this->record) ? 'O vídeo atual continua no ar até o novo ser aceito pelo Panda. ' : '')
                    .(PandaVideoUploadForm::statusDescription($this->record) ?? '')
                ))
                ->modalSubmitActionLabel('Enviar ao Panda')
                ->form(fn (): array => PandaVideoUploadForm::fields($this->record))
                ->action(function (array $data, LessonDirectVideoUploader $uploader): void {
                    $path = PandaVideoUploadForm::storedPath($data);

                    if ($path === null) {
                        Notification::make()
                            ->title('Selecione um arquivo de vídeo.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $this->record = $uploader->queue(
                            $this->record,
                            $path,
                            PandaVideoUploadForm::originalName($data),
                            (string) ($data['panda_folder_reference'] ?? ''),
                            (string) ($data['panda_title'] ?? ''),
                            (bool) ($data['create_panda_folder'] ?? false),
                        );

                        $this->refreshFormData([
                            'type',
                            'thumbnail_url',
                            'duration_seconds',
                            'duration_minutes_preview',
                            'panda_video_id',
                            'panda_status',
                            'panda_embed_url',
                            'panda_player_url',
                            'source_status',
                            'metadata',
                        ]);

                        Notification::make()
                            ->title('Vídeo recebido.')
                            ->body('O envio ao Panda segue em segundo plano. Acompanhe pelo status de origem da aula.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Não foi possível enviar o vídeo ao Panda')
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
                    $this->courseIdsPendingCacheForget = LessonResource::courseIdsForLesson($this->record)->all();
                })
                ->after(function (): void {
                    foreach ($this->courseIdsPendingCacheForget as $courseId) {
                        \App\Http\Controllers\CourseCatalogController::forgetCourseCatalogCache((int) $courseId);
                    }
                }),
        ];
    }
}

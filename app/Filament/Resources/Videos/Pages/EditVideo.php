<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Resources\Videos\VideoResource;
use App\Services\PandaVideoClient;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;
use Throwable;

class EditVideo extends EditRecord
{
    protected static string $resource = VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPandaVideo')
                ->label('Importar URL do Panda')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar vídeo do Panda')
                ->modalDescription('Cole a URL completa ou o ID do vídeo no Panda. A Biblioteca de Vídeos será atualizada e as aulas vinculadas receberão os campos legados de compatibilidade.')
                ->form([
                    TextInput::make('panda_video_reference')
                        ->label('URL ou ID do vídeo no Panda')
                        ->placeholder('https://dashboard.pandavideo.com.br/#/videos/...')
                        ->required()
                        ->maxLength(2048),
                ])
                ->action(function (array $data, PandaVideoClient $client): void {
                    try {
                        $videoId = $client->resolveVideoReference((string) $data['panda_video_reference']);
                        $payload = $client->video($videoId);

                        if (! $payload) {
                            throw new \RuntimeException('Não encontrei esse vídeo no Panda.');
                        }

                        if ($client->videoIsFailed($payload)) {
                            throw new \RuntimeException('O vídeo está com falha no Panda e não pode ser importado.');
                        }

                        $metadata = is_array($this->record->metadata) ? $this->record->metadata : [];
                        $durationSeconds = (int) ($payload['duration_seconds'] ?? 0);
                        $title = (string) ($payload['title'] ?? $this->record->title);

                        $this->record->forceFill([
                            'title' => filled($this->record->title) ? $this->record->title : $title,
                            'slug' => filled($this->record->slug) ? $this->record->slug : (Str::slug($title) ?: 'video-panda-'.$videoId),
                            'description' => filled($this->record->description)
                                ? $this->record->description
                                : ((string) ($payload['description'] ?? '') ?: null),
                            'provider' => 'panda',
                            'provider_video_id' => $payload['panda_video_id'],
                            'provider_status' => $payload['panda_status'],
                            'embed_url' => $payload['panda_embed_url'],
                            'player_url' => $payload['panda_player_url'],
                            'thumbnail_url' => filled($payload['thumbnail_url'] ?? null)
                                ? $payload['thumbnail_url']
                                : $this->record->thumbnail_url,
                            'duration_seconds' => $durationSeconds > 0
                                ? $durationSeconds
                                : (int) $this->record->duration_seconds,
                            'source_status' => $client->videoIsReady($payload) ? 'media_ready' : 'panda_processing',
                            'metadata' => array_replace_recursive($metadata, [
                                'source' => 'panda',
                                'panda_direct_import_reference' => (string) $data['panda_video_reference'],
                                'panda_direct_imported_at' => now()->toIso8601String(),
                                'folder_id' => $payload['folder_id'] ?? ($metadata['folder_id'] ?? null),
                                'payload' => $payload['payload'],
                            ]),
                        ])->save();

                        VideoResource::syncLinkedLessonsFromVideo($this->record);
                        $this->refreshFormData([
                            'title',
                            'slug',
                            'description',
                            'provider',
                            'provider_video_id',
                            'provider_status',
                            'embed_url',
                            'player_url',
                            'thumbnail_url',
                            'duration_seconds',
                            'duration_minutes_preview',
                            'source_status',
                            'metadata',
                        ]);

                        Notification::make()
                            ->title('Vídeo do Panda importado.')
                            ->body('A Biblioteca de Vídeos foi atualizada com sucesso.')
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
            DeleteAction::make(),
        ];
    }
}

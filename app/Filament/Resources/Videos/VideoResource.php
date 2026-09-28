<?php

namespace App\Filament\Resources\Videos;

use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Videos\Pages\CreateVideo;
use App\Filament\Resources\Videos\Pages\EditVideo;
use App\Filament\Resources\Videos\Pages\ListVideos;
use App\Models\Lesson;
use App\Models\LessonFolder;
use App\Models\Video;
use App\Services\PandaAiResourceActivator;
use App\Services\PandaTutorActivator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

class VideoResource extends Resource
{
    protected static ?string $model = Video::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Vídeo';

    protected static ?string $pluralModelLabel = 'Biblioteca de Vídeos';

    protected static ?int $navigationSort = 42;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('lesson_folder_id')
                ->label('Pasta da biblioteca')
                ->options(fn (): array => self::lessonFolderOptions())
                ->searchable()
                ->preload()
                ->columnSpanFull(),
            TextInput::make('title')
                ->label('Título')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')
                ->label('Slug')
                ->required(),
            Select::make('provider')
                ->label('Provedor')
                ->options([
                    'panda' => 'Panda Video',
                    'drive' => 'Google Drive',
                    'manual' => 'Manual',
                ])
                ->default('panda')
                ->required(),
            TextInput::make('provider_video_id')
                ->label('ID no provedor')
                ->helperText('Para Panda, este é o ID técnico usado para sincronizar status, player e IA.')
                ->maxLength(255),
            Select::make('source_status')
                ->label('Status da mídia')
                ->options([
                    'structure_only' => 'Somente estrutura',
                    'awaiting_media' => 'Mídia pendente',
                    'upload_queued' => 'Upload na fila',
                    'uploading' => 'Enviando ao Panda',
                    'panda_processing' => 'Processando no Panda',
                    'upload_failed' => 'Falha no upload',
                    'media_ready' => 'Mídia pronta',
                    'published' => 'Publicado',
                ])
                ->default('awaiting_media')
                ->required(),
            TextInput::make('provider_status')
                ->label('Status no provedor')
                ->maxLength(255),
            TextInput::make('embed_url')
                ->label('URL de embed')
                ->url()
                ->maxLength(2048),
            TextInput::make('player_url')
                ->label('URL do player')
                ->url()
                ->maxLength(2048),
            TextInput::make('thumbnail_url')
                ->label('URL da thumbnail')
                ->url()
                ->maxLength(2048),
            TextInput::make('duration_seconds')
                ->label('Duração em segundos')
                ->numeric()
                ->default(0)
                ->required()
                ->suffix('s')
                ->live(onBlur: true)
                ->afterStateHydrated(fn ($state, Set $set) => $set('duration_minutes_preview', self::durationSecondsToMinutes($state)))
                ->afterStateUpdated(fn ($state, Set $set) => $set('duration_minutes_preview', self::durationSecondsToMinutes($state))),
            TextInput::make('duration_minutes_preview')
                ->label('Duração em minutos')
                ->numeric()
                ->disabled()
                ->dehydrated(false)
                ->suffix('min'),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(4)
                ->columnSpanFull(),
            KeyValue::make('metadata')
                ->label('Metadados')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['folder'])->withCount(['lessons', 'aiArtifacts']))
            ->columns([
                TextColumn::make('title')
                    ->label('Vídeo')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('folder.path')
                    ->label('Pasta')
                    ->formatStateUsing(fn (?string $state): string => $state ? str_replace('/', ' / ', $state) : 'Sem pasta')
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('folder', fn ($query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('path', 'like', "%{$search}%")))
                    ->toggleable(),
                TextColumn::make('provider')
                    ->label('Provedor')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'panda' => 'Panda',
                        'drive' => 'Drive',
                        'manual' => 'Manual',
                        default => (string) $state,
                    })
                    ->toggleable(),
                TextColumn::make('source_status')
                    ->label('Mídia')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::sourceStatusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'media_ready', 'published' => 'success',
                        'panda_processing', 'uploading', 'upload_queued' => 'warning',
                        'upload_failed' => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('provider_status')
                    ->label('Status provedor')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('duration_minutes')
                    ->label('Min')
                    ->getStateUsing(fn (Video $record): int => (int) ceil(((int) $record->duration_seconds) / 60))
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('duration_seconds', $direction))
                    ->toggleable(),
                TextColumn::make('lessons_count')
                    ->label('Aulas')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ai_artifacts_count')
                    ->label('IA')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (int|string|null $state): string => ((int) $state) > 0 ? (string) $state : 'Sem IA')
                    ->color(fn (int|string|null $state): string => ((int) $state) > 0 ? 'success' : 'gray')
                    ->toggleable(),
                TextColumn::make('tutor_status_flag')
                    ->label('Tutor')
                    ->badge()
                    ->toggleable()
                    ->getStateUsing(fn (Video $record): string => self::tutorStatusFlag($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Aula vinculada' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('provider_video_id')
                    ->label('ID do provedor')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('lesson_folder_id')
                    ->label('Pasta da biblioteca')
                    ->options(fn (): array => self::lessonFolderOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('provider')
                    ->label('Provedor')
                    ->options([
                        'panda' => 'Panda Video',
                        'drive' => 'Google Drive',
                        'manual' => 'Manual',
                    ]),
                SelectFilter::make('source_status')
                    ->label('Status da mídia')
                    ->options([
                        'awaiting_media' => 'Mídia pendente',
                        'upload_queued' => 'Upload na fila',
                        'uploading' => 'Enviando ao Panda',
                        'panda_processing' => 'Processando no Panda',
                        'upload_failed' => 'Falha no upload',
                        'media_ready' => 'Mídia pronta',
                        'published' => 'Publicado',
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                Action::make('activatePandaAi')
                    ->label('Gerar Recursos de IA')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalHeading('Gerar recursos de IA para este vídeo')
                    ->modalDescription('A operação usa a mídia canônica do vídeo. Se houver aula vinculada, os recursos também continuam compatíveis com a área do aluno.')
                    ->visible(fn (Video $record): bool => self::hasPandaVideo($record))
                    ->action(function (Video $record, PandaAiResourceActivator $activator): void {
                        $lesson = self::lessonForPandaAction($record);

                        if (! $lesson) {
                            self::notifyMissingLessonForVideo();

                            return;
                        }

                        try {
                            LessonResource::notifyPandaAiResult($activator->reprocess($lesson));
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
                    ->modalHeading('Limpar recursos de IA deste vídeo')
                    ->modalDescription('Remove os recursos de IA Panda salvos para o vídeo e para a aula vinculada usada como compatibilidade.')
                    ->visible(fn (Video $record): bool => self::hasPandaVideo($record))
                    ->action(function (Video $record, PandaAiResourceActivator $activator): void {
                        $lesson = self::lessonForPandaAction($record);

                        if (! $lesson) {
                            self::notifyMissingLessonForVideo();

                            return;
                        }

                        $deleted = $activator->clearCachedArtifacts($lesson);

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
                    ->modalHeading('Ativar Tutor IA para este vídeo')
                    ->modalDescription('A plataforma verificará se o Tutor IA já está disponível no Panda. Se ainda não estiver, solicitará a geração dos recursos necessários.')
                    ->visible(fn (Video $record): bool => self::hasPandaVideo($record))
                    ->action(function (Video $record, PandaTutorActivator $activator): void {
                        $lesson = self::lessonForPandaAction($record);

                        if (! $lesson) {
                            self::notifyMissingLessonForVideo();

                            return;
                        }

                        try {
                            LessonResource::notifyPandaTutorResult($activator->activate($lesson));
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('Não foi possível ativar o Tutor IA')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('openLesson')
                    ->label('Abrir aula')
                    ->icon('heroicon-o-academic-cap')
                    ->visible(fn (Video $record): bool => $record->lessons()->exists())
                    ->url(fn (Video $record): string => LessonResource::getUrl('edit', ['record' => $record->lessons()->orderBy('title')->first()]))
                    ->openUrlInNewTab(),
                EditAction::make()->label('Editar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activatePandaAi')
                        ->label('Gerar Recursos de IA')
                        ->icon('heroicon-o-sparkles')
                        ->requiresConfirmation()
                        ->modalHeading('Gerar recursos de IA para os vídeos selecionados')
                        ->action(function (Collection $records, PandaAiResourceActivator $activator): void {
                            [$requested, $pending, $syncedArtifacts, $alreadyReady, $skipped, $failed] = [0, 0, 0, 0, 0, 0];

                            foreach ($records as $record) {
                                $lesson = self::lessonForPandaAction($record);

                                if (! $lesson || ! self::hasPandaVideo($record)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $result = $activator->reprocess($lesson);
                                    $requested += $result['requested'] ? 1 : 0;
                                    $pending += $result['pending'] ? 1 : 0;
                                    $syncedArtifacts += (int) $result['created_artifacts'];
                                    $alreadyReady += (! $result['requested'] && ! $result['pending'] && (int) $result['created_artifacts'] === 0) ? 1 : 0;
                                } catch (Throwable $exception) {
                                    report($exception);
                                    $failed++;
                                }
                            }

                            $notification = Notification::make()
                                ->title('Geração de IA Panda concluída')
                                ->body("Solicitadas: {$requested}. Aguardando Panda: {$pending}. Já disponíveis: {$alreadyReady}. Artefatos sincronizados: {$syncedArtifacts}. Ignorados sem Panda/aula: {$skipped}. Falhas: {$failed}.");

                            ($failed > 0 ? $notification->warning() : $notification->success())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('clearPandaAiCache')
                        ->label('Limpar cache da IA')
                        ->icon('heroicon-o-trash')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Limpar recursos de IA dos vídeos selecionados')
                        ->action(function (Collection $records, PandaAiResourceActivator $activator): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                $lesson = self::lessonForPandaAction($record);

                                if (! $lesson || ! self::hasPandaVideo($record)) {
                                    $skipped++;

                                    continue;
                                }

                                $deleted += $activator->clearCachedArtifacts($lesson);
                            }

                            Notification::make()
                                ->title('Cache da IA limpo')
                                ->body("Recursos removidos: {$deleted}. Ignorados sem Panda/aula: {$skipped}.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activatePandaTutor')
                        ->label('Ativar Tutor IA')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->requiresConfirmation()
                        ->modalHeading('Ativar Tutor IA para os vídeos selecionados')
                        ->action(function (Collection $records, PandaTutorActivator $activator): void {
                            [$activated, $requested, $skipped, $failed] = [0, 0, 0, 0];

                            foreach ($records as $record) {
                                $lesson = self::lessonForPandaAction($record);

                                if (! $lesson || ! self::hasPandaVideo($record)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $result = $activator->activate($lesson);
                                    $activated += $result['available'] ? 1 : 0;
                                    $requested += $result['requested'] ? 1 : 0;
                                } catch (Throwable $exception) {
                                    report($exception);
                                    $failed++;
                                }
                            }

                            $notification = Notification::make()
                                ->title('Ativação do Tutor IA concluída')
                                ->body("Ativadas: {$activated}. Solicitadas: {$requested}. Ignorados sem Panda/aula: {$skipped}. Falhas: {$failed}.");

                            ($failed > 0 ? $notification->warning() : $notification->success())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVideos::route('/'),
            'create' => CreateVideo::route('/create'),
            'edit' => EditVideo::route('/{record}/edit'),
        ];
    }

    public static function lessonFolderOptions(): array
    {
        return LessonFolder::query()
            ->orderBy('path')
            ->get(['id', 'path'])
            ->mapWithKeys(fn (LessonFolder $folder): array => [
                $folder->id => str_replace('/', ' / ', $folder->path),
            ])
            ->all();
    }

    public static function durationSecondsToMinutes(mixed $seconds): int
    {
        return (int) ceil(max(0, (int) $seconds) / 60);
    }

    public static function hasPandaVideo(Video $video): bool
    {
        return $video->provider === 'panda'
            && (
                filled($video->provider_video_id)
                || filled($video->embed_url)
                || filled($video->player_url)
            );
    }

    public static function lessonForPandaAction(Video $video): ?Lesson
    {
        return $video->lessons()
            ->orderByRaw("case when status = 'published' then 0 else 1 end")
            ->orderBy('id')
            ->first();
    }

    public static function tutorStatusFlag(Video $video): string
    {
        return ((int) ($video->lessons_count ?? 0)) > 0 ? 'Aula vinculada' : 'Sem aula';
    }

    public static function syncLinkedLessonsFromVideo(Video $video): void
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];

        $video->lessons()->get()->each(function (Lesson $lesson) use ($video, $metadata): void {
            $lessonMetadata = is_array($lesson->metadata) ? $lesson->metadata : [];
            $lesson->forceFill([
                'type' => 'video',
                'thumbnail_url' => $video->thumbnail_url ?: $lesson->thumbnail_url,
                'duration_seconds' => (int) ($video->duration_seconds ?: $lesson->duration_seconds),
                'panda_video_id' => $video->provider_video_id,
                'panda_status' => $video->provider_status,
                'panda_embed_url' => $video->embed_url,
                'panda_player_url' => $video->player_url,
                'source_status' => $video->source_status,
                'metadata' => array_replace_recursive($lessonMetadata, [
                    'source' => 'panda',
                    'payload' => data_get($metadata, 'payload', data_get($lessonMetadata, 'payload')),
                    'synced_from_video_id' => $video->id,
                    'synced_from_video_at' => now()->toIso8601String(),
                ]),
            ])->save();
        });
    }

    protected static function notifyMissingLessonForVideo(): void
    {
        Notification::make()
            ->title('Vídeo sem aula vinculada')
            ->body('Vincule este vídeo a uma aula antes de gerar IA ou ativar Tutor. A área do aluno continua usando aulas como unidade pedagógica.')
            ->warning()
            ->send();
    }

    public static function sourceStatusLabel(?string $state): string
    {
        return match ($state) {
            'structure_only' => 'Somente estrutura',
            'awaiting_media' => 'Mídia pendente',
            'upload_queued' => 'Upload na fila',
            'uploading' => 'Enviando ao Panda',
            'panda_processing' => 'Processando no Panda',
            'upload_failed' => 'Falha no upload',
            'media_ready' => 'Mídia pronta',
            'published' => 'Publicado',
            default => (string) $state,
        };
    }
}

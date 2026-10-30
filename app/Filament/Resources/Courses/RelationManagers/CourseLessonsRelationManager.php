<?php

namespace App\Filament\Resources\Courses\RelationManagers;

use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CourseLessonsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessons';

    protected static ?string $title = 'Aulas vinculadas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getOwnerRecord()->linkedLessonsQuery()->with('video'))
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')->label('Aula')->searchable()->sortable(),
                TextColumn::make('module_name')
                    ->label('Módulo')
                    ->getStateUsing(fn (Lesson $record): string => $record->module?->name ?: $record->modules->pluck('name')->join(', ') ?: '-')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->whereHas('module', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('modules', fn (Builder $query) => $query->where('course_modules.name', 'like', "%{$search}%"))),
                TextColumn::make('track_name')
                    ->label('Trilha')
                    ->getStateUsing(fn (Lesson $record): string => $record->track?->name ?: $record->tracks->pluck('name')->join(', ') ?: '-')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->whereHas('track', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('tracks', fn (Builder $query) => $query->where('course_module_tracks.name', 'like', "%{$search}%"))),
                TextColumn::make('source_status')
                    ->label('Mídia')
                    ->badge()
                    ->getStateUsing(fn (Lesson $record): string => self::mediaStateForLesson($record))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'structure_only' => 'Somente estrutura',
                        'awaiting_media' => 'Mídia pendente',
                        'upload_queued' => 'Upload na fila',
                        'uploading' => 'Enviando ao Panda',
                        'panda_processing' => 'Processando no Panda',
                        'upload_failed' => 'Falha no upload',
                        'media_ready' => 'Mídia pronta',
                        'published' => 'Publicado',
                        default => filled($state) ? (string) $state : 'Sem mídia',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'media_ready', 'published' => 'success',
                        'upload_queued', 'uploading', 'panda_processing' => 'warning',
                        'upload_failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                        default => $state,
                    }),
                TextColumn::make('duration_minutes')->label('Min')->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('duration_seconds', $direction)),
                TextColumn::make('panda_video_id')->label('ID Panda')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source_status')
                    ->label('Mídia')
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
                    ->query(fn (Builder $query, array $data): Builder => self::applyMediaFilter($query, (string) ($data['value'] ?? ''))),
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                    ]),
            ])
            ->recordActions([
                Action::make('openLesson')
                    ->label('Abrir aula')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Lesson $record): string => LessonResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
            ]);
    }

    protected static function applyMediaFilter(Builder $query, string $state): Builder
    {
        return match ($state) {
            'awaiting_media' => $query->where(function (Builder $query): void {
                self::whereMissingMedia($query)
                    ->where(function (Builder $query): void {
                        $query
                            ->whereNull('lessons.source_status')
                            ->orWhereIn('lessons.source_status', ['', 'awaiting_media', 'structure_only']);
                    });
            }),
            'media_ready' => $query->where(fn (Builder $query) => self::whereHasReadyMedia($query)),
            'published' => $query->where('lessons.source_status', 'published'),
            'upload_queued', 'uploading', 'panda_processing', 'upload_failed', 'structure_only' => $query->where('lessons.source_status', $state),
            default => $query,
        };
    }

    protected static function mediaStateForLesson(Lesson $lesson): string
    {
        if (self::lessonHasReadyMedia($lesson)) {
            return 'media_ready';
        }

        $state = (string) $lesson->source_status;

        return $state !== '' ? $state : 'awaiting_media';
    }

    protected static function lessonHasReadyMedia(Lesson $lesson): bool
    {
        $lesson->loadMissing('video');

        return filled($lesson->panda_video_id)
            || filled($lesson->panda_embed_url)
            || filled($lesson->panda_player_url)
            || $lesson->source_status === 'media_ready'
            || filled($lesson->video?->provider_video_id)
            || filled($lesson->video?->embed_url)
            || filled($lesson->video?->player_url)
            || $lesson->video?->source_status === 'media_ready';
    }

    protected static function whereHasReadyMedia(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('lessons.source_status', 'media_ready')
                ->orWhereNotNull('lessons.panda_video_id')
                ->orWhereNotNull('lessons.panda_embed_url')
                ->orWhereNotNull('lessons.panda_player_url')
                ->orWhereHas('video', function (Builder $query): void {
                    $query
                        ->where('source_status', 'media_ready')
                        ->orWhereNotNull('provider_video_id')
                        ->orWhereNotNull('embed_url')
                        ->orWhereNotNull('player_url');
                });
        });
    }

    protected static function whereMissingMedia(Builder $query): Builder
    {
        return $query
            ->whereNull('lessons.panda_video_id')
            ->whereNull('lessons.panda_embed_url')
            ->whereNull('lessons.panda_player_url')
            ->whereDoesntHave('video', function (Builder $query): void {
                $query
                    ->where('source_status', 'media_ready')
                    ->orWhereNotNull('provider_video_id')
                    ->orWhereNotNull('embed_url')
                    ->orWhereNotNull('player_url');
            });
    }
}

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
            ->query(fn (): Builder => $this->getOwnerRecord()->linkedLessonsQuery())
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
                    ]),
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
}

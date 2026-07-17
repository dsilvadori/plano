<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Models\Course;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Curso')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                        default => $state,
                    }),
                TextColumn::make('sphere.name')->label('Esfera')->sortable()->toggleable(),
                TextColumn::make('educationLevel.name')->label('Escolaridade')->sortable()->toggleable(),
                TextColumn::make('tutory_product_id')->label('Tutory ID')->searchable()->toggleable(),
                TextColumn::make('combo_name')->label('Combo')->searchable()->toggleable(),
                TextColumn::make('modules_count')->label('Módulos')->counts('modules'),
                TextColumn::make('lessons_count')->label('Aulas')->counts('lessons'),
                TextColumn::make('linked_lessons_count')
                    ->label('Aulas vinculadas')
                    ->getStateUsing(fn (Course $record): int => $record->linkedLessonsCount())
                    ->sortable(false),
                TextColumn::make('linked_media_lessons_count')
                    ->label('Aulas com mídia')
                    ->getStateUsing(fn (Course $record): int => $record->linkedMediaLessonsCount())
                    ->sortable(false),
                TextColumn::make('study_tracks_count')->label('Trilhas')->counts('studyTracks'),
                IconColumn::make('is_featured')->label('Destaque')->boolean(),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\CourseModules\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseModulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.name')->label('Curso')->searchable()->sortable(),
                TextColumn::make('name')->label('Módulo')->searchable()->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'basic' => 'Matéria Básica',
                        'specific' => 'Conhecimentos Específicos',
                        'complementary' => 'Conhecimentos Complementares',
                        'review' => 'Revisão',
                        'questions' => 'Questões',
                        'other' => 'Outro/Legado',
                        default => 'Outro',
                    }),
                TextColumn::make('lessons_count')
                    ->label('Aulas da trilha')
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('workload_minutes', $direction)),
                TextColumn::make('tracks_count')->label('Trilhas')->counts('tracks'),
                TextColumn::make('online_lessons_count')->label('Aulas online')->counts('onlineLessons'),
                TextColumn::make('workload_minutes')->label('Minutos')->sortable(),
                TextColumn::make('sort_order')->label('Ordem')->sortable(),
                TextColumn::make('panda_folder_id')->label('Pasta do provedor')->toggleable(),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'basic' => 'Básica',
                        'specific' => 'Específica',
                        'complementary' => 'Complementar',
                        'review' => 'Revisão',
                        'questions' => 'Questões',
                        'other' => 'Outro/Legado',
                    ]),
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

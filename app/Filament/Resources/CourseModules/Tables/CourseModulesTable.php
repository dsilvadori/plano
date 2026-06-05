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
                        'review' => 'Revisão',
                        'questions' => 'Questões',
                        default => 'Outro',
                    }),
                TextColumn::make('workload_minutes')->label('Minutos')->sortable(),
                TextColumn::make('sort_order')->label('Ordem')->sortable(),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'basic' => 'Básica',
                        'specific' => 'Específica',
                        'review' => 'Revisão',
                        'questions' => 'Questões',
                        'other' => 'Outro',
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

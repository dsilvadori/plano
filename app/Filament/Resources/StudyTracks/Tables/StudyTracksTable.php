<?php

namespace App\Filament\Resources\StudyTracks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StudyTracksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.name')->label('Curso')->searchable()->sortable(),
                TextColumn::make('name')->label('Trilha')->searchable()->sortable(),
                TextColumn::make('modules_count')->label('Módulos')->counts('modules'),
                IconColumn::make('is_active')->label('Ativa')->boolean(),
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

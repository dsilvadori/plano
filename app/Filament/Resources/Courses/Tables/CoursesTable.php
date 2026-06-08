<?php

namespace App\Filament\Resources\Courses\Tables;

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
                TextColumn::make('tutory_product_id')->label('Tutory ID')->searchable()->toggleable(),
                TextColumn::make('combo_name')->label('Combo')->searchable()->toggleable(),
                TextColumn::make('modules_count')->label('Módulos')->counts('modules'),
                TextColumn::make('study_tracks_count')->label('Trilhas')->counts('studyTracks'),
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

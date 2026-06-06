<?php

namespace App\Filament\Resources\Courses\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseModulesRelationManager extends RelationManager
{
    protected static string $relationship = 'modules';

    protected static ?string $title = 'Módulos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required(),
            Select::make('type')
                ->label('Tipo')
                ->options([
                    'basic' => 'Matéria Básica',
                    'specific' => 'Conhecimentos Específicos',
                    'complementary' => 'Conhecimentos Complementares',
                    'review' => 'Revisão',
                    'questions' => 'Questões',
                    'other' => 'Outro/Legado',
                ])
                ->required(),
            TextInput::make('workload_minutes')
                ->label('Carga horária (minutos)')
                ->numeric()
                ->required(),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0)
                ->required(),
            Toggle::make('is_active')
                ->label('Ativo')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
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
                TextColumn::make('workload_minutes')->label('Minutos')->sortable(),
                TextColumn::make('sort_order')->label('Ordem')->sortable(),
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
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $data + [
                        'course_id' => $this->getOwnerRecord()->getKey(),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}

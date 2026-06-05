<?php

namespace App\Filament\Resources\StudyPlans\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StudyPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Plano')->searchable(),
                TextColumn::make('user.name')->label('Aluno')->searchable()->sortable(),
                TextColumn::make('course.name')->label('Curso')->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Rascunho',
                        'active' => 'Ativo',
                        'completed' => 'Concluído',
                        'archived' => 'Arquivado',
                        default => $state,
                    }),
                TextColumn::make('viability_status')
                    ->label('Viabilidade')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'good' => 'Boa',
                        'warning' => 'Atenção',
                        'critical' => 'Crítica',
                        default => $state,
                    }),
                TextColumn::make('generated_at')->label('Gerado em')->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Rascunho',
                        'active' => 'Ativo',
                        'completed' => 'Concluído',
                        'archived' => 'Arquivado',
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

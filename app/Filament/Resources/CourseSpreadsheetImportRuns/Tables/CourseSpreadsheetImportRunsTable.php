<?php

namespace App\Filament\Resources\CourseSpreadsheetImportRuns\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseSpreadsheetImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Criada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->sortable(),
                TextColumn::make('course.name')->label('Curso destino')->placeholder('Novo curso')->searchable()->sortable(),
                TextColumn::make('course_name')->label('Curso na planilha')->searchable()->sortable(),
                TextColumn::make('file_name')->label('Arquivo')->limit(32)->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'queued' => 'gray',
                        'running' => 'warning',
                        'finished' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'queued' => 'Na fila',
                        'running' => 'Rodando',
                        'finished' => 'Concluída',
                        'failed' => 'Falhou',
                        default => $state,
                    }),
                TextColumn::make('progress_label')->label('Progresso'),
                TextColumn::make('duration_label')->label('Duração'),
                TextColumn::make('total_modules')->label('Módulos')->sortable(),
                TextColumn::make('processed_modules')->label('Processados')->sortable(),
                TextColumn::make('total_tracks')->label('Trilhas')->sortable(),
                TextColumn::make('total_lessons')->label('Aulas')->sortable(),
                TextColumn::make('latest_message')->label('Última atualização')->limit(50),
                TextColumn::make('updated_at')->label('Atualizada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'queued' => 'Na fila',
                        'running' => 'Rodando',
                        'finished' => 'Concluída',
                        'failed' => 'Falhou',
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

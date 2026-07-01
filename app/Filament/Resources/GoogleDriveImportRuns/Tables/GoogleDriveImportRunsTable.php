<?php

namespace App\Filament\Resources\GoogleDriveImportRuns\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GoogleDriveImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Criada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->sortable(),
                TextColumn::make('course.name')->label('Curso')->searchable()->sortable(),
                TextColumn::make('module.name')->label('Módulo')->searchable()->sortable(),
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
                TextColumn::make('latest_message')->label('Última atualização')->limit(50),
                TextColumn::make('panda_videos_uploaded')->label('Enviados')->sortable(),
                TextColumn::make('panda_videos_failed')->label('Erros')->sortable(),
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

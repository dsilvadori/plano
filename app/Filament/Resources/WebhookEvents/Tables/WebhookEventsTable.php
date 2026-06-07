<?php

namespace App\Filament\Resources\WebhookEvents\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WebhookEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label('Provedor')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'tutory' => 'Tutory',
                        default => str($state)->headline()->toString(),
                    }),
                TextColumn::make('event_id')->label('ID do evento')->searchable(),
                TextColumn::make('event_type')->label('Tipo')->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'received' => 'Recebido',
                        'processed' => 'Processado',
                        'ignored' => 'Ignorado',
                        'failed' => 'Falhou',
                        default => $state,
                    }),
                TextColumn::make('processed_at')->label('Processado em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo'),
                TextColumn::make('created_at')->label('Recebido em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'received' => 'Recebido',
                        'processed' => 'Processado',
                        'ignored' => 'Ignorado',
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

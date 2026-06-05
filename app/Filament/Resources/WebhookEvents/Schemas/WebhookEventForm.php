<?php

namespace App\Filament\Resources\WebhookEvents\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class WebhookEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('provider')
                    ->label('Provedor')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'tutory' => 'Tutory',
                        null => '',
                        default => str($state)->headline()->toString(),
                    })
                    ->disabled(),
                TextInput::make('event_id')->label('ID do evento')->disabled(),
                TextInput::make('event_type')->label('Tipo do evento')->disabled(),
                TextInput::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'received' => 'Recebido',
                        'processed' => 'Processado',
                        'ignored' => 'Ignorado',
                        'failed' => 'Falhou',
                        null => '',
                        default => $state,
                    })
                    ->disabled(),
                KeyValue::make('payload')->label('Payload')->disabled()->columnSpanFull(),
                Textarea::make('error_message')->label('Erro')->disabled()->columnSpanFull(),
                DateTimePicker::make('processed_at')->label('Processado em')->disabled(),
            ]);
    }
}

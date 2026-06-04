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
                TextInput::make('provider')->disabled(),
                TextInput::make('event_id')->disabled(),
                TextInput::make('event_type')->disabled(),
                TextInput::make('status')->disabled(),
                KeyValue::make('payload')->label('Payload')->disabled()->columnSpanFull(),
                Textarea::make('error_message')->label('Erro')->disabled()->columnSpanFull(),
                DateTimePicker::make('processed_at')->label('Processado em')->disabled(),
            ]);
    }
}

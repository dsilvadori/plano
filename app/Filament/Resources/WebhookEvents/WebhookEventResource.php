<?php

namespace App\Filament\Resources\WebhookEvents;

use App\Filament\Resources\WebhookEvents\Pages\CreateWebhookEvent;
use App\Filament\Resources\WebhookEvents\Pages\EditWebhookEvent;
use App\Filament\Resources\WebhookEvents\Pages\ListWebhookEvents;
use App\Filament\Resources\WebhookEvents\Schemas\WebhookEventForm;
use App\Filament\Resources\WebhookEvents\Tables\WebhookEventsTable;
use App\Models\WebhookEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WebhookEventResource extends Resource
{
    protected static ?string $model = WebhookEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Operação';

    protected static ?string $modelLabel = 'Webhook';

    protected static ?string $pluralModelLabel = 'Webhooks';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return WebhookEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebhookEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEvents::route('/'),
            'create' => CreateWebhookEvent::route('/create'),
            'edit' => EditWebhookEvent::route('/{record}/edit'),
        ];
    }
}

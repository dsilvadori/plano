<?php

namespace App\Filament\Resources\WebhookEvents\Pages;

use App\Filament\Resources\WebhookEvents\WebhookEventResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWebhookEvent extends EditRecord
{
    protected static string $resource = WebhookEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

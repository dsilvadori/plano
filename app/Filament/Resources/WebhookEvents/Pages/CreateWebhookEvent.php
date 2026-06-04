<?php

namespace App\Filament\Resources\WebhookEvents\Pages;

use App\Filament\Resources\WebhookEvents\WebhookEventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhookEvent extends CreateRecord
{
    protected static string $resource = WebhookEventResource::class;
}

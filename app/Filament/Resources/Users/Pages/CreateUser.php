<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = filled($data['password'] ?? null)
            ? $data['password']
            : Str::password(24);

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! ($this->record->isStudent() || $this->record->isSubscriber())) {
            return;
        }

        $token = Password::broker()->createToken($this->record);
        $this->record->sendSetPasswordNotification($token);

        Notification::make()
            ->title('E-mail de primeiro acesso enviado.')
            ->success()
            ->send();
    }
}

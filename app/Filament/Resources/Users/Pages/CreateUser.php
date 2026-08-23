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
        $this->syncManualCourses();

        if (! ($this->record->isStudent() || $this->record->isSubscriber())) {
            return;
        }

        $token = Password::broker('first_access')->createToken($this->record);
        $this->record->sendSetPasswordNotification($token);

        Notification::make()
            ->title('E-mail de primeiro acesso enviado.')
            ->success()
            ->send();
    }

    protected function syncManualCourses(): void
    {
        $courseIds = collect($this->data['course_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($courseIds->isEmpty()) {
            return;
        }

        $this->record->courses()->syncWithoutDetaching(
            $courseIds->mapWithKeys(fn (int $courseId): array => [$courseId => ['source' => 'manual']])->all()
        );
    }
}

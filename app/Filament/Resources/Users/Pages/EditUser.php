<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $courseIds = collect($this->data['course_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $existingPivots = $this->record->courses()
            ->get()
            ->keyBy('id')
            ->map(fn ($course) => [
                'source' => $course->pivot?->source ?: 'manual',
                'external_purchase_id' => $course->pivot?->external_purchase_id,
            ]);

        $this->record->courses()->sync(
            $courseIds->mapWithKeys(fn (int $courseId): array => [
                $courseId => $existingPivots->get($courseId, ['source' => 'manual']),
            ])->all()
        );
    }
}

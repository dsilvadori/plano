<?php

namespace App\Filament\Resources\Teachers\Pages;

use App\Filament\Resources\Teachers\TeacherResource;
use App\Support\FilamentThumbnailUpload;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTeacher extends EditRecord
{
    protected static string $resource = TeacherResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['thumbnail_path'] = FilamentThumbnailUpload::store($data['thumbnail_path'] ?? null, 'teacher-thumbnails') ?: $this->record->thumbnail_path;
        unset($data['thumbnail_upload']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

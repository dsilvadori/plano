<?php

namespace App\Filament\Resources\Teachers\Pages;

use App\Filament\Resources\Teachers\TeacherResource;
use App\Support\FilamentThumbnailUpload;
use Filament\Resources\Pages\CreateRecord;

class CreateTeacher extends CreateRecord
{
    protected static string $resource = TeacherResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['thumbnail_path'] = FilamentThumbnailUpload::store($data['thumbnail_path'] ?? null, 'teacher-thumbnails');
        unset($data['thumbnail_upload']);

        return $data;
    }
}

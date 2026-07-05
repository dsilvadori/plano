<?php

namespace App\Filament\Resources\CourseModuleTracks\Pages;

use App\Filament\Resources\CourseModuleTracks\CourseModuleTrackResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateCourseModuleTrack extends CreateRecord
{
    protected static string $resource = CourseModuleTrackResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['thumbnail_path'] = $this->resolveUploadedPath($data['thumbnail_path'] ?? null, 'track-thumbnails');

        return $data;
    }

    protected function resolveUploadedPath(mixed $state, ?string $directory = null): ?string
    {
        if (is_string($state) && $state !== '') {
            return $state;
        }

        if ($directory && is_object($state) && method_exists($state, 'storePublicly')) {
            return $state->storePublicly($directory, ['disk' => 'public']) ?: null;
        }

        if (is_array($state)) {
            $first = Arr::first($state, fn ($value) => (is_string($value) && $value !== '') || (is_object($value) && method_exists($value, 'storePublicly')));

            if (is_string($first)) {
                return $first;
            }

            if ($directory && is_object($first) && method_exists($first, 'storePublicly')) {
                return $first->storePublicly($directory, ['disk' => 'public']) ?: null;
            }
        }

        return null;
    }
}

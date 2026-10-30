<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use App\Support\FilamentThumbnailUpload;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateCourse extends CreateRecord
{
    protected static string $resource = CourseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $previousPath = $data['thumbnail_path'] ?? null;
        $uploadState = $data['thumbnail_upload'] ?? null;

        try {
            $data['thumbnail_path'] = $this->resolveUploadedPath($uploadState, $previousPath, 'course-thumbnails');
        } catch (Throwable $exception) {
            $this->logThumbnailDebug('create.thumbnail_failed', [
                'course_name' => $data['name'] ?? null,
                'previous_path' => $previousPath,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        unset($data['thumbnail_upload']);

        $this->logThumbnailDebug('create.thumbnail_resolved', [
            'course_name' => $data['name'] ?? null,
            'previous_path' => $previousPath,
            'resolved_path' => $data['thumbnail_path'] ?? null,
            'has_upload_state' => filled($uploadState),
        ]);

        return $data;
    }

    protected function resolveUploadedPath(mixed $state, mixed $fallback = null, ?string $directory = null): ?string
    {
        foreach (Arr::wrap($state) as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $path = $directory ? FilamentThumbnailUpload::store($state, $directory) : null;

        if ($path !== null) {
            return $path;
        }

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    protected function logThumbnailDebug(string $event, array $context = []): void
    {
        try {
            Log::channel('course_debug')->info($event, $context);
        } catch (Throwable) {
            //
        }
    }
}

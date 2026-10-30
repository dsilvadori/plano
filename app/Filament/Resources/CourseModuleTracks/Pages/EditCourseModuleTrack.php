<?php

namespace App\Filament\Resources\CourseModuleTracks\Pages;

use App\Filament\Resources\CourseModuleTracks\CourseModuleTrackResource;
use App\Services\ActiveStudyPlanRefresher;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditCourseModuleTrack extends EditRecord
{
    protected static string $resource = CourseModuleTrackResource::class;

    protected array $courseIdsPendingPlanRefresh = [];

    protected function afterSave(): void
    {
        app(ActiveStudyPlanRefresher::class)->refreshCoursesForTrack($this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['thumbnail_path'] = $this->resolveUploadedPath(
            $data['thumbnail_path'] ?? null,
            $this->record->thumbnail_path,
            'track-thumbnails',
        );

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (): void {
                    $this->courseIdsPendingPlanRefresh = app(ActiveStudyPlanRefresher::class)->courseIdsForTrack($this->record);
                })
                ->after(fn (): int => app(ActiveStudyPlanRefresher::class)->refreshCoursesByIds($this->courseIdsPendingPlanRefresh)),
        ];
    }

    protected function resolveUploadedPath(mixed $state, ?string $fallback = null, ?string $directory = null): ?string
    {
        if (is_string($state) && $state !== '') {
            return $state;
        }

        if ($directory && is_object($state) && method_exists($state, 'storePublicly')) {
            return $state->storePublicly($directory, ['disk' => 'public']) ?: $fallback;
        }

        if (is_array($state)) {
            $first = Arr::first($state, fn ($value) => (is_string($value) && $value !== '') || (is_object($value) && method_exists($value, 'storePublicly')));

            if (is_string($first)) {
                return $first;
            }

            if ($directory && is_object($first) && method_exists($first, 'storePublicly')) {
                return $first->storePublicly($directory, ['disk' => 'public']) ?: $fallback;
            }

            return $fallback;
        }

        return $fallback;
    }
}

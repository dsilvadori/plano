<?php

namespace App\Filament\Resources\StudyTracks\Pages;

use App\Filament\Resources\StudyTracks\StudyTrackResource;
use App\Models\Course;
use App\Services\ActiveStudyPlanRefresher;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStudyTrack extends EditRecord
{
    protected static string $resource = StudyTrackResource::class;

    protected ?int $courseIdPendingPlanRefresh = null;

    protected function afterSave(): void
    {
        if ($this->record->course) {
            app(ActiveStudyPlanRefresher::class)->refreshCourseFromNextWeek($this->record->course);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (): void {
                    $this->courseIdPendingPlanRefresh = $this->record->course_id;
                })
                ->after(function (): void {
                    $course = Course::query()->find($this->courseIdPendingPlanRefresh);

                    if ($course) {
                        app(ActiveStudyPlanRefresher::class)->refreshCourseFromNextWeek($course);
                    }
                }),
        ];
    }
}

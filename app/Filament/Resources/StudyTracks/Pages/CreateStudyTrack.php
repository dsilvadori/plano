<?php

namespace App\Filament\Resources\StudyTracks\Pages;

use App\Filament\Resources\StudyTracks\StudyTrackResource;
use App\Services\ActiveStudyPlanRefresher;
use Filament\Resources\Pages\CreateRecord;

class CreateStudyTrack extends CreateRecord
{
    protected static string $resource = StudyTrackResource::class;

    protected function afterCreate(): void
    {
        if ($this->record->course) {
            app(ActiveStudyPlanRefresher::class)->refreshCourseFromNextWeek($this->record->course);
        }
    }
}

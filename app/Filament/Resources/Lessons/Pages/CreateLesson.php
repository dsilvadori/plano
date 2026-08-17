<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Services\ActiveStudyPlanRefresher;
use Filament\Resources\Pages\CreateRecord;

class CreateLesson extends CreateRecord
{
    protected static string $resource = LessonResource::class;

    protected function afterCreate(): void
    {
        LessonResource::syncPrimaryCatalogLinks($this->record);

        app(ActiveStudyPlanRefresher::class)->refreshCoursesForLesson($this->record);
    }
}

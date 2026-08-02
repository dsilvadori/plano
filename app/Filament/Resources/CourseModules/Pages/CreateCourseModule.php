<?php

namespace App\Filament\Resources\CourseModules\Pages;

use App\Filament\Resources\CourseModules\CourseModuleResource;
use App\Services\ActiveStudyPlanRefresher;
use Filament\Resources\Pages\CreateRecord;

class CreateCourseModule extends CreateRecord
{
    protected static string $resource = CourseModuleResource::class;

    protected function afterCreate(): void
    {
        app(ActiveStudyPlanRefresher::class)->refreshCoursesForModule($this->record);
    }
}

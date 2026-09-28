<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Services\ActiveStudyPlanRefresher;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLesson extends EditRecord
{
    protected static string $resource = LessonResource::class;

    protected array $courseIdsPendingPlanRefresh = [];

    protected function afterSave(): void
    {
        LessonResource::syncPrimaryCatalogLinks($this->record);

        app(ActiveStudyPlanRefresher::class)->refreshCoursesForLesson($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (): void {
                    $this->courseIdsPendingPlanRefresh = app(ActiveStudyPlanRefresher::class)->courseIdsForLesson($this->record);
                })
                ->after(fn (): int => app(ActiveStudyPlanRefresher::class)->refreshCoursesByIds($this->courseIdsPendingPlanRefresh)),
        ];
    }
}

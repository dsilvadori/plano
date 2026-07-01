<?php

namespace App\Filament\Resources\CourseModuleTracks\Pages;

use App\Filament\Resources\CourseModuleTracks\CourseModuleTrackResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseModuleTrack extends EditRecord
{
    protected static string $resource = CourseModuleTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

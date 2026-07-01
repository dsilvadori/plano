<?php

namespace App\Filament\Resources\CourseModuleTracks\Pages;

use App\Filament\Resources\CourseModuleTracks\CourseModuleTrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourseModuleTracks extends ListRecords
{
    protected static string $resource = CourseModuleTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

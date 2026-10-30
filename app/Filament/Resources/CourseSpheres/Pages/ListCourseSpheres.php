<?php

namespace App\Filament\Resources\CourseSpheres\Pages;

use App\Filament\Resources\CourseSpheres\CourseSphereResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourseSpheres extends ListRecords
{
    protected static string $resource = CourseSphereResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

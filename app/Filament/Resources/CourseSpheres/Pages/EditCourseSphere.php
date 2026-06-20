<?php

namespace App\Filament\Resources\CourseSpheres\Pages;

use App\Filament\Resources\CourseSpheres\CourseSphereResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseSphere extends EditRecord
{
    protected static string $resource = CourseSphereResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

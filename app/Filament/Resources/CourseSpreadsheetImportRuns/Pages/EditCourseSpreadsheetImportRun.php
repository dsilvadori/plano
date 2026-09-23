<?php

namespace App\Filament\Resources\CourseSpreadsheetImportRuns\Pages;

use App\Filament\Resources\CourseSpreadsheetImportRuns\CourseSpreadsheetImportRunResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseSpreadsheetImportRun extends EditRecord
{
    protected static string $resource = CourseSpreadsheetImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

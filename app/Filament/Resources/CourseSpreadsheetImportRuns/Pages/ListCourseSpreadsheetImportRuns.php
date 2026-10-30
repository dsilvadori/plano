<?php

namespace App\Filament\Resources\CourseSpreadsheetImportRuns\Pages;

use App\Filament\Resources\CourseSpreadsheetImportRuns\CourseSpreadsheetImportRunResource;
use Filament\Resources\Pages\ListRecords;

class ListCourseSpreadsheetImportRuns extends ListRecords
{
    protected static string $resource = CourseSpreadsheetImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

<?php

namespace App\Filament\Resources\GoogleDriveImportRuns\Pages;

use App\Filament\Resources\GoogleDriveImportRuns\GoogleDriveImportRunResource;
use Filament\Resources\Pages\ListRecords;

class ListGoogleDriveImportRuns extends ListRecords
{
    protected static string $resource = GoogleDriveImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

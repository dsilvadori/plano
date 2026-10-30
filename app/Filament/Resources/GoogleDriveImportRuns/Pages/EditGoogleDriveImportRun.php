<?php

namespace App\Filament\Resources\GoogleDriveImportRuns\Pages;

use App\Filament\Resources\GoogleDriveImportRuns\GoogleDriveImportRunResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGoogleDriveImportRun extends EditRecord
{
    protected static string $resource = GoogleDriveImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

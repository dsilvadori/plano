<?php

namespace App\Filament\Resources\StudyTracks\Pages;

use App\Filament\Resources\StudyTracks\StudyTrackResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStudyTrack extends EditRecord
{
    protected static string $resource = StudyTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

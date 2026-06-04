<?php

namespace App\Filament\Resources\StudyTracks\Pages;

use App\Filament\Resources\StudyTracks\StudyTrackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudyTracks extends ListRecords
{
    protected static string $resource = StudyTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

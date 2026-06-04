<?php

namespace App\Filament\Resources\StudyPlans\Pages;

use App\Filament\Resources\StudyPlans\StudyPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudyPlans extends ListRecords
{
    protected static string $resource = StudyPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\StudyPlans\Pages;

use App\Filament\Resources\StudyPlans\StudyPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStudyPlan extends EditRecord
{
    protected static string $resource = StudyPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

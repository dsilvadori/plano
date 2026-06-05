<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('previewTestStudent')
                ->label('Visualizar aluno teste')
                ->icon('heroicon-o-eye')
                ->url(route('admin.preview-test-student.enter')),
        ];
    }
}

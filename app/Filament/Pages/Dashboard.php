<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('openStudentArea')
                ->label('Visualizar área do aluno')
                ->icon('heroicon-o-eye')
                ->color('primary')
                ->button()
                ->openUrlInNewTab()
                ->url(route('dashboard', absolute: false)),
        ];
    }
}

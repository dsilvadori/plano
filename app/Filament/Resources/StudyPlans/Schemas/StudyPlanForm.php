<?php

namespace App\Filament\Resources\StudyPlans\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StudyPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Nome')->disabled(),
                Select::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Rascunho',
                        'active' => 'Ativo',
                        'completed' => 'Concluído',
                        'archived' => 'Arquivado',
                    ]),
                Select::make('viability_status')
                    ->label('Viabilidade')
                    ->options([
                        'good' => 'Boa',
                        'warning' => 'Atenção',
                        'critical' => 'Crítica',
                    ]),
                DatePicker::make('exam_date')->label('Data da prova'),
                DatePicker::make('start_date')->label('Data de início'),
                TextInput::make('total_available_minutes')->label('Minutos disponíveis')->numeric()->disabled(),
                TextInput::make('total_required_minutes')->label('Minutos necessários')->numeric()->disabled(),
                Textarea::make('viability_message')->label('Mensagem')->columnSpanFull(),
            ]);
    }
}

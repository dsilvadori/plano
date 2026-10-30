<?php

namespace App\Filament\Resources\CourseSpreadsheetImportRuns\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CourseSpreadsheetImportRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('course.name')->label('Curso destino')->placeholder('Novo curso')->disabled(),
                TextInput::make('course_name')->label('Curso na planilha')->disabled(),
                TextInput::make('file_name')->label('Arquivo')->disabled(),
                TextInput::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'queued' => 'Na fila',
                        'running' => 'Rodando',
                        'finished' => 'Concluída',
                        'failed' => 'Falhou',
                        null => '',
                        default => $state,
                    })
                    ->disabled(),
                TextInput::make('progress_label')->label('Progresso')->disabled(),
                TextInput::make('duration_label')->label('Duração')->disabled(),
                TextInput::make('total_modules')->label('Módulos')->disabled(),
                TextInput::make('processed_modules')->label('Módulos processados')->disabled(),
                TextInput::make('total_tracks')->label('Trilhas')->disabled(),
                TextInput::make('total_lessons')->label('Aulas')->disabled(),
                TextInput::make('total_minutes')->label('Minutos')->disabled(),
                TextInput::make('latest_message')->label('Última atualização')->disabled()->columnSpanFull(),
                KeyValue::make('summary')->label('Resumo')->disabled()->columnSpanFull(),
                Textarea::make('error_message')->label('Erro da importação')->disabled()->columnSpanFull(),
                DateTimePicker::make('started_at')->label('Iniciada em')->timezone('America/Sao_Paulo')->disabled(),
                DateTimePicker::make('finished_at')->label('Finalizada em')->timezone('America/Sao_Paulo')->disabled(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\GoogleDriveImportRuns\Schemas;

use App\Models\GoogleDriveImportRun;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GoogleDriveImportRunForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('course.name')->label('Curso')->placeholder('Catálogo')->disabled(),
                TextInput::make('module.name')->label('Módulo')->disabled(),
                TextInput::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'queued' => 'Na fila',
                        'running' => 'Rodando',
                        'finished' => 'Concluída',
                        'failed' => 'Falhou',
                        GoogleDriveImportRun::STATUS_CANCELED => 'Interrompida',
                        null => '',
                        default => $state,
                    })
                    ->disabled(),
                TextInput::make('progress_label')->label('Progresso')->disabled(),
                TextInput::make('folder_url')->label('Pasta Drive')->disabled()->columnSpanFull(),
                TextInput::make('latest_message')->label('Última atualização')->disabled()->columnSpanFull(),
                TextInput::make('panda_folders')->label('Pastas Panda')->disabled(),
                TextInput::make('panda_videos_uploaded')->label('Vídeos enviados')->disabled(),
                TextInput::make('panda_videos_failed')->label('Uploads pendentes')->disabled(),
                TextInput::make('panda_videos_skipped')->label('Vídeos ignorados')->disabled(),
                KeyValue::make('summary')->label('Resumo')->disabled()->columnSpanFull(),
                Textarea::make('error_message')->label('Erro da importação')->disabled()->columnSpanFull(),
                DateTimePicker::make('started_at')->label('Iniciada em')->timezone('America/Sao_Paulo')->disabled(),
                DateTimePicker::make('finished_at')->label('Finalizada em')->timezone('America/Sao_Paulo')->disabled(),
            ]);
    }
}

<?php

namespace App\Filament\Resources\GoogleDriveImportRuns\Tables;

use App\Jobs\ReprocessGoogleDrivePendingLessons;
use App\Models\GoogleDriveImportRun;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class GoogleDriveImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Criada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->sortable(),
                TextColumn::make('course.name')->label('Curso')->placeholder('Catálogo')->searchable()->sortable(),
                TextColumn::make('module.name')->label('Módulo')->searchable()->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state, $record): string => match (true) {
                        $state === 'finished' && $record->panda_videos_failed > 0 => 'warning',
                        $state === 'queued' => 'gray',
                        $state === 'running' => 'warning',
                        $state === 'finished' => 'success',
                        $state === 'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state, $record): string => match (true) {
                        $state === 'finished' && $record->panda_videos_failed > 0 => 'Concluída com pendências',
                        $state === 'queued' => 'Na fila',
                        $state === 'running' => 'Rodando',
                        $state === 'finished' => 'Concluída',
                        $state === 'failed' => 'Falhou',
                        default => $state,
                    }),
                TextColumn::make('progress_label')->label('Progresso'),
                TextColumn::make('latest_message')->label('Última atualização')->limit(50),
                TextColumn::make('panda_videos_uploaded')->label('Enviados')->sortable(),
                TextColumn::make('panda_videos_failed')
                    ->label('Uploads pendentes')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'success')
                    ->sortable(),
                TextColumn::make('updated_at')->label('Atualizada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'queued' => 'Na fila',
                        'running' => 'Rodando',
                        'finished' => 'Concluída',
                        'failed' => 'Falhou',
                    ]),
            ])
            ->recordActions([
                Action::make('reprocessPending')
                    ->label('Reprocessar pendentes')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->visible(fn (GoogleDriveImportRun $record): bool => filled($record->folder_id) || filled($record->folder_url))
                    ->action(function (GoogleDriveImportRun $record): void {
                        try {
                            $run = GoogleDriveImportRun::query()->create([
                                'course_id' => $record->course_id,
                                'course_module_id' => $record->course_module_id,
                                'folder_url' => $record->folder_url,
                                'folder_id' => $record->folder_id,
                                'status' => 'queued',
                                'latest_message' => 'Aguardando worker para reprocessar pendentes.',
                                'summary' => [
                                    'type' => 'pending_reprocess',
                                    'source_run_id' => $record->id,
                                ],
                            ]);

                            ReprocessGoogleDrivePendingLessons::dispatch($record->id, $run->id);

                            Notification::make()
                                ->title('Reprocessamento enviado para a fila.')
                                ->body('Uma nova execução foi criada para tentar reaproveitar ou reenviar somente as aulas pendentes.')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Não foi possível reprocessar pendentes.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

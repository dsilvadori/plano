<?php

namespace App\Filament\Resources\CourseModules\Pages;

use App\Filament\Resources\CourseModules\CourseModuleResource;
use App\Services\PandaCourseImporter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditCourseModule extends EditRecord
{
    protected static string $resource = CourseModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPanda')
                ->label('Importar Panda')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar aulas do Panda para este módulo')
                ->modalDescription('Informe a pasta do Panda. Cada vídeo será criado ou atualizado como aula reutilizável e vinculado a este módulo.')
                ->form([
                    TextInput::make('panda_folder_id')
                        ->label('ID da pasta no Panda')
                        ->default(fn () => $this->record->panda_folder_id)
                        ->required(),
                    Select::make('module_type')
                        ->label('Tipo do módulo')
                        ->options([
                            'basic' => 'Matéria Básica',
                            'specific' => 'Conhecimentos Específicos',
                            'complementary' => 'Conhecimentos Complementares',
                            'review' => 'Revisão',
                            'questions' => 'Questões',
                            'other' => 'Outro/Legado',
                        ])
                        ->default(fn () => $this->record->type ?: 'specific')
                        ->required(),
                    Select::make('lesson_status')
                        ->label('Status inicial das aulas')
                        ->options([
                            'draft' => 'Rascunho',
                            'published' => 'Publicado',
                        ])
                        ->default('draft')
                        ->required(),
                ])
                ->action(function (array $data, PandaCourseImporter $importer): void {
                    try {
                        $run = $importer->importIntoModule(
                            $this->record,
                            (string) $data['panda_folder_id'],
                            (string) ($data['lesson_status'] ?? 'draft'),
                            (string) ($data['module_type'] ?? $this->record->type),
                        );

                        Notification::make()
                            ->title('Aulas importadas do Panda.')
                            ->body('Vídeos: ' . ($run->summary['videos'] ?? 0) . '. Criadas: ' . ($run->summary['created'] ?? 0) . '. Atualizadas: ' . ($run->summary['updated'] ?? 0) . '.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar do Panda.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}

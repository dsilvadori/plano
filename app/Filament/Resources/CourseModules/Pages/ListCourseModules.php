<?php

namespace App\Filament\Resources\CourseModules\Pages;

use App\Filament\Resources\CourseModules\CourseModuleResource;
use App\Models\Course;
use App\Services\PandaCourseImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListCourseModules extends ListRecords
{
    protected static string $resource = CourseModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPanda')
                ->label('Importar Panda')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar módulo do Panda')
                ->modalDescription('Informe o nome do módulo já cadastrado e a pasta do Panda. Se existir módulo com o mesmo nome, ele será atualizado; se não existir, será criado no curso de referência.')
                ->form([
                    Select::make('course_id')
                        ->label('Curso de referência')
                        ->options(Course::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('module_name')
                        ->label('Nome do módulo')
                        ->required(),
                    TextInput::make('panda_folder_id')
                        ->label('ID da pasta no Panda')
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
                        $course = Course::query()->findOrFail($data['course_id']);
                        $run = $importer->importReplacingModuleByName(
                            $course,
                            (string) $data['module_name'],
                            (string) $data['panda_folder_id'],
                            (string) ($data['lesson_status'] ?? 'draft'),
                        );

                        Notification::make()
                            ->title('Módulo importado do Panda.')
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
            CreateAction::make(),
        ];
    }
}

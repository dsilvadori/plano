<?php

namespace App\Filament\Resources\CourseModules\Pages;

use App\Filament\Resources\CourseModules\CourseModuleResource;
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
                ->label('Importar vídeos')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar módulo da integração de vídeo')
                ->modalDescription('Informe o nome do módulo já cadastrado e a pasta do provedor de vídeo. Se existir módulo com o mesmo nome, ele será atualizado; se não existir, será criado no catálogo.')
                ->form([
                    TextInput::make('module_name')
                        ->label('Nome do módulo')
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
                        ->default('specific')
                        ->required(),
                    TextInput::make('panda_folder_id')
                        ->label('ID da pasta no provedor')
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
                        $run = $importer->importReplacingModuleByName(
                            null,
                            (string) $data['module_name'],
                            (string) $data['panda_folder_id'],
                            (string) ($data['lesson_status'] ?? 'draft'),
                            (string) ($data['module_type'] ?? 'specific'),
                        );

                        Notification::make()
                            ->title('Módulo importado da integração de vídeo.')
                            ->body('Vídeos: '.($run->summary['videos'] ?? 0).'. Criadas: '.($run->summary['created'] ?? 0).'. Atualizadas: '.($run->summary['updated'] ?? 0).'.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar os vídeos.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}

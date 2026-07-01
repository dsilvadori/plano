<?php

namespace App\Filament\Resources\CourseModules\Pages;

use App\Filament\Resources\CourseModules\CourseModuleResource;
use App\Jobs\ImportGoogleDriveModuleTracks;
use App\Models\Course;
use App\Models\GoogleDriveImportRun;
use App\Services\PandaCourseImporter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                ->label('Importar vídeos')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar aulas da integração de vídeo')
                ->modalDescription('Informe a pasta do provedor de vídeo. Cada vídeo será criado ou atualizado como aula reutilizável e vinculado a este módulo.')
                ->form([
                    TextInput::make('panda_folder_id')
                        ->label('ID da pasta no provedor')
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
                            ->title('Aulas importadas da integração de vídeo.')
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
            Action::make('importGoogleDrive')
                ->label('Importar Drive')
                ->icon('heroicon-o-folder')
                ->modalHeading('Importar trilhas do Google Drive')
                ->modalDescription('Informe uma pasta raiz. Cada subpasta dentro dela será criada ou atualizada como uma trilha deste módulo, e os arquivos de cada subpasta virarão aulas em rascunho.')
                ->form([
                    Select::make('course_id')
                        ->label('Curso que usará estas trilhas')
                        ->options(fn (): array => Course::query()
                            ->where(function ($query): void {
                                $query
                                    ->whereHas('modules', fn ($moduleQuery) => $moduleQuery->whereKey($this->record->id))
                                    ->orWhere('id', $this->record->course_id);
                            })
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => $this->record->course_id)
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('folder_url')
                        ->label('URL ou ID da pasta raiz do Google Drive')
                        ->placeholder('https://drive.google.com/drive/folders/...')
                        ->required(),
                    Select::make('lesson_status')
                        ->label('Status inicial das aulas')
                        ->options([
                            'draft' => 'Rascunho',
                            'published' => 'Publicado',
                        ])
                        ->default('draft')
                        ->required(),
                    Toggle::make('create_panda_folders')
                        ->label('Criar pastas correspondentes no Panda')
                        ->helperText('Cria uma pasta Panda para o módulo e uma pasta Panda para cada trilha importada do Drive.')
                        ->default(true),
                    Toggle::make('upload_panda_videos')
                        ->label('Enviar vídeos das trilhas ao Panda')
                        ->helperText('Baixa cada arquivo de vídeo do Drive e envia para a pasta Panda da trilha. Arquivos PDF e Google Docs ficam como materiais/link.')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    try {
                        Course::query()->findOrFail($data['course_id']);
                        $run = GoogleDriveImportRun::query()->create([
                            'course_id' => (int) $data['course_id'],
                            'course_module_id' => $this->record->id,
                            'folder_url' => (string) $data['folder_url'],
                            'status' => 'queued',
                            'latest_message' => 'Aguardando worker.',
                        ]);

                        ImportGoogleDriveModuleTracks::dispatch(
                            (int) $data['course_id'],
                            $this->record->id,
                            (string) $data['folder_url'],
                            (string) ($data['lesson_status'] ?? 'draft'),
                            (bool) ($data['create_panda_folders'] ?? true),
                            (bool) ($data['upload_panda_videos'] ?? true),
                            $run->id,
                        );

                        Notification::make()
                            ->title('Importação enviada para a fila.')
                            ->body('Acompanhe o progresso em Operação > Importações Drive.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar o Google Drive.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}

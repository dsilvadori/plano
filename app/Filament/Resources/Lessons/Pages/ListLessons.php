<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Jobs\ImportGoogleDriveLessons;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\GoogleDriveImportRun;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Throwable;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importGoogleDriveLessons')
                ->label('Importar Drive')
                ->icon('heroicon-o-folder')
                ->modalHeading('Importar aulas do Google Drive')
                ->modalDescription('Informe uma pasta do Drive. Os arquivos dentro dela serão criados ou atualizados como aulas, com curso, módulo e trilha opcionais.')
                ->form([
                    Select::make('course_id')
                        ->label('Curso')
                        ->options(fn (): array => Course::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->nullable()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('course_module_id', null);
                            $set('course_module_track_id', null);
                        }),
                    Select::make('course_module_id')
                        ->label('Módulo')
                        ->options(fn (Get $get): array => filled($get('course_id'))
                            ? CourseModule::query()
                                ->where('course_id', $get('course_id'))
                                ->orWhereHas('courses', fn ($query) => $query->whereKey($get('course_id')))
                                ->orWhereNull('course_id')
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()
                            : CourseModule::query()
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                        ->searchable()
                        ->preload()
                        ->live()
                        ->nullable()
                        ->helperText('Opcional. Deixe vazio para criar aulas avulsas.')
                        ->afterStateUpdated(fn (Set $set) => $set('course_module_track_id', null)),
                    Select::make('course_module_track_id')
                        ->label('Trilha')
                        ->options(fn (Get $get): array => filled($get('course_module_id'))
                            ? CourseModuleTrack::query()
                                ->where('course_module_id', $get('course_module_id'))
                                ->orderBy('sort_order')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()
                            : [])
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->helperText('Opcional. Se não informar, as aulas ficam sem trilha ou entram na trilha padrão do módulo.'),
                    TextInput::make('folder_url')
                        ->label('URL ou ID da pasta do Google Drive')
                        ->placeholder('https://drive.google.com/drive/folders/...')
                        ->required(),
                    TextInput::make('panda_folder_name')
                        ->label('Pasta Panda para aulas avulsas')
                        ->placeholder('Aulas avulsas')
                        ->helperText('Opcional. Usada quando nenhum módulo ou trilha foi escolhido. Se ficar vazia, o upload vai para a biblioteca raiz do Panda.'),
                    Select::make('lesson_status')
                        ->label('Status inicial das aulas')
                        ->options([
                            'draft' => 'Rascunho',
                            'published' => 'Publicado',
                        ])
                        ->default('draft')
                        ->required(),
                    Toggle::make('create_panda_folder')
                        ->label('Criar ou reutilizar pasta no Panda')
                        ->helperText('Usa a pasta da trilha, do módulo ou o nome informado para aulas avulsas.')
                        ->default(true),
                    Toggle::make('upload_panda_videos')
                        ->label('Enviar vídeos ao Panda')
                        ->helperText('Baixa os arquivos de vídeo do Drive e envia ao Panda. PDFs e documentos ficam como material/link.')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    try {
                        $courseId = filled($data['course_id'] ?? null) ? (int) $data['course_id'] : null;
                        $moduleId = filled($data['course_module_id'] ?? null) ? (int) $data['course_module_id'] : null;
                        $trackId = filled($data['course_module_track_id'] ?? null) ? (int) $data['course_module_track_id'] : null;

                        $run = GoogleDriveImportRun::query()->create([
                            'course_id' => $courseId,
                            'course_module_id' => $moduleId,
                            'folder_url' => (string) $data['folder_url'],
                            'status' => 'queued',
                            'latest_message' => 'Aguardando worker.',
                        ]);

                        ImportGoogleDriveLessons::dispatch(
                            $courseId,
                            $moduleId,
                            $trackId,
                            (string) $data['folder_url'],
                            (string) ($data['lesson_status'] ?? 'draft'),
                            filled($data['panda_folder_name'] ?? null) ? (string) $data['panda_folder_name'] : null,
                            (bool) ($data['create_panda_folder'] ?? true),
                            (bool) ($data['upload_panda_videos'] ?? true),
                            $run->id,
                        )->afterResponse();

                        Notification::make()
                            ->title('Importação de aulas enviada para a fila.')
                            ->body('Acompanhe o progresso em Operação > Importações Drive.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar as aulas do Drive.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}

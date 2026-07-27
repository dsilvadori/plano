<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Jobs\ImportGoogleDriveLessons;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\GoogleDriveImportRun;
use App\Services\PandaCourseImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Throwable;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPandaLessons')
                ->label('Importar Panda')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar aulas do Panda')
                ->modalDescription('Informe uma pasta do Panda. Os vídeos serão criados ou atualizados como aulas, com curso, módulo e trilha opcionais.')
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
                        ->createOptionForm($this->moduleCreateOptionForm())
                        ->createOptionUsing(fn (array $data): int => $this->createModuleOption($data))
                        ->live()
                        ->nullable()
                        ->helperText('Opcional. Deixe vazio para importar aulas avulsas.')
                        ->afterStateUpdated(fn (Set $set) => $set('course_module_track_id', null)),
                    Toggle::make('create_course_module')
                        ->label('Criar novo módulo')
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?bool $state): void {
                            if (! $state) {
                                $set('new_course_module_name', null);

                                return;
                            }

                            $set('course_module_id', null);
                            $set('course_module_track_id', null);
                        }),
                    TextInput::make('new_course_module_name')
                        ->label('Nome do novo módulo')
                        ->required(fn (Get $get): bool => (bool) $get('create_course_module'))
                        ->visible(fn (Get $get): bool => (bool) $get('create_course_module')),
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
                        ->createOptionForm($this->trackCreateOptionForm())
                        ->createOptionUsing(fn (array $data): int => $this->createTrackOption($data))
                        ->nullable()
                        ->helperText('Opcional. Se escolhida, as aulas também serão vinculadas à trilha.')
                        ->afterStateUpdated(fn ($state, Set $set) => $this->syncModuleFromTrack($state, $set)),
                    Toggle::make('create_course_module_track')
                        ->label('Criar nova trilha')
                        ->live()
                        ->visible(fn (Get $get): bool => filled($get('course_module_id')) || (bool) $get('create_course_module'))
                        ->afterStateUpdated(function (Set $set, ?bool $state): void {
                            if (! $state) {
                                $set('new_course_module_track_name', null);

                                return;
                            }

                            $set('course_module_track_id', null);
                        }),
                    TextInput::make('new_course_module_track_name')
                        ->label('Nome da nova trilha')
                        ->required(fn (Get $get): bool => (bool) $get('create_course_module_track'))
                        ->visible(fn (Get $get): bool => (bool) $get('create_course_module_track')),
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
                        $course = filled($data['course_id'] ?? null)
                            ? Course::query()->findOrFail((int) $data['course_id'])
                            : null;
                        [$module, $track] = $this->resolveImportStructure($data, $course);

                        $run = $importer->importLessons(
                            $course,
                            $module,
                            $track,
                            (string) $data['panda_folder_id'],
                            (string) ($data['lesson_status'] ?? 'draft'),
                        );

                        Notification::make()
                            ->title('Aulas importadas do Panda.')
                            ->body('Vídeos: '.($run->summary['videos'] ?? 0).'. Criadas: '.($run->summary['created'] ?? 0).'. Atualizadas: '.($run->summary['updated'] ?? 0).'.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar aulas do Panda.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
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
                        ->createOptionForm($this->moduleCreateOptionForm())
                        ->createOptionUsing(fn (array $data): int => $this->createModuleOption($data))
                        ->live()
                        ->nullable()
                        ->helperText('Opcional. Deixe vazio para criar aulas avulsas.')
                        ->afterStateUpdated(fn (Set $set) => $set('course_module_track_id', null)),
                    Toggle::make('create_course_module')
                        ->label('Criar novo módulo')
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?bool $state): void {
                            if (! $state) {
                                $set('new_course_module_name', null);

                                return;
                            }

                            $set('course_module_id', null);
                            $set('course_module_track_id', null);
                        }),
                    TextInput::make('new_course_module_name')
                        ->label('Nome do novo módulo')
                        ->required(fn (Get $get): bool => (bool) $get('create_course_module'))
                        ->visible(fn (Get $get): bool => (bool) $get('create_course_module')),
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
                        ->createOptionForm($this->trackCreateOptionForm())
                        ->createOptionUsing(fn (array $data): int => $this->createTrackOption($data))
                        ->nullable()
                        ->helperText('Opcional. Se não informar, as aulas ficam sem trilha ou entram na trilha padrão do módulo.')
                        ->afterStateUpdated(fn ($state, Set $set) => $this->syncModuleFromTrack($state, $set)),
                    Toggle::make('create_course_module_track')
                        ->label('Criar nova trilha')
                        ->live()
                        ->visible(fn (Get $get): bool => filled($get('course_module_id')) || (bool) $get('create_course_module'))
                        ->afterStateUpdated(function (Set $set, ?bool $state): void {
                            if (! $state) {
                                $set('new_course_module_track_name', null);

                                return;
                            }

                            $set('course_module_track_id', null);
                        }),
                    TextInput::make('new_course_module_track_name')
                        ->label('Nome da nova trilha')
                        ->required(fn (Get $get): bool => (bool) $get('create_course_module_track'))
                        ->visible(fn (Get $get): bool => (bool) $get('create_course_module_track')),
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
                        $course = $courseId ? Course::query()->findOrFail($courseId) : null;
                        [$module, $track] = $this->resolveImportStructure($data, $course);
                        $moduleId = $module?->id;
                        $trackId = $track?->id;

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

    protected function moduleCreateOptionForm(): array
    {
        return [
            TextInput::make('name')
                ->label('Nome')
                ->required(),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(3)
                ->columnSpanFull(),
            Select::make('type')
                ->label('Tipo')
                ->options([
                    'basic' => 'Matéria Básica',
                    'specific' => 'Conhecimentos Específicos',
                    'complementary' => 'Conhecimentos Complementares',
                    'review' => 'Revisão',
                    'questions' => 'Questões',
                    'other' => 'Outro/Legado',
                ])
                ->default('other')
                ->required(),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0)
                ->required(),
            TextInput::make('panda_folder_id')
                ->label('ID da pasta no Panda'),
        ];
    }

    protected function trackCreateOptionForm(): array
    {
        return [
            Select::make('course_module_id')
                ->label('Módulo')
                ->options(fn (): array => CourseModule::query()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')
                ->label('Slug')
                ->required(),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(3)
                ->columnSpanFull(),
            TextInput::make('thumbnail_url')
                ->label('URL da thumbnail')
                ->url()
                ->maxLength(2048),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0)
                ->required(),
            Select::make('status')
                ->label('Status')
                ->options([
                    'draft' => 'Rascunho',
                    'published' => 'Publicado',
                    'archived' => 'Arquivado',
                ])
                ->default('draft')
                ->required(),
            TextInput::make('panda_folder_id')
                ->label('ID da pasta/playlist no Panda'),
        ];
    }

    protected function createModuleOption(array $data): int
    {
        return CourseModule::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'other',
            'workload_minutes' => 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'panda_folder_id' => filled($data['panda_folder_id'] ?? null) ? (string) $data['panda_folder_id'] : null,
            'is_active' => true,
        ])->getKey();
    }

    protected function createTrackOption(array $data): int
    {
        return CourseModuleTrack::query()->create([
            'course_module_id' => (int) $data['course_module_id'],
            'name' => $data['name'],
            'slug' => filled($data['slug'] ?? null) ? (string) $data['slug'] : Str::slug((string) $data['name']),
            'description' => $data['description'] ?? null,
            'thumbnail_url' => filled($data['thumbnail_url'] ?? null) ? (string) $data['thumbnail_url'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'status' => $data['status'] ?? 'draft',
            'panda_folder_id' => filled($data['panda_folder_id'] ?? null) ? (string) $data['panda_folder_id'] : null,
        ])->getKey();
    }

    /**
     * @return array{0: CourseModule|null, 1: CourseModuleTrack|null}
     */
    protected function resolveImportStructure(array $data, ?Course $course): array
    {
        $module = null;
        $track = null;

        if ((bool) ($data['create_course_module'] ?? false)) {
            $module = CourseModule::query()->create([
                'course_id' => $course?->id,
                'name' => trim((string) $data['new_course_module_name']),
                'type' => 'other',
                'workload_minutes' => 0,
                'sort_order' => $this->nextModuleSortOrder($course),
                'is_active' => true,
            ]);
        } elseif (filled($data['course_module_id'] ?? null)) {
            $module = CourseModule::query()->findOrFail((int) $data['course_module_id']);
        }

        if ((bool) ($data['create_course_module_track'] ?? false)) {
            if (! $module) {
                throw new \InvalidArgumentException('Selecione ou crie um módulo antes de criar uma trilha.');
            }

            $trackName = trim((string) $data['new_course_module_track_name']);

            $track = CourseModuleTrack::query()->create([
                'course_module_id' => $module->id,
                'name' => $trackName,
                'slug' => $this->uniqueTrackSlug($module, $trackName),
                'sort_order' => $this->nextTrackSortOrder($module),
                'status' => 'draft',
            ]);
        } elseif (filled($data['course_module_track_id'] ?? null)) {
            $track = CourseModuleTrack::query()->findOrFail((int) $data['course_module_track_id']);
        }

        if ($course && $module) {
            $module->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $module->sort_order],
            ]);
        }

        if ($course && $track) {
            $track->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $track->sort_order],
            ]);
        }

        return [$module, $track];
    }

    protected function nextModuleSortOrder(?Course $course): int
    {
        if (! $course) {
            return ((int) CourseModule::query()->max('sort_order')) + 1;
        }

        return ((int) CourseModule::query()
            ->where('course_id', $course->id)
            ->orWhereHas('courses', fn ($query) => $query->whereKey($course->id))
            ->max('sort_order')) + 1;
    }

    protected function nextTrackSortOrder(CourseModule $module): int
    {
        return ((int) $module->tracks()->max('sort_order')) + 1;
    }

    protected function uniqueTrackSlug(CourseModule $module, string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'trilha';
        $slug = $baseSlug;
        $suffix = 2;

        while ($module->tracks()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected function syncModuleFromTrack(mixed $state, Set $set): void
    {
        if (blank($state)) {
            return;
        }

        $track = CourseModuleTrack::query()
            ->select(['id', 'course_module_id'])
            ->with('module:id,course_id')
            ->find($state);

        if (! $track) {
            return;
        }

        $set('course_module_id', $track->course_module_id);
    }
}

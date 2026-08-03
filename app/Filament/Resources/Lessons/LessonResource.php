<?php

namespace App\Filament\Resources\Lessons;

use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\QuestionBank;
use App\Services\PandaAiResourceActivator;
use App\Services\PandaTutorActivator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Aula';

    protected static ?string $pluralModelLabel = 'Aulas';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('course_module_id')
                ->label('Módulo')
                ->options(fn (): array => CourseModule::query()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->createOptionForm([
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
                    TextInput::make('workload_minutes')
                        ->label('Carga horária (minutos)')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    TextInput::make('sort_order')
                        ->label('Ordem')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    TextInput::make('panda_folder_id')
                        ->label('ID da pasta no provedor'),
                ])
                ->createOptionUsing(function (array $data): int {
                    return CourseModule::create([
                        ...$data,
                        'is_active' => true,
                    ])->getKey();
                })
                ->afterStateUpdated(function ($state, Set $set): void {
                    $set('course_module_track_id', null);
                })
                ->nullable(),
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
                ->preload(),
            TextInput::make('title')
                ->label('Título')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')
                ->label('Slug')
                ->required(),
            Select::make('type')
                ->label('Tipo')
                ->options([
                    'video' => 'Vídeo',
                    'pdf' => 'PDF / Livro digital',
                    'mixed' => 'Vídeo + material',
                    'text' => 'Texto',
                    'quiz' => 'Questões',
                ])
                ->default('video')
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
            Select::make('source_status')
                ->label('Status da mídia')
                ->options([
                    'structure_only' => 'Somente estrutura',
                    'awaiting_media' => 'Aguardando mídia',
                    'upload_queued' => 'Upload na fila',
                    'uploading' => 'Enviando ao Panda',
                    'panda_processing' => 'Processando no Panda',
                    'upload_failed' => 'Falha no upload',
                    'media_ready' => 'Mídia pronta',
                    'published' => 'Publicado',
                ])
                ->default('media_ready')
                ->required(),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(4)
                ->columnSpanFull(),
            TextInput::make('thumbnail_url')
                ->label('URL da thumbnail')
                ->url()
                ->maxLength(2048)
                ->helperText('Pode vir diretamente da integração de vídeo.'),
            TextInput::make('duration_seconds')
                ->label('Duração em segundos')
                ->numeric()
                ->default(0)
                ->required(),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0)
                ->required(),
            TextInput::make('panda_video_id')
                ->label('ID do vídeo no provedor')
                ->helperText('Preparado para importação/sincronização com a integração de vídeo.'),
            TextInput::make('panda_embed_url')
                ->label('URL de embed')
                ->url()
                ->maxLength(2048),
            TextInput::make('panda_player_url')
                ->label('URL do player')
                ->url()
                ->maxLength(2048),
            TextInput::make('panda_status')
                ->label('Status no provedor'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Aula')->searchable()->sortable()->toggleable(),
                TextColumn::make('linked_courses')
                    ->label('Curso')
                    ->getStateUsing(fn (Lesson $record): string => self::linkedCourseNames($record))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('course', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('modules.courses', fn ($query) => $query->where('courses.name', 'like', "%{$search}%"))
                        ->orWhereHas('tracks.courses', fn ($query) => $query->where('courses.name', 'like', "%{$search}%"))),
                TextColumn::make('linked_modules')
                    ->label('Módulo')
                    ->getStateUsing(fn (Lesson $record): string => self::linkedModuleNames($record))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('module', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('modules', fn ($query) => $query->where('course_modules.name', 'like', "%{$search}%"))),
                TextColumn::make('linked_tracks')
                    ->label('Trilha')
                    ->getStateUsing(fn (Lesson $record): string => self::linkedTrackNames($record))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('track', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('tracks', fn ($query) => $query->where('course_module_tracks.name', 'like', "%{$search}%"))),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'video' => 'Vídeo',
                        'pdf' => 'PDF',
                        'mixed' => 'Mista',
                        'text' => 'Texto',
                        'quiz' => 'Questões',
                        default => $state,
                    }),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                        default => $state,
                    }),
                TextColumn::make('source_status')
                    ->label('Mídia')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'structure_only' => 'Somente estrutura',
                        'awaiting_media' => 'Aguardando mídia',
                        'upload_queued' => 'Upload na fila',
                        'uploading' => 'Enviando ao Panda',
                        'panda_processing' => 'Processando no Panda',
                        'upload_failed' => 'Falha no upload',
                        'media_ready' => 'Mídia pronta',
                        'published' => 'Publicado',
                        default => (string) $state,
                    }),
                TextColumn::make('ai_resources_status')
                    ->label('IA')
                    ->badge()
                    ->toggleable()
                    ->getStateUsing(fn (Lesson $record): string => self::aiResourcesStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Completa' => 'success',
                        'Parcial' => 'warning',
                        'Gerando' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('tutor_status_flag')
                    ->label('Tutor')
                    ->badge()
                    ->toggleable()
                    ->getStateUsing(fn (Lesson $record): string => self::tutorStatusFlag($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Ativo' => 'success',
                        'Processando' => 'warning',
                        'Solicitado' => 'info',
                        'Falhou' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('duration_minutes')->label('Min')->sortable(query: fn ($query, $direction) => $query->orderBy('duration_seconds', $direction))->toggleable(),
                TextColumn::make('sort_order')->label('Ordem')->sortable()->toggleable(),
                TextColumn::make('panda_video_id')->label('ID do provedor')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label('Curso')
                    ->options(Course::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(function ($query) use ($data): void {
                            $query->where('course_id', $data['value'])
                                ->orWhereHas('modules.courses', fn ($query) => $query->whereKey($data['value']))
                                ->orWhereHas('tracks.courses', fn ($query) => $query->whereKey($data['value']));
                        })
                        : $query),
                SelectFilter::make('question_bank_id')
                    ->label('Banco de questões')
                    ->options(QuestionBank::query()->orderBy('title')->pluck('title', 'id'))
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(function ($query) use ($data): void {
                            $query->whereHas('questionBanks', fn ($query) => $query->whereKey($data['value']))
                                ->orWhereHas('module.questionBanks', fn ($query) => $query->whereKey($data['value']))
                                ->orWhereHas('modules.questionBanks', fn ($query) => $query->whereKey($data['value']))
                                ->orWhereHas('track.questionBanks', fn ($query) => $query->whereKey($data['value']))
                                ->orWhereHas('tracks.questionBanks', fn ($query) => $query->whereKey($data['value']));
                        })
                        : $query),
                SelectFilter::make('type')
                    ->options([
                        'video' => 'Vídeo',
                        'pdf' => 'PDF',
                        'mixed' => 'Mista',
                        'text' => 'Texto',
                        'quiz' => 'Questões',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                    ]),
                SelectFilter::make('source_status')
                    ->label('Mídia')
                    ->options([
                        'structure_only' => 'Somente estrutura',
                        'awaiting_media' => 'Aguardando mídia',
                        'upload_queued' => 'Upload na fila',
                        'uploading' => 'Enviando ao Panda',
                        'panda_processing' => 'Processando no Panda',
                        'upload_failed' => 'Falha no upload',
                        'media_ready' => 'Mídia pronta',
                        'published' => 'Publicado',
                    ]),
            ])
            ->recordActions([
                Action::make('activatePandaAi')
                    ->label('Gerar Recursos de IA')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalHeading('Gerar recursos de IA em português')
                    ->modalDescription('Se já houver uma geração em andamento, a plataforma tentará buscar o resultado. Caso contrário, os recursos atuais serão removidos e uma nova geração em português do Brasil será solicitada.')
                    ->visible(fn (Lesson $record): bool => self::hasPandaVideo($record))
                    ->action(function (Lesson $record, PandaAiResourceActivator $activator): void {
                        try {
                            $result = $activator->generate($record);

                            self::notifyPandaAiResult($result);
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('Não foi possível ativar a IA do Panda')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('activatePandaTutor')
                    ->label('Ativar Tutor IA')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->requiresConfirmation()
                    ->modalHeading('Ativar Tutor IA do Panda')
                    ->modalDescription('A plataforma verificará se o Tutor IA já está disponível no Panda. Se ainda não estiver, solicitará a geração dos recursos necessários e agendará novas verificações.')
                    ->visible(fn (Lesson $record): bool => self::hasPandaVideo($record))
                    ->action(function (Lesson $record, PandaTutorActivator $activator): void {
                        try {
                            $result = $activator->activate($record);

                            self::notifyPandaTutorResult($result);
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('Não foi possível ativar o Tutor IA')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activatePandaAi')
                        ->label('Gerar Recursos de IA')
                        ->icon('heroicon-o-sparkles')
                        ->requiresConfirmation()
                        ->modalHeading('Gerar recursos de IA em português')
                        ->modalDescription('Para aulas com geração em andamento, a plataforma tentará buscar o resultado. Para as demais, os recursos atuais serão removidos e uma nova geração em português do Brasil será solicitada.')
                        ->action(function (Collection $records, PandaAiResourceActivator $activator): void {
                            if ($records->isEmpty()) {
                                Notification::make()
                                    ->title('Nenhuma aula selecionada')
                                    ->body('Selecione uma ou mais aulas na tabela antes de usar a ação em massa.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $requested = 0;
                            $syncedArtifacts = 0;
                            $skipped = 0;
                            $failed = 0;
                            $pending = 0;
                            $alreadyReady = 0;

                            foreach ($records as $record) {
                                if (! self::hasPandaVideo($record)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $result = $activator->generate($record);
                                    $requested += $result['requested'] ? 1 : 0;
                                    $pending += $result['pending'] ? 1 : 0;
                                    $syncedArtifacts += (int) $result['created_artifacts'];
                                    $alreadyReady += (! $result['requested'] && ! $result['pending'] && (int) $result['created_artifacts'] === 0) ? 1 : 0;
                                } catch (Throwable $exception) {
                                    report($exception);
                                    $failed++;
                                }
                            }

                            $notification = Notification::make()
                                ->title('Geração de IA Panda concluída')
                                ->body("Solicitadas: {$requested}. Aguardando Panda: {$pending}. Já disponíveis: {$alreadyReady}. Artefatos sincronizados: {$syncedArtifacts}. Ignoradas sem vídeo Panda: {$skipped}. Falhas: {$failed}.");

                            ($failed > 0 ? $notification->warning() : $notification->success())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activatePandaTutor')
                        ->label('Ativar Tutor IA')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->requiresConfirmation()
                        ->modalHeading('Ativar Tutor IA do Panda')
                        ->modalDescription('Para aulas selecionadas, a plataforma verificará se o Tutor IA já está disponível no Panda. Se ainda não estiver, solicitará a geração dos recursos necessários.')
                        ->action(function (Collection $records, PandaTutorActivator $activator): void {
                            if ($records->isEmpty()) {
                                Notification::make()
                                    ->title('Nenhuma aula selecionada')
                                    ->body('Selecione uma ou mais aulas na tabela antes de usar a ação em massa.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $activated = 0;
                            $requested = 0;
                            $skipped = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if (! self::hasPandaVideo($record)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $result = $activator->activate($record);
                                    $activated += $result['available'] ? 1 : 0;
                                    $requested += $result['requested'] ? 1 : 0;
                                } catch (Throwable $exception) {
                                    report($exception);
                                    $failed++;
                                }
                            }

                            $notification = Notification::make()
                                ->title('Ativação do Tutor IA concluída')
                                ->body("Ativadas: {$activated}. Solicitadas: {$requested}. Ignoradas sem vídeo Panda: {$skipped}. Falhas: {$failed}.");

                            ($failed > 0 ? $notification->warning() : $notification->success())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('publish')
                        ->label('Publicar selecionadas')
                        ->icon('heroicon-o-eye')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'published']))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('unpublish')
                        ->label('Despublicar selecionadas')
                        ->icon('heroicon-o-eye-slash')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'draft']))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLessons::route('/'),
            'create' => CreateLesson::route('/create'),
            'edit' => EditLesson::route('/{record}/edit'),
        ];
    }

    protected static function linkedCourseNames(Lesson $lesson): string
    {
        $names = collect();

        if ($lesson->relationLoaded('course') ? $lesson->course : $lesson->course()->first()) {
            $names->push($lesson->course->name);
        }

        $moduleCourses = ($lesson->relationLoaded('modules') ? $lesson->modules : $lesson->modules()->with('courses')->get())
            ->flatMap(fn (CourseModule $module) => $module->courses);
        $trackCourses = ($lesson->relationLoaded('tracks') ? $lesson->tracks : $lesson->tracks()->with('courses')->get())
            ->flatMap(fn (CourseModuleTrack $track) => $track->courses);

        return $names
            ->merge($moduleCourses->pluck('name'))
            ->merge($trackCourses->pluck('name'))
            ->filter()
            ->unique()
            ->join(', ');
    }

    public static function hasPandaVideo(Lesson $lesson): bool
    {
        return filled($lesson->panda_video_id) || filled(data_get($lesson->metadata, 'payload.id'));
    }

    public static function aiResourcesStatus(Lesson $lesson): string
    {
        $readyTypes = $lesson->aiArtifacts()
            ->where('status', 'ready')
            ->whereIn('artifact_type', ['summary', 'quiz', 'mindmap'])
            ->pluck('artifact_type')
            ->unique();

        if ($readyTypes->count() >= 3) {
            return 'Completa';
        }

        if ($readyTypes->isNotEmpty()) {
            return 'Parcial';
        }

        if (data_get($lesson->metadata, 'panda_ai.last_request_status') === 'requested'
            || data_get($lesson->metadata, 'panda_ai.last_payload_status') === 'regenerating') {
            return 'Gerando';
        }

        return 'Sem IA';
    }

    public static function tutorStatusFlag(Lesson $lesson): string
    {
        if ((bool) data_get($lesson->metadata, 'panda_ai.tutor_available', false)) {
            return 'Ativo';
        }

        return match ((string) data_get($lesson->metadata, 'panda_ai.tutor_status', '')) {
            'processing', 'queued' => 'Processando',
            'requested', 'ready' => 'Solicitado',
            'failed' => 'Falhou',
            default => 'Sem Tutor',
        };
    }

    public static function notifyPandaAiResult(array $result): void
    {
        Notification::make()
            ->title($result['created_artifacts'] > 0 ? 'IA do Panda sincronizada' : ($result['requested'] ? 'IA do Panda solicitada em PT-BR' : 'IA do Panda já disponível'))
            ->body($result['created_artifacts'] > 0
                ? "{$result['created_artifacts']} recurso(s) de IA foram salvos para esta aula."
                : ($result['requested']
                    ? 'O Panda recebeu uma nova solicitação em português do Brasil e a sincronização foi agendada.'
                    : ($result['pending']
                        ? 'A geração já está em andamento no Panda. A plataforma tentou buscar o resultado e vai tentar novamente em background.'
                        : 'Os recursos de IA desta aula já estão disponíveis na plataforma.')))
            ->success()
            ->send();
    }

    public static function notifyPandaTutorResult(array $result): void
    {
        Notification::make()
            ->title($result['available'] ? 'Tutor IA ativado' : 'Tutor IA em processamento')
            ->body($result['available']
                ? 'O Tutor IA do Panda já está disponível para esta aula.'
                : 'O Tutor IA já foi solicitado ao Panda. A plataforma vai liberar o chat quando o processamento do vídeo no Tutor terminar.')
            ->success()
            ->send();
    }

    protected static function linkedModuleNames(Lesson $lesson): string
    {
        $names = collect();

        if ($lesson->relationLoaded('module') ? $lesson->module : $lesson->module()->first()) {
            $names->push($lesson->module->name);
        }

        return $names
            ->merge(($lesson->relationLoaded('modules') ? $lesson->modules : $lesson->modules()->get())->pluck('name'))
            ->filter()
            ->unique()
            ->join(', ');
    }

    protected static function linkedTrackNames(Lesson $lesson): string
    {
        $names = collect();

        if ($lesson->relationLoaded('track') ? $lesson->track : $lesson->track()->first()) {
            $names->push($lesson->track->name);
        }

        return $names
            ->merge(($lesson->relationLoaded('tracks') ? $lesson->tracks : $lesson->tracks()->get())->pluck('name'))
            ->filter()
            ->unique()
            ->join(', ');
    }
}

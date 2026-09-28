<?php

namespace App\Filament\Resources\Lessons;

use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Http\Controllers\CourseCatalogController;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\LessonFolder;
use App\Models\QuestionBank;
use App\Models\Video;
use App\Services\PandaAiResourceActivator;
use App\Services\PandaTutorActivator;
use Filament\Actions\Action;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
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
            Select::make('lesson_folder_id')
                ->label('Pasta da biblioteca')
                ->options(fn (): array => self::lessonFolderOptions())
                ->searchable()
                ->preload()
                ->helperText('Organiza a aula como item único da biblioteca, reutilizável em várias trilhas, módulos e cursos.')
                ->columnSpanFull(),
            Select::make('modules')
                ->label('Módulos vinculados')
                ->relationship('modules', 'name')
                ->options(fn (): array => CourseModule::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Uma aula pode aparecer em vários módulos. Os cursos vêm dos vínculos destes módulos.')
                ->columnSpanFull(),
            Select::make('tracks')
                ->label('Trilhas vinculadas')
                ->relationship('tracks', 'name')
                ->options(fn (): array => CourseModuleTrack::query()
                    ->with('module:id,name')
                    ->orderBy('name')
                    ->get(['id', 'course_module_id', 'name'])
                    ->mapWithKeys(fn (CourseModuleTrack $track): array => [
                        $track->id => collect([$track->module?->name, $track->name])->filter()->join(' / '),
                    ])
                    ->sort()
                    ->all())
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Uma aula pode aparecer em várias trilhas. Cada trilha também pode estar vinculada a vários cursos.')
                ->columnSpanFull(),
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
                    'awaiting_media' => 'Mídia pendente',
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
            FileUpload::make('digital_book_path')
                ->label('Livro digital / apostila em PDF')
                ->disk('local')
                ->directory('lesson-digital-books')
                ->preserveFilenames()
                ->acceptedFileTypes(['application/pdf'])
                ->maxSize(51200)
                ->helperText('Opcional. Quando preenchido, o aluno poderá baixar o PDF; em aulas do tipo PDF, ele também será exibido dentro da aula.')
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
                ->required()
                ->suffix('s')
                ->live(onBlur: true)
                ->afterStateHydrated(fn ($state, Set $set) => $set('duration_minutes_preview', self::durationSecondsToMinutes($state)))
                ->afterStateUpdated(fn ($state, Set $set) => $set('duration_minutes_preview', self::durationSecondsToMinutes($state))),
            TextInput::make('duration_minutes_preview')
                ->label('Duração em minutos')
                ->numeric()
                ->disabled()
                ->dehydrated(false)
                ->suffix('min')
                ->helperText('Calculada automaticamente a partir da duração em segundos.'),
            Select::make('video_id')
                ->label('Vídeo da biblioteca')
                ->options(fn (): array => self::videoLibraryOptions())
                ->getSearchResultsUsing(fn (?string $search): array => self::videoLibraryOptions($search))
                ->getOptionLabelUsing(fn ($value): ?string => self::videoLibraryOptionLabel($value))
                ->searchable()
                ->preload()
                ->live()
                ->nullable()
                ->helperText('Opcional. A mídia técnica do Panda fica na Biblioteca de Vídeos; a aula apenas aponta para ela.')
                ->afterStateUpdated(fn ($state, Set $set) => self::applyVideoToForm($state, $set)),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Aula')->searchable()->sortable()->toggleable(),
                TextColumn::make('folder.path')
                    ->label('Pasta')
                    ->formatStateUsing(fn (?string $state): string => $state ? str_replace('/', ' / ', $state) : 'Sem pasta')
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('folder', fn ($query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('path', 'like', "%{$search}%")))
                    ->toggleable(),
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
                        'awaiting_media' => 'Mídia pendente',
                        'upload_queued' => 'Upload na fila',
                        'uploading' => 'Enviando ao Panda',
                        'panda_processing' => 'Processando no Panda',
                        'upload_failed' => 'Falha no upload',
                        'media_ready' => 'Mídia pronta',
                        'published' => 'Publicado',
                        default => (string) $state,
                    }),
                TextColumn::make('digital_book_path')
                    ->label('Apostila')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'PDF' : 'Sem PDF')
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray'),
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
                    ->options(fn (): array => Course::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(function ($query) use ($data): void {
                            $query->where('course_id', $data['value'])
                                ->orWhereHas('module', fn ($query) => $query
                                    ->where('course_id', $data['value'])
                                    ->orWhereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                    ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $data['value'])))
                                ->orWhereHas('modules', fn ($query) => $query
                                    ->where('course_id', $data['value'])
                                    ->orWhereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                    ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $data['value'])))
                                ->orWhereHas('track', fn ($query) => $query
                                    ->whereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                    ->orWhereHas('module', fn ($query) => $query
                                        ->where('course_id', $data['value'])
                                        ->orWhereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                        ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $data['value']))))
                                ->orWhereHas('tracks', fn ($query) => $query
                                    ->whereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                    ->orWhereHas('module', fn ($query) => $query
                                        ->where('course_id', $data['value'])
                                        ->orWhereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                        ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $data['value']))));
                        })
                        : $query),
                SelectFilter::make('lesson_folder_id')
                    ->label('Pasta da biblioteca')
                    ->options(fn (): array => self::lessonFolderOptions())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('course_module_id')
                    ->label('Módulo')
                    ->options(fn ($livewire): array => self::moduleFilterOptions(
                        self::selectedFilterValue($livewire, 'course_id'),
                    ))
                    ->searchable()
                    ->preload()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(function ($query) use ($data): void {
                            $query->where('course_module_id', $data['value'])
                                ->orWhere('metadata->import_context_module_id', (int) $data['value'])
                                ->orWhereHas('modules', fn ($query) => $query->whereKey($data['value']));
                        })
                        : $query),
                SelectFilter::make('course_module_track_id')
                    ->label('Trilha')
                    ->options(fn ($livewire): array => self::trackFilterOptions(
                        self::selectedFilterValue($livewire, 'course_id'),
                        self::selectedFilterValue($livewire, 'course_module_id'),
                    ))
                    ->searchable()
                    ->preload()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(function ($query) use ($data): void {
                            $query->where('course_module_track_id', $data['value'])
                                ->orWhere('metadata->import_context_track_id', (int) $data['value'])
                                ->orWhereHas('tracks', fn ($query) => $query->whereKey($data['value']));
                        })
                        : $query),
                SelectFilter::make('question_bank_id')
                    ->label('Banco de questões')
                    ->options(QuestionBank::query()->orderBy('title')->pluck('title', 'id'))
                    ->searchable()
                    ->preload()
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
                    ->label('Tipo')
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
                        'awaiting_media' => 'Mídia pendente',
                        'upload_queued' => 'Upload na fila',
                        'uploading' => 'Enviando ao Panda',
                        'panda_processing' => 'Processando no Panda',
                        'upload_failed' => 'Falha no upload',
                        'media_ready' => 'Mídia pronta',
                        'published' => 'Publicado',
                    ]),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->recordActions([
                Action::make('activatePandaAi')
                    ->label('Gerar Recursos de IA')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalHeading('Gerar recursos de IA para esta aula')
                    ->visible(fn (Lesson $record): bool => self::hasPandaVideo($record))
                    ->action(function (Lesson $record, PandaAiResourceActivator $activator): void {
                        try {
                            self::notifyPandaAiResult($activator->reprocess($record));
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('Não foi possível ativar a IA do Panda')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('clearPandaAiCache')
                    ->label('Limpar cache da IA')
                    ->icon('heroicon-o-trash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Limpar recursos de IA desta aula')
                    ->visible(fn (Lesson $record): bool => self::hasPandaVideo($record))
                    ->action(function (Lesson $record, PandaAiResourceActivator $activator): void {
                        $deleted = $activator->clearCachedArtifacts($record);

                        Notification::make()
                            ->title('Cache da IA limpo')
                            ->body("{$deleted} recurso(s) de IA foram removidos.")
                            ->success()
                            ->send();
                    }),
                Action::make('activatePandaTutor')
                    ->label('Ativar Tutor IA')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->requiresConfirmation()
                    ->modalHeading('Ativar Tutor IA para esta aula')
                    ->visible(fn (Lesson $record): bool => self::hasPandaVideo($record))
                    ->action(function (Lesson $record, PandaTutorActivator $activator): void {
                        try {
                            self::notifyPandaTutorResult($activator->activate($record));
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
                        ->modalHeading('Gerar recursos de IA para as aulas selecionadas')
                        ->action(function (Collection $records, PandaAiResourceActivator $activator): void {
                            [$requested, $pending, $syncedArtifacts, $alreadyReady, $skipped, $failed] = [0, 0, 0, 0, 0, 0];

                            foreach ($records as $record) {
                                if (! self::hasPandaVideo($record)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $result = $activator->reprocess($record);
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
                                ->body("Solicitadas: {$requested}. Aguardando Panda: {$pending}. Já disponíveis: {$alreadyReady}. Artefatos sincronizados: {$syncedArtifacts}. Ignoradas sem Panda: {$skipped}. Falhas: {$failed}.");

                            ($failed > 0 ? $notification->warning() : $notification->success())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('clearPandaAiCache')
                        ->label('Limpar cache da IA')
                        ->icon('heroicon-o-trash')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Limpar recursos de IA das aulas selecionadas')
                        ->action(function (Collection $records, PandaAiResourceActivator $activator): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! self::hasPandaVideo($record)) {
                                    $skipped++;

                                    continue;
                                }

                                $deleted += $activator->clearCachedArtifacts($record);
                            }

                            Notification::make()
                                ->title('Cache da IA limpo')
                                ->body("Recursos removidos: {$deleted}. Ignoradas sem Panda: {$skipped}.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activatePandaTutor')
                        ->label('Ativar Tutor IA')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->requiresConfirmation()
                        ->modalHeading('Ativar Tutor IA para as aulas selecionadas')
                        ->action(function (Collection $records, PandaTutorActivator $activator): void {
                            [$activated, $requested, $skipped, $failed] = [0, 0, 0, 0];

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
                                ->body("Ativadas: {$activated}. Solicitadas: {$requested}. Ignoradas sem Panda: {$skipped}. Falhas: {$failed}.");

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

    public static function videoLibraryOptions(?string $search = null): array
    {
        return self::videoLibraryQuery($search)
            ->limit(50)
            ->get(['id', 'title', 'duration_seconds', 'provider_video_id'])
            ->mapWithKeys(fn (Video $video): array => [
                $video->id => self::videoLibraryLabel($video),
            ])
            ->all();
    }

    public static function videoLibraryOptionLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $video = self::videoLibraryQuery()
            ->whereKey($value)
            ->first(['id', 'title', 'duration_seconds', 'provider_video_id']);

        return $video ? self::videoLibraryLabel($video) : null;
    }

    public static function applyVideoToForm(mixed $videoId, Set $set): void
    {
        if (blank($videoId)) {
            return;
        }

        $video = self::videoLibraryQuery()
            ->whereKey($videoId)
            ->first();

        if (! $video) {
            return;
        }

        $set('type', 'video');
        $set('thumbnail_url', $video->thumbnail_url);
        $set('duration_seconds', (int) $video->duration_seconds);
        $set('duration_minutes_preview', self::durationSecondsToMinutes($video->duration_seconds));
        $set('source_status', $video->source_status ?: 'media_ready');
    }

    protected static function durationSecondsToMinutes(mixed $seconds): int
    {
        return (int) ceil(max(0, (int) $seconds) / 60);
    }

    protected static function videoLibraryQuery(?string $search = null): \Illuminate\Database\Eloquent\Builder
    {
        $search = trim((string) $search);

        return Video::query()
            ->where(function ($query): void {
                $query
                    ->whereNotNull('provider_video_id')
                    ->orWhereNotNull('embed_url')
                    ->orWhereNotNull('player_url');
            })
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('provider_video_id', 'like', '%'.$search.'%');
            }))
            ->orderBy('title')
            ->orderBy('id');
    }

    protected static function videoLibraryLabel(Video $video): string
    {
        $minutes = (int) ceil(((int) $video->duration_seconds) / 60);
        $duration = $minutes > 0 ? $minutes.' min' : 'sem duração';
        $panda = filled($video->provider_video_id) ? ' · Panda '.$video->provider_video_id : '';

        return Str::limit($video->title, 90).' · '.$duration.$panda;
    }

    public static function lessonFolderOptions(): array
    {
        return LessonFolder::query()
            ->orderBy('path')
            ->get(['id', 'path'])
            ->mapWithKeys(fn (LessonFolder $folder): array => [
                $folder->id => str_replace('/', ' / ', $folder->path),
            ])
            ->all();
    }

    public static function moduleFilterOptions(mixed $courseId = null): array
    {
        return CourseModule::query()
            ->when(filled($courseId), fn ($query) => $query->where(function ($query) use ($courseId): void {
                $query->where('course_id', $courseId)
                    ->orWhereHas('courses', fn ($query) => $query->whereKey($courseId))
                    ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $courseId))
                    ->orWhereHas('tracks.courses', fn ($query) => $query->whereKey($courseId));
            }))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function trackFilterOptions(mixed $courseId = null, mixed $moduleId = null): array
    {
        return CourseModuleTrack::query()
            ->with('module:id,name')
            ->when(filled($moduleId), fn ($query) => $query->where('course_module_id', $moduleId))
            ->when(filled($courseId), fn ($query) => $query->where(function ($query) use ($courseId): void {
                $query->whereHas('courses', fn ($query) => $query->whereKey($courseId))
                    ->orWhereHas('module', fn ($query) => $query
                        ->where('course_id', $courseId)
                        ->orWhereHas('courses', fn ($query) => $query->whereKey($courseId))
                        ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $courseId)));
            }))
            ->orderBy('name')
            ->get(['id', 'course_module_id', 'name'])
            ->mapWithKeys(fn (CourseModuleTrack $track): array => [
                $track->id => collect([$track->module?->name, $track->name])->filter()->join(' / '),
            ])
            ->sort()
            ->all();
    }

    protected static function selectedFilterValue(mixed $livewire, string $filter): mixed
    {
        return data_get($livewire, "tableFilters.{$filter}.value");
    }

    public static function syncPrimaryCatalogLinks(Lesson $lesson): void
    {
        $trackModuleIds = $lesson->tracks()
            ->with('module:id')
            ->get()
            ->pluck('module.id')
            ->filter()
            ->unique()
            ->values();

        if ($trackModuleIds->isNotEmpty()) {
            $lesson->modules()->syncWithoutDetaching(
                $trackModuleIds
                    ->mapWithKeys(fn (int $moduleId): array => [$moduleId => ['sort_order' => (int) $lesson->sort_order]])
                    ->all()
            );
        }

        $primaryModuleId = $lesson->modules()
            ->orderByPivot('sort_order')
            ->orderBy('course_modules.name')
            ->value('course_modules.id');

        $primaryTrackId = $lesson->tracks()
            ->orderByPivot('sort_order')
            ->orderBy('course_module_tracks.name')
            ->value('course_module_tracks.id');

        $lesson->forceFill([
            'course_module_id' => $primaryModuleId,
            'course_module_track_id' => $primaryTrackId,
        ])->saveQuietly();
    }

    public static function forgetCatalogCachesForLesson(Lesson $lesson): void
    {
        static::courseIdsForLesson($lesson)
            ->each(fn (int $courseId): null => CourseCatalogController::forgetCourseCatalogCache($courseId));
    }

    public static function courseIdsForLesson(Lesson $lesson): \Illuminate\Support\Collection
    {
        $lesson->loadMissing([
            'course:id',
            'modules:id,course_id',
            'modules.courses:id',
            'modules.studyTracks:id,course_id',
            'tracks:id,course_module_id',
            'tracks.courses:id',
            'tracks.module:id,course_id',
            'tracks.module.courses:id',
            'tracks.module.studyTracks:id,course_id',
        ]);

        return collect([$lesson->course_id, $lesson->course?->id])
            ->merge($lesson->modules->pluck('course_id'))
            ->merge($lesson->modules->flatMap(fn (CourseModule $module) => $module->courses->pluck('id')))
            ->merge($lesson->modules->flatMap(fn (CourseModule $module) => $module->studyTracks->pluck('course_id')))
            ->merge($lesson->tracks->flatMap(fn (CourseModuleTrack $track) => $track->courses->pluck('id')))
            ->merge($lesson->tracks->pluck('module.course_id'))
            ->merge($lesson->tracks->flatMap(fn (CourseModuleTrack $track) => $track->module?->courses->pluck('id') ?? collect()))
            ->merge($lesson->tracks->flatMap(fn (CourseModuleTrack $track) => $track->module?->studyTracks->pluck('course_id') ?? collect()))
            ->filter()
            ->map(fn ($courseId): int => (int) $courseId)
            ->unique()
            ->values();
    }

    protected static function linkedCourseNames(Lesson $lesson): string
    {
        $names = collect();

        if ($lesson->relationLoaded('course') ? $lesson->course : $lesson->course()->first()) {
            $names->push($lesson->course->name);
        }

        $moduleCourses = collect();

        if ($lesson->relationLoaded('module') ? $lesson->module : $lesson->module()->first()) {
            if ($lesson->module->course) {
                $moduleCourses->push($lesson->module->course);
            }

            $moduleCourses = $moduleCourses
                ->merge($lesson->module->courses)
                ->merge($lesson->module->studyTracks()->with('course')->get()->pluck('course'));
        }

        $moduleCourses = $moduleCourses->merge(
            ($lesson->relationLoaded('modules') ? $lesson->modules : $lesson->modules()->with(['course', 'courses', 'studyTracks.course'])->get())
                ->flatMap(fn (CourseModule $module) => collect([$module->course])
                    ->merge($module->courses)
                    ->merge($module->studyTracks->pluck('course')))
        );

        $trackCourses = collect();

        if ($lesson->relationLoaded('track') ? $lesson->track : $lesson->track()->first()) {
            $trackCourses = $trackCourses
                ->merge($lesson->track->courses)
                ->merge(collect([$lesson->track->module?->course]))
                ->merge($lesson->track->module?->courses ?? collect())
                ->merge($lesson->track->module?->studyTracks()->with('course')->get()->pluck('course') ?? collect());
        }

        $trackCourses = $trackCourses->merge(
            ($lesson->relationLoaded('tracks') ? $lesson->tracks : $lesson->tracks()->with(['courses', 'module.course', 'module.courses', 'module.studyTracks.course'])->get())
                ->flatMap(fn (CourseModuleTrack $track) => $track->courses
                    ->merge(collect([$track->module?->course]))
                    ->merge($track->module?->courses ?? collect())
                    ->merge($track->module?->studyTracks->pluck('course') ?? collect()))
        );

        return $names
            ->merge($moduleCourses->pluck('name'))
            ->merge($trackCourses->pluck('name'))
            ->filter()
            ->unique()
            ->join(', ');
    }

    public static function hasPandaVideo(Lesson $lesson): bool
    {
        $lesson->loadMissing('video');

        return filled($lesson->panda_video_id)
            || filled($lesson->panda_embed_url)
            || filled($lesson->panda_player_url)
            || filled($lesson->video?->provider_video_id)
            || filled($lesson->video?->embed_url)
            || filled($lesson->video?->player_url)
            || filled(data_get($lesson->metadata, 'payload.id'))
            || filled(data_get($lesson->video?->metadata, 'payload.id'));
    }

    public static function aiResourcesStatus(Lesson $lesson): string
    {
        $lesson->loadMissing('video');

        $readyTypes = $lesson->aiArtifacts()
            ->where('status', 'ready')
            ->whereIn('artifact_type', ['summary', 'quiz', 'mindmap'])
            ->pluck('artifact_type')
            ->merge($lesson->video
                ? $lesson->video->aiArtifacts()
                    ->where('status', 'ready')
                    ->whereIn('artifact_type', ['summary', 'quiz', 'mindmap'])
                    ->pluck('artifact_type')
                : collect())
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

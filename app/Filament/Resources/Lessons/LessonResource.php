<?php

namespace App\Filament\Resources\Lessons;

use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

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
            Select::make('course_id')
                ->label('Curso')
                ->options(Course::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('course_module_id', null);
                    $set('course_module_track_id', null);
                })
                ->nullable(),
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
                ->createOptionForm([
                    Select::make('course_id')
                        ->label('Curso')
                        ->options(Course::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->nullable(),
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

                    if (blank($state)) {
                        return;
                    }

                    $module = CourseModule::query()->select('course_id')->find($state);

                    if ($module) {
                        $set('course_id', $module->course_id);
                    }
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
                TextColumn::make('title')->label('Aula')->searchable()->sortable(),
                TextColumn::make('linked_courses')
                    ->label('Curso')
                    ->getStateUsing(fn (Lesson $record): string => self::linkedCourseNames($record))
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('course', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('modules.courses', fn ($query) => $query->where('courses.name', 'like', "%{$search}%"))
                        ->orWhereHas('tracks.courses', fn ($query) => $query->where('courses.name', 'like', "%{$search}%"))),
                TextColumn::make('linked_modules')
                    ->label('Módulo')
                    ->getStateUsing(fn (Lesson $record): string => self::linkedModuleNames($record))
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('module', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('modules', fn ($query) => $query->where('course_modules.name', 'like', "%{$search}%"))),
                TextColumn::make('linked_tracks')
                    ->label('Trilha')
                    ->getStateUsing(fn (Lesson $record): string => self::linkedTrackNames($record))
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('track', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('tracks', fn ($query) => $query->where('course_module_tracks.name', 'like', "%{$search}%"))),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
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
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                        default => $state,
                    }),
                TextColumn::make('source_status')
                    ->label('Mídia')
                    ->badge()
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
                TextColumn::make('duration_minutes')->label('Min')->sortable(query: fn ($query, $direction) => $query->orderBy('duration_seconds', $direction)),
                TextColumn::make('sort_order')->label('Ordem')->sortable(),
                TextColumn::make('panda_video_id')->label('ID do provedor')->searchable()->toggleable(),
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
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

<?php

namespace App\Filament\Resources\Lessons;

use App\Filament\Resources\Lessons\Pages\CreateLesson;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                ->afterStateUpdated(fn (Set $set) => $set('course_module_id', null))
                ->required(),
            Select::make('course_module_id')
                ->label('Módulo')
                ->options(fn (Get $get): array => filled($get('course_id'))
                    ? CourseModule::query()
                        ->where('course_id', $get('course_id'))
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()
                    : [])
                ->searchable()
                ->preload()
                ->createOptionForm([
                    Select::make('course_id')
                        ->label('Curso')
                        ->options(Course::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->required(),
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
                        ->label('ID da pasta no Panda'),
                ])
                ->createOptionUsing(function (array $data): int {
                    return CourseModule::create([
                        ...$data,
                        'is_active' => true,
                    ])->getKey();
                })
                ->afterStateUpdated(function ($state, Set $set): void {
                    if (blank($state)) {
                        return;
                    }

                    $module = CourseModule::query()->select('course_id')->find($state);

                    if ($module) {
                        $set('course_id', $module->course_id);
                    }
                })
                ->required(),
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
            Textarea::make('description')
                ->label('Descrição')
                ->rows(4)
                ->columnSpanFull(),
            TextInput::make('thumbnail_url')
                ->label('URL da thumbnail')
                ->url()
                ->maxLength(2048)
                ->helperText('Pode vir diretamente do Panda Video.'),
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
                ->label('ID do vídeo no Panda')
                ->helperText('Preparado para importação/sincronização com Panda Video.'),
            TextInput::make('panda_embed_url')
                ->label('URL de embed Panda')
                ->url()
                ->maxLength(2048),
            TextInput::make('panda_player_url')
                ->label('URL do player Panda')
                ->url()
                ->maxLength(2048),
            TextInput::make('panda_status')
                ->label('Status no Panda'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Aula')->searchable()->sortable(),
                TextColumn::make('course.name')->label('Curso')->searchable()->sortable(),
                TextColumn::make('module.name')->label('Módulo')->searchable()->sortable(),
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
                TextColumn::make('duration_minutes')->label('Min')->sortable(query: fn ($query, $direction) => $query->orderBy('duration_seconds', $direction)),
                TextColumn::make('sort_order')->label('Ordem')->sortable(),
                TextColumn::make('panda_video_id')->label('Panda ID')->searchable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label('Curso')
                    ->options(Course::query()->orderBy('name')->pluck('name', 'id')),
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
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
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
}

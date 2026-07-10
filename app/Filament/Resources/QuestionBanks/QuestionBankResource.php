<?php

namespace App\Filament\Resources\QuestionBanks;

use App\Filament\Resources\QuestionBanks\Pages\CreateQuestionBank;
use App\Filament\Resources\QuestionBanks\Pages\EditQuestionBank;
use App\Filament\Resources\QuestionBanks\Pages\ListQuestionBanks;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\QuestionBank;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuestionBankResource extends Resource
{
    protected static ?string $model = QuestionBank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Banco de questões';

    protected static ?string $pluralModelLabel = 'Bancos de questões';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Título')
                ->required()
                ->maxLength(255),
            TextInput::make('exam_board')
                ->label('Banca')
                ->placeholder('Ex.: VUNESP')
                ->maxLength(255),
            TextInput::make('exam_year')
                ->label('Ano')
                ->numeric()
                ->minValue(1900)
                ->maxValue(2100),
            TextInput::make('exam_name')
                ->label('Concurso')
                ->placeholder('Ex.: Prefeitura de Santos - Oficial de Administração')
                ->maxLength(255)
                ->columnSpanFull(),
            Select::make('status')
                ->label('Status')
                ->options([
                    'draft' => 'Rascunho',
                    'published' => 'Publicado',
                    'archived' => 'Arquivado',
                ])
                ->default('draft')
                ->required(),
            Select::make('modules')
                ->label('Módulos vinculados')
                ->relationship('modules', 'name')
                ->options(CourseModule::query()->orderBy('name')->pluck('name', 'id'))
                ->multiple()
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                    $moduleIds = collect($state ?? [])->map(fn ($id): int => (int) $id);

                    $set('tracks', collect($get('tracks') ?? [])
                        ->filter(fn ($trackId): bool => CourseModuleTrack::query()
                            ->whereKey((int) $trackId)
                            ->whereIn('course_module_id', $moduleIds)
                            ->exists())
                        ->values()
                        ->all());

                    $set('lessons', collect($get('lessons') ?? [])
                        ->filter(fn ($lessonId): bool => self::lessonMatchesSelectedScope((int) $lessonId, $moduleIds->all(), collect($get('tracks') ?? [])->map(fn ($id): int => (int) $id)->all()))
                        ->values()
                        ->all());
                })
                ->helperText('O banco ficará disponível nos cursos que usam estes módulos.')
                ->columnSpanFull(),
            Select::make('tracks')
                ->label('Trilhas vinculadas')
                ->relationship('tracks', 'name')
                ->options(fn (Get $get): array => self::trackOptionsForModules($get('modules') ?? []))
                ->multiple()
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                    $set('lessons', collect($get('lessons') ?? [])
                        ->filter(fn ($lessonId): bool => self::lessonMatchesSelectedScope((int) $lessonId, collect($get('modules') ?? [])->map(fn ($id): int => (int) $id)->all(), collect($state ?? [])->map(fn ($id): int => (int) $id)->all()))
                        ->values()
                        ->all());
                })
                ->helperText('Use trilhas quando o banco for específico de uma subdivisão do módulo.')
                ->columnSpanFull(),
            Select::make('lessons')
                ->label('Aulas vinculadas')
                ->relationship('lessons', 'title')
                ->options(fn (Get $get): array => self::lessonOptionsForScope($get('modules') ?? [], $get('tracks') ?? []))
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Use aulas quando o banco deve aparecer no dia específico dessas aulas no plano.')
                ->columnSpanFull(),
            FileUpload::make('source_file_path')
                ->label('Arquivo de questões')
                ->disk('local')
                ->directory('question-banks')
                ->preserveFilenames()
                ->acceptedFileTypes([
                    'application/pdf',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->maxSize(20480)
                ->helperText('Envie um PDF ou XLSX. Depois de salvar, use a ação "Importar questões".')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Banco')->searchable()->sortable(),
                TextColumn::make('exam_board')->label('Banca')->searchable()->sortable()->toggleable(),
                TextColumn::make('exam_year')->label('Ano')->sortable()->toggleable(),
                TextColumn::make('exam_name')->label('Concurso')->searchable()->limit(40)->toggleable(),
                TextColumn::make('modules.name')->label('Módulos')->badge()->limitList(3)->toggleable(),
                TextColumn::make('tracks.name')->label('Trilhas')->badge()->limitList(3)->toggleable(),
                TextColumn::make('lessons.title')->label('Aulas')->badge()->limitList(3)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                        default => $state,
                    }),
                TextColumn::make('questions_count')->label('Questões')->counts('questions'),
                TextColumn::make('updated_at')->label('Atualizado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([])
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
            'index' => ListQuestionBanks::route('/'),
            'create' => CreateQuestionBank::route('/create'),
            'edit' => EditQuestionBank::route('/{record}/edit'),
        ];
    }

    protected static function trackOptionsForModules(array $moduleIds): array
    {
        $moduleIds = collect($moduleIds)->map(fn ($id): int => (int) $id)->filter()->values();

        return CourseModuleTrack::query()
            ->when($moduleIds->isNotEmpty(), fn ($query) => $query->whereIn('course_module_id', $moduleIds))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function lessonOptionsForScope(array $moduleIds, array $trackIds): array
    {
        $moduleIds = collect($moduleIds)->map(fn ($id): int => (int) $id)->filter()->values();
        $trackIds = collect($trackIds)->map(fn ($id): int => (int) $id)->filter()->values();

        return Lesson::query()
            ->when($trackIds->isNotEmpty(), fn ($query) => $query->where(function ($query) use ($trackIds): void {
                $query->whereIn('course_module_track_id', $trackIds)
                    ->orWhereHas('tracks', fn ($query) => $query->whereIn('course_module_tracks.id', $trackIds));
            }))
            ->when($trackIds->isEmpty() && $moduleIds->isNotEmpty(), fn ($query) => $query->where(function ($query) use ($moduleIds): void {
                $query->whereIn('course_module_id', $moduleIds)
                    ->orWhereHas('modules', fn ($query) => $query->whereIn('course_modules.id', $moduleIds));
            }))
            ->orderBy('sort_order')
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    protected static function lessonMatchesSelectedScope(int $lessonId, array $moduleIds, array $trackIds): bool
    {
        return array_key_exists($lessonId, self::lessonOptionsForScope($moduleIds, $trackIds));
    }
}

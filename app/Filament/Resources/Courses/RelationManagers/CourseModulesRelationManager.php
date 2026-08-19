<?php

namespace App\Filament\Resources\Courses\RelationManagers;

use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Teacher;
use App\Services\ActiveStudyPlanRefresher;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseModulesRelationManager extends RelationManager
{
    protected static string $relationship = 'modules';

    protected static ?string $title = 'Módulos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required(),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(3)
                ->columnSpanFull(),
            Select::make('teacher_id')
                ->label('Professor cadastrado')
                ->options(fn (): array => Teacher::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function ($state, callable $set): void {
                    $teacherName = filled($state) ? Teacher::query()->whereKey($state)->value('name') : null;

                    if (filled($teacherName)) {
                        $set('teacher_name', $teacherName);
                    }
                }),
            TextInput::make('teacher_name')
                ->label('Professor em texto')
                ->maxLength(255)
                ->helperText('Fallback para importações e módulos antigos sem professor cadastrado.'),
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
                ->required(),
            Textarea::make('lessons')
                ->label('Aulas da trilha')
                ->rows(8)
                ->helperText('Uma aula por linha no formato: Nome da aula|50. A carga horária será recalculada pela soma das aulas.')
                ->formatStateUsing(fn ($state, ?CourseModule $record = null): string => self::formatLessonsForTextarea(
                    is_array($state) && $state !== [] ? $state : ($record?->planning_lessons ?? []),
                ))
                ->dehydrateStateUsing(fn (?string $state): array => self::parseLessonsFromTextarea($state))
                ->afterStateUpdated(function ($state, callable $set): void {
                    $set('workload_minutes', array_sum(array_column(self::parseLessonsFromTextarea($state), 'minutes')));
                })
                ->columnSpanFull(),
            TextInput::make('workload_minutes')
                ->label('Carga horária (minutos)')
                ->numeric()
                ->required(),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0)
                ->required(),
            TextInput::make('panda_folder_id')
                ->label('ID da pasta no provedor')
                ->helperText('Preparado para a sincronização futura com a integração de vídeo.'),
            Toggle::make('is_active')
                ->label('Ativo')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Módulo')->searchable()->sortable(),
                TextColumn::make('teacher.name')->label('Professor')->searchable()->toggleable(),
                TextColumn::make('teacher_name')->label('Professor em texto')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'basic' => 'Matéria Básica',
                        'specific' => 'Conhecimentos Específicos',
                        'complementary' => 'Conhecimentos Complementares',
                        'review' => 'Revisão',
                        'questions' => 'Questões',
                        'other' => 'Outro/Legado',
                        default => 'Outro',
                    }),
                TextColumn::make('lessons_count')
                    ->label('Aulas da trilha')
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('workload_minutes', $direction)),
                TextColumn::make('online_lessons_count')->label('Aulas online')->counts('onlineLessons'),
                TextColumn::make('media_coverage_label')
                    ->label('Mídias')
                    ->badge()
                    ->color(fn ($record): string => match (true) {
                        $record->lessons_count === 0 => 'gray',
                        $record->missing_media_lessons_count === 0 => 'success',
                        $record->imported_media_lessons_count > 0 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('missing_media_lessons_count')
                    ->label('Sem mídia')
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'danger'),
                TextColumn::make('published_lessons_count')
                    ->label('Publicadas')
                    ->badge()
                    ->color(fn ($record): string => $record->lessons_count > 0 && $record->published_lessons_count === $record->lessons_count ? 'success' : 'warning'),
                TextColumn::make('missing_media_lessons_label')
                    ->label('Aulas sem mídia')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('workload_minutes')->label('Minutos')->sortable(),
                TextColumn::make('sort_order')->label('Ordem')->sortable(),
                TextColumn::make('panda_folder_id')->label('Pasta do provedor')->toggleable(),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('course_module_track_id')
                    ->label('Trilha')
                    ->options(fn (): array => CourseModuleTrack::query()
                        ->where(function ($query): void {
                            $query->whereHas('courses', fn ($query) => $query->whereKey($this->getOwnerRecord()->getKey()))
                                ->orWhereHas('module', fn ($query) => $query
                                    ->where('course_id', $this->getOwnerRecord()->getKey())
                                    ->orWhereHas('courses', fn ($query) => $query->whereKey($this->getOwnerRecord()->getKey())));
                        })
                        ->with('module:id,name')
                        ->orderBy('name')
                        ->get(['id', 'course_module_id', 'name'])
                        ->mapWithKeys(fn (CourseModuleTrack $track): array => [
                            $track->id => collect([$track->module?->name, $track->name])->filter()->join(' / '),
                        ])
                        ->sort()
                        ->all())
                    ->searchable()
                    ->preload()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('tracks', fn ($query) => $query->whereKey($data['value']))
                        : $query),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'basic' => 'Básica',
                        'specific' => 'Específica',
                        'complementary' => 'Complementar',
                        'review' => 'Revisão',
                        'questions' => 'Questões',
                        'other' => 'Outro/Legado',
                    ]),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(2)
            ->headerActions([
                CreateAction::make()
                    ->after(fn (): int => app(ActiveStudyPlanRefresher::class)->refreshCourseFromNextWeek($this->getOwnerRecord())),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(fn (): int => app(ActiveStudyPlanRefresher::class)->refreshCourseFromNextWeek($this->getOwnerRecord())),
                DeleteAction::make()
                    ->after(fn (): int => app(ActiveStudyPlanRefresher::class)->refreshCourseFromNextWeek($this->getOwnerRecord())),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->after(fn (): int => app(ActiveStudyPlanRefresher::class)->refreshCourseFromNextWeek($this->getOwnerRecord())),
            ]);
    }

    protected static function formatLessonsForTextarea(array $lessons): string
    {
        return collect($lessons)
            ->map(fn (array $lesson) => trim((string) ($lesson['name'] ?? '')).'|'.(int) ($lesson['minutes'] ?? 0))
            ->implode("\n");
    }

    protected static function parseLessonsFromTextarea(?string $state): array
    {
        return collect(preg_split('/\r\n|\r|\n/', trim((string) $state)) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->map(function (string $line): ?array {
                [$name, $minutes] = array_pad(explode('|', $line, 2), 2, null);
                $minutes = (int) trim((string) $minutes);
                $name = trim((string) $name);

                if ($name === '' || $minutes <= 0) {
                    return null;
                }

                return [
                    'name' => $name,
                    'minutes' => $minutes,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}

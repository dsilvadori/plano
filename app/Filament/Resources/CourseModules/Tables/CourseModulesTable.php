<?php

namespace App\Filament\Resources\CourseModules\Tables;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CourseModulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('courses.name')
                    ->label('Cursos')
                    ->placeholder('Não agrupado')
                    ->searchable()
                    ->sortable(),
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
                TextColumn::make('tracks_count')->label('Trilhas')->counts('tracks'),
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
                SelectFilter::make('course_id')
                    ->label('Curso')
                    ->options(fn (): array => self::courseFilterOptions())
                    ->searchable()
                    ->preload()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(function ($query) use ($data): void {
                            $query->where('course_id', $data['value'])
                                ->orWhereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $data['value']))
                                ->orWhereHas('tracks.courses', fn ($query) => $query->whereKey($data['value']));
                        })
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
                SelectFilter::make('course_module_track_id')
                    ->label('Trilha')
                    ->options(fn ($livewire): array => self::trackFilterOptions(
                        self::selectedFilterValue($livewire, 'course_id'),
                    ))
                    ->searchable()
                    ->preload()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('tracks', fn ($query) => $query->whereKey($data['value']))
                        : $query),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function courseFilterOptions(): array
    {
        return Course::query()
            ->orderBy('name')
            ->pluck('name', 'id')
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

    public static function trackFilterOptions(mixed $courseId = null): array
    {
        return CourseModuleTrack::query()
            ->with('module:id,name')
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
}

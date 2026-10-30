<?php

namespace App\Filament\Resources\StudyTracks\Tables;

use App\Models\Course;
use App\Models\CourseModule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StudyTracksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.name')->label('Curso')->searchable()->sortable(),
                TextColumn::make('name')->label('Trilha')->searchable()->sortable(),
                TextColumn::make('modules_count')->label('Módulos')->counts('modules'),
                IconColumn::make('is_active')->label('Ativa')->boolean(),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label('Curso')
                    ->options(fn (): array => Course::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
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
                        ? $query->whereHas('modules', fn ($query) => $query->whereKey($data['value']))
                        : $query),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(2)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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

    protected static function selectedFilterValue(mixed $livewire, string $filter): mixed
    {
        return data_get($livewire, "tableFilters.{$filter}.value");
    }
}

<?php

namespace App\Filament\Resources\Courses\RelationManagers;

use App\Services\ActiveStudyPlanRefresher;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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

class StudyTracksRelationManager extends RelationManager
{
    protected static string $relationship = 'studyTracks';

    protected static ?string $title = 'Trilhas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required(),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(4)
                ->columnSpanFull(),
            CheckboxList::make('modules')
                ->label('Módulos da trilha')
                ->relationship('modules', 'name')
                ->options(fn () => $this->getOwnerRecord()->modules()->orderBy('sort_order')->pluck('name', 'id')->all())
                ->columns(2)
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Ativa')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Trilha')->searchable()->sortable(),
                TextColumn::make('modules_count')->label('Módulos')->counts('modules'),
                IconColumn::make('is_active')->label('Ativa')->boolean(),
            ])
            ->filters([
                SelectFilter::make('course_module_id')
                    ->label('Módulo')
                    ->options(fn (): array => $this->getOwnerRecord()
                        ->modules()
                        ->orderBy('course_modules.name')
                        ->pluck('name', 'course_modules.id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('modules', fn ($query) => $query->whereKey($data['value']))
                        : $query),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(1)
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $data + [
                        'course_id' => $this->getOwnerRecord()->getKey(),
                    ])
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
}

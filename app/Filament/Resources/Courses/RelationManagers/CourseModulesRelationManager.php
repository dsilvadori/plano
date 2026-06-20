<?php

namespace App\Filament\Resources\Courses\RelationManagers;

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
                ->formatStateUsing(fn ($state): string => self::formatLessonsForTextarea(is_array($state) ? $state : []))
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
                ->label('ID da pasta no Panda')
                ->helperText('Preparado para a sincronização futura com Panda Video.'),
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
                TextColumn::make('workload_minutes')->label('Minutos')->sortable(),
                TextColumn::make('sort_order')->label('Ordem')->sortable(),
                TextColumn::make('panda_folder_id')->label('Pasta Panda')->toggleable(),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'basic' => 'Básica',
                        'specific' => 'Específica',
                        'complementary' => 'Complementar',
                        'review' => 'Revisão',
                        'questions' => 'Questões',
                        'other' => 'Outro/Legado',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $data + [
                        'course_id' => $this->getOwnerRecord()->getKey(),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    protected static function formatLessonsForTextarea(array $lessons): string
    {
        return collect($lessons)
            ->map(fn (array $lesson) => trim((string) ($lesson['name'] ?? '')) . '|' . (int) ($lesson['minutes'] ?? 0))
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

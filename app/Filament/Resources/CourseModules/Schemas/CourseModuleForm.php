<?php

namespace App\Filament\Resources\CourseModules\Schemas;

use App\Models\Course;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CourseModuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('course_id')
                    ->label('Curso')
                    ->options(Course::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
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
                    ->required()
                    ->helperText('Exemplo: 120 minutos = 2 horas. Quando há aulas cadastradas, usamos a soma delas.'),
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

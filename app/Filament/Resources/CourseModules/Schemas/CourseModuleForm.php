<?php

namespace App\Filament\Resources\CourseModules\Schemas;

use App\Models\Course;
use Filament\Forms\Components\Select;
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
                Select::make('type')
                    ->label('Tipo')
                    ->options([
                        'basic' => 'Matéria Básica',
                        'specific' => 'Conhecimentos Específicos',
                        'review' => 'Revisão',
                        'questions' => 'Questões',
                        'other' => 'Outro',
                    ])
                    ->required(),
                TextInput::make('workload_minutes')
                    ->label('Carga horária (minutos)')
                    ->numeric()
                    ->required()
                    ->helperText('Exemplo: 120 minutos = 2 horas.'),
                TextInput::make('sort_order')
                    ->label('Ordem')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true),
            ]);
    }
}

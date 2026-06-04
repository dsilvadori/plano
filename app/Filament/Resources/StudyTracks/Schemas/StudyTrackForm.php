<?php

namespace App\Filament\Resources\StudyTracks\Schemas;

use App\Models\Course;
use App\Models\CourseModule;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class StudyTrackForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('course_id')
                    ->label('Curso')
                    ->options(Course::query()->orderBy('name')->pluck('name', 'id'))
                    ->live()
                    ->required(),
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
                    ->options(function ($get) {
                        $courseId = $get('course_id');

                        return $courseId
                            ? CourseModule::query()->where('course_id', $courseId)->orderBy('sort_order')->pluck('name', 'id')
                            : [];
                    })
                    ->columns(2)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Ativa')
                    ->default(true),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Courses\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->unique(ignoreRecord: true),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(4)
                    ->columnSpanFull(),
                TextInput::make('tutory_product_id')
                    ->label('ID do produto na Tutory'),
                DatePicker::make('exam_date')
                    ->label('Data da prova do curso')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText('Se este curso já tem prova definida, o aluno verá essa data preenchida e bloqueada no plano.'),
                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Course;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Nome')->required(),
                TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('phone')->label('Telefone'),
                Select::make('role')
                    ->label('Perfil')
                    ->options([
                        'admin' => 'Administrador',
                        'student' => 'Aluno',
                        'subscriber' => 'Assinante',
                    ])
                    ->required(),
                TextInput::make('tutory_customer_id')->label('ID do cliente na Tutory'),
                Select::make('course_ids')
                    ->label('Cursos liberados')
                    ->multiple()
                    ->options(fn (): array => Course::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Select $component, ?User $record): void {
                        if (! $record?->exists) {
                            return;
                        }

                        $component->state($record->courses()->pluck('courses.id')->map(fn ($id) => (string) $id)->all());
                    })
                    ->helperText('Opcional. Para alunos, libera o acesso manual aos cursos selecionados.'),
                TextInput::make('password')
                    ->label('Senha')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('Opcional. Para alunos e assinantes, o sistema envia um e-mail de primeiro acesso para criarem a própria senha.'),
            ]);
    }
}

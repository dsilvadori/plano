<?php

namespace App\Filament\Resources\Users\Schemas;

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
                        'admin' => 'Admin',
                        'student' => 'Aluno',
                    ])
                    ->required(),
                TextInput::make('tutory_customer_id')->label('Tutory Customer ID'),
                TextInput::make('password')
                    ->label('Senha')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create'),
            ]);
    }
}

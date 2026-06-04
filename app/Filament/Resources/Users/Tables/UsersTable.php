<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Facades\Password;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable(),
                TextColumn::make('role')->label('Perfil')->badge(),
                TextColumn::make('phone')->label('Telefone')->toggleable(),
                TextColumn::make('courses.name')->label('Curso')->badge()->separator(', '),
                TextColumn::make('last_login_at')->label('Último login')->since()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'student' => 'Aluno',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('sendReset')
                    ->label('Reenviar acesso')
                    ->action(function ($record) {
                        $token = Password::broker()->createToken($record);
                        $record->sendSetPasswordNotification($token);

                        Notification::make()->title('E-mail de criação de senha reenviado.')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

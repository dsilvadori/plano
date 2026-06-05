<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\Course;
use App\Services\ManualStudentCourseLinker;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
                TextColumn::make('role')
                    ->label('Perfil')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'Administrador',
                        'student' => 'Aluno',
                        default => $state,
                    }),
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
                Action::make('linkCourses')
                    ->label('Vincular curso')
                    ->icon('heroicon-o-link')
                    ->visible(fn ($record) => $record->isStudent())
                    ->modalHeading('Vincular curso ao aluno')
                    ->modalDescription('Use esta ação quando o webhook falhar e você precisar liberar manualmente um ou mais cursos para o aluno.')
                    ->form([
                        Select::make('course_ids')
                            ->label('Cursos')
                            ->options(Course::query()->orderBy('name')->pluck('name', 'id'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(function ($record, array $data, ManualStudentCourseLinker $linker) {
                        $linker->link($record, $data['course_ids'] ?? []);

                        Notification::make()
                            ->title('Cursos vinculados com sucesso.')
                            ->success()
                            ->send();
                    }),
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

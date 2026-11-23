<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Course;
use App\Models\StudentCourse;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if ($state === 'subscriber' && blank($get('access_expires_at'))) {
                            $set('access_expires_at', StudentCourse::defaultExpiration()->toDateTimeString());
                        }
                    })
                    ->required(),
                DateTimePicker::make('access_expires_at')
                    ->label('Assinatura válida até')
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->timezone('America/Sao_Paulo')
                    ->default(fn (): string => StudentCourse::defaultExpiration()->toDateTimeString())
                    ->visible(fn (Get $get): bool => $get('role') === 'subscriber')
                    ->helperText('Assinantes acessam todos os cursos ativos até esta data. Em branco = sem limite.'),
                TextInput::make('tutory_customer_id')->label('ID do cliente na Tutory'),
                Select::make('course_ids')
                    ->label('Cursos liberados')
                    ->multiple()
                    ->options(fn (): array => Course::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Course $course): array => [
                            $course->id => $course->is_active
                                ? $course->name
                                : "{$course->name} (inativo/provisório)",
                        ])
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
                    ->helperText('Opcional. Para alunos, libera o acesso manual aos cursos selecionados, com 1 ano de prazo. Ajuste os prazos na aba "Cursos do aluno" abaixo.'),
                TextInput::make('password')
                    ->label('Senha')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('Opcional. Para alunos e assinantes, o sistema envia um e-mail de primeiro acesso para criarem a própria senha.'),
            ]);
    }
}

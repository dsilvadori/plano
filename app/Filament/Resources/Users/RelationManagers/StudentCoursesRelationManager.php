<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\StudentCourse;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class StudentCoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'studentCourses';

    protected static ?string $title = 'Cursos do aluno e prazos de acesso';

    protected static ?string $modelLabel = 'acesso ao curso';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->isStudent();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('expires_at')
                ->label('Acesso até')
                ->native(false)
                ->seconds(false)
                ->displayFormat('d/m/Y H:i')
                ->timezone('America/Sao_Paulo')
                ->helperText('Deixe em branco para acesso sem limite. Depois dessa data o curso some da área do aluno.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('course'))
            ->columns([
                TextColumn::make('course.name')->label('Curso')->searchable()->wrap(),
                TextColumn::make('source')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'tutory' => 'Tutory',
                        'manual' => 'Manual',
                        'import' => 'Planilha',
                        default => (string) $state,
                    }),
                TextColumn::make('created_at')
                    ->label('Liberado em')
                    ->dateTime('d/m/Y', 'America/Sao_Paulo')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Acesso até')
                    ->dateTime('d/m/Y H:i', 'America/Sao_Paulo')
                    ->placeholder('Sem limite')
                    ->badge()
                    ->color(fn (StudentCourse $record): string => match (true) {
                        $record->expires_at === null => 'gray',
                        $record->isExpired() => 'danger',
                        $record->expires_at->lessThan(now()->addDays(30)) => 'warning',
                        default => 'success',
                    })
                    ->description(fn (StudentCourse $record): ?string => $record->isExpired() ? 'Expirado' : null)
                    ->sortable(),
            ])
            ->defaultSort('expires_at')
            ->filters([
                TernaryFilter::make('expired')
                    ->label('Situação')
                    ->placeholder('Todos')
                    ->trueLabel('Expirados')
                    ->falseLabel('Em dia')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
                        false: fn (Builder $query) => $query->active(),
                    ),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Alterar prazo')
                    ->modalHeading(fn (StudentCourse $record): string => 'Prazo de acesso: '.$record->course?->name),
                Action::make('renew')
                    ->label('Renovar 1 ano')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('O acesso passa a valer por 1 ano a partir de hoje.')
                    ->action(function (StudentCourse $record): void {
                        $record->forceFill(['expires_at' => StudentCourse::defaultExpiration()])->save();

                        Notification::make()->title('Acesso renovado por 1 ano.')->success()->send();
                    }),
                DeleteAction::make()->label('Remover acesso'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('renewSelected')
                        ->label('Renovar 1 ano')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $records->each(fn (StudentCourse $record) => $record
                                ->forceFill(['expires_at' => StudentCourse::defaultExpiration()])
                                ->save());

                            Notification::make()->title('Acessos renovados por 1 ano.')->success()->send();
                        }),
                    DeleteBulkAction::make()->label('Remover acessos'),
                ]),
            ]);
    }
}

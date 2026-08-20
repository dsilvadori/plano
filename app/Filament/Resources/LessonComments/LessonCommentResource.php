<?php

namespace App\Filament\Resources\LessonComments;

use App\Filament\Resources\LessonComments\Pages\EditLessonComment;
use App\Filament\Resources\LessonComments\Pages\ListLessonComments;
use App\Models\LessonComment;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LessonCommentResource extends Resource
{
    protected static ?string $model = LessonComment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Comentário';

    protected static ?string $pluralModelLabel = 'Comentários';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('student')
                ->label('Aluno')
                ->content(fn (?LessonComment $record): string => $record?->user?->name.' <'.$record?->user?->email.'>'),
            Placeholder::make('lesson')
                ->label('Aula')
                ->content(fn (?LessonComment $record): string => collect([$record?->course?->name, $record?->lesson?->title])->filter()->join(' / ')),
            Placeholder::make('body')
                ->label('Comentário do aluno')
                ->content(fn (?LessonComment $record): string => (string) $record?->body)
                ->columnSpanFull(),
            Textarea::make('answer')
                ->label('Resposta da equipe')
                ->rows(6)
                ->maxLength(4000)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'course', 'lesson', 'answeredBy']))
            ->columns([
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'answered' ? 'Respondido' : 'Aberto')
                    ->color(fn (string $state): string => $state === 'answered' ? 'success' : 'warning'),
                TextColumn::make('user.name')->label('Aluno')->searchable()->sortable(),
                TextColumn::make('course.name')->label('Curso')->searchable()->toggleable(),
                TextColumn::make('lesson.title')->label('Aula')->searchable()->limit(42),
                TextColumn::make('body')->label('Comentário')->limit(70)->searchable(),
                TextColumn::make('answeredBy.name')->label('Respondido por')->toggleable(),
                TextColumn::make('created_at')->label('Criado em')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'open' => 'Aberto',
                        'answered' => 'Respondido',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()->label('Responder'),
                DeleteAction::make()->label('Excluir'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLessonComments::route('/'),
            'edit' => EditLessonComment::route('/{record}/edit'),
        ];
    }
}

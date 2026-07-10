<?php

namespace App\Filament\Resources\Questions;

use App\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Filament\Resources\Questions\Pages\EditQuestion;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Jobs\GenerateQuestionCommentary;
use App\Models\Question;
use App\Models\QuestionBank;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Questão';

    protected static ?string $pluralModelLabel = 'Questões';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('question_bank_id')
                ->label('Banco')
                ->options(QuestionBank::query()->orderBy('title')->pluck('title', 'id'))
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('number')
                ->label('Número')
                ->numeric(),
            Select::make('status')
                ->label('Status')
                ->options([
                    'review' => 'Em revisão',
                    'published' => 'Publicada',
                    'draft' => 'Rascunho',
                    'archived' => 'Arquivada',
                ])
                ->default('review')
                ->required(),
            Select::make('type')
                ->label('Tipo')
                ->options([
                    'multiple_choice' => 'Múltipla escolha',
                    'true_false' => 'Certo/Errado',
                    'discursive' => 'Discursiva',
                ])
                ->default('multiple_choice')
                ->required(),
            TextInput::make('subject')
                ->label('Disciplina'),
            TextInput::make('topic')
                ->label('Assunto'),
            TextInput::make('subtopic')
                ->label('Subassunto'),
            TextInput::make('source_reference')
                ->label('Origem/referência')
                ->columnSpanFull(),
            Textarea::make('statement')
                ->label('Enunciado')
                ->rows(8)
                ->required()
                ->columnSpanFull(),
            Select::make('answer_key')
                ->label('Gabarito')
                ->options([
                    'a' => 'A',
                    'b' => 'B',
                    'c' => 'C',
                    'd' => 'D',
                    'e' => 'E',
                    'certo' => 'Certo',
                    'errado' => 'Errado',
                ])
                ->searchable(),
            Textarea::make('commentary')
                ->label('Comentário')
                ->helperText('Pode ser preenchido manualmente ou editado após geração por IA.')
                ->rows(6)
                ->columnSpanFull(),
            TextInput::make('commentary_provider')
                ->label('Origem do comentário')
                ->helperText('Ex.: manual, gemini.')
                ->maxLength(255),
            Repeater::make('options')
                ->label('Alternativas')
                ->relationship()
                ->schema([
                    TextInput::make('label')
                        ->label('Letra')
                        ->required()
                        ->maxLength(5),
                    Textarea::make('text')
                        ->label('Texto')
                        ->rows(2)
                        ->required(),
                    Select::make('is_correct')
                        ->label('Correta?')
                        ->options([
                            false => 'Não',
                            true => 'Sim',
                        ])
                        ->default(false)
                        ->required(),
                    TextInput::make('sort_order')
                        ->label('Ordem')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(4)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nº')->sortable(),
                TextColumn::make('bank.title')->label('Banco')->searchable()->sortable(),
                TextColumn::make('topic')->label('Assunto')->searchable()->toggleable(),
                TextColumn::make('statement')->label('Enunciado')->limit(80)->searchable(),
                TextColumn::make('answer_key')->label('Gabarito')->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'review' => 'Revisão',
                        'published' => 'Publicada',
                        'draft' => 'Rascunho',
                        'archived' => 'Arquivada',
                        default => $state,
                    }),
            ])
            ->filters([
                SelectFilter::make('question_bank_id')
                    ->label('Banco')
                    ->options(QuestionBank::query()->orderBy('title')->pluck('title', 'id')),
                SelectFilter::make('status')
                    ->options([
                        'review' => 'Em revisão',
                        'published' => 'Publicada',
                        'draft' => 'Rascunho',
                        'archived' => 'Arquivada',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('generateCommentaries')
                        ->label('Gerar comentários com IA')
                        ->icon('heroicon-o-sparkles')
                        ->requiresConfirmation()
                        ->modalHeading('Gerar comentários em massa')
                        ->modalDescription('As questões selecionadas serão enviadas para a fila. Os comentários continuarão editáveis pelo admin após a geração.')
                        ->action(function (Collection $records): void {
                            $queued = 0;
                            $skipped = 0;

                            foreach ($records as $question) {
                                if (blank($question->answer_key)) {
                                    $skipped++;

                                    continue;
                                }

                                GenerateQuestionCommentary::dispatch($question->id);

                                $queued++;
                            }

                            $notification = Notification::make()
                                ->title($queued > 0 ? 'Comentários enviados para geração.' : 'Nenhuma questão foi enviada.')
                                ->body("Enfileiradas: {$queued}. Sem gabarito: {$skipped}.");

                            ($queued > 0 ? $notification->success() : $notification->warning())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('publish')
                        ->label('Publicar selecionadas')
                        ->icon('heroicon-o-eye')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'published']))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('review')
                        ->label('Enviar para revisão')
                        ->icon('heroicon-o-pencil-square')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'review']))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuestions::route('/'),
            'create' => CreateQuestion::route('/create'),
            'edit' => EditQuestion::route('/{record}/edit'),
        ];
    }
}

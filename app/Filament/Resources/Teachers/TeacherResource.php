<?php

namespace App\Filament\Resources\Teachers;

use App\Filament\Resources\Teachers\Pages\CreateTeacher;
use App\Filament\Resources\Teachers\Pages\EditTeacher;
use App\Filament\Resources\Teachers\Pages\ListTeachers;
use App\Models\Teacher;
use App\Support\FilamentThumbnailUpload;
use App\Support\ThumbnailUrl;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class TeacherResource extends Resource
{
    protected static ?string $model = Teacher::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Professor';

    protected static ?string $pluralModelLabel = 'Professores';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            TextInput::make('thumbnail_url')
                ->label('URL da thumbnail')
                ->url()
                ->maxLength(2048)
                ->helperText('Usada como fallback quando nenhum arquivo for enviado.'),
            Hidden::make('thumbnail_path'),
            Placeholder::make('thumbnail_preview')
                ->label('Thumbnail atual')
                ->content(function (Get $get, ?Teacher $record): HtmlString {
                    $path = (string) ($get('thumbnail_path') ?: $record?->thumbnail_path ?: '');
                    $url = ThumbnailUrl::fromPathOrUrl($path, (string) ($get('thumbnail_url') ?: $record?->thumbnail_url ?: ''));

                    return new HtmlString('<img src="'.e($url).'" alt="" style="height: 180px; width: 96px; object-fit: cover; object-position: center 40%; border-radius: 8px;">');
                })
                ->columnSpanFull(),
            FileUpload::make('thumbnail_upload')
                ->label('Enviar nova thumbnail')
                ->image()
                ->imageEditor()
                ->imagePreviewHeight('180')
                ->storeFiles(false)
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    $path = FilamentThumbnailUpload::store($state, 'teacher-thumbnails');

                    if ($path !== null) {
                        $set('thumbnail_path', $path);
                    }
                })
                ->dehydrated(false)
                ->maxSize(4096)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->helperText('Ao enviar um arquivo, ele tem prioridade sobre a URL da thumbnail.')
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Ativo')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail_display_url')
                    ->label('Thumb')
                    ->height(72)
                    ->width(40),
                TextColumn::make('name')->label('Professor')->searchable()->sortable(),
                TextColumn::make('tracks_count')->label('Trilhas')->counts('tracks'),
                IconColumn::make('is_active')->label('Ativo')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
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
            'index' => ListTeachers::route('/'),
            'create' => CreateTeacher::route('/create'),
            'edit' => EditTeacher::route('/{record}/edit'),
        ];
    }
}

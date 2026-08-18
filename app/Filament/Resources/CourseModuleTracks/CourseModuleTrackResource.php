<?php

namespace App\Filament\Resources\CourseModuleTracks;

use App\Filament\Resources\CourseModuleTracks\Pages\CreateCourseModuleTrack;
use App\Filament\Resources\CourseModuleTracks\Pages\EditCourseModuleTrack;
use App\Filament\Resources\CourseModuleTracks\Pages\ListCourseModuleTracks;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Support\FilamentThumbnailUpload;
use App\Support\ThumbnailUrl;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class CourseModuleTrackResource extends Resource
{
    protected static ?string $model = CourseModuleTrack::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Acadêmico';

    protected static ?string $modelLabel = 'Trilha';

    protected static ?string $pluralModelLabel = 'Trilhas';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('course_module_id')
                ->label('Módulo')
                ->options(CourseModule::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')
                ->label('Slug')
                ->required(),
            Textarea::make('description')
                ->label('Descrição')
                ->rows(3)
                ->columnSpanFull(),
            TextInput::make('thumbnail_url')
                ->label('URL da thumbnail')
                ->url()
                ->maxLength(2048)
                ->helperText('Usada como fallback quando nenhum arquivo for enviado.'),
            Select::make('metadata.thumbnail_aspect_ratio')
                ->label('Proporção da thumbnail')
                ->options(CourseModuleTrack::thumbnailAspectRatioOptions())
                ->default('300/580')
                ->helperText('Padrão atual: 300x580px. Use outra proporção se a arte da trilha tiver formato diferente.'),
            Hidden::make('thumbnail_path'),
            Placeholder::make('thumbnail_preview')
                ->label('Thumbnail atual')
                ->content(function (Get $get, ?CourseModuleTrack $record): HtmlString {
                    $path = (string) ($get('thumbnail_path') ?: $record?->thumbnail_path ?: '');
                    $url = ThumbnailUrl::fromPathOrUrl($path, (string) ($get('thumbnail_url') ?: $record?->thumbnail_url ?: ''));
                    $ratio = (string) ($get('metadata.thumbnail_aspect_ratio') ?: $record?->thumbnail_aspect_ratio ?: '300/580');

                    return new HtmlString('<img src="'.e($url).'" alt="" style="height: 180px; max-width: 240px; aspect-ratio: '.e($ratio).'; object-fit: cover; border-radius: 8px;">');
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
                    $path = FilamentThumbnailUpload::store($state, 'track-thumbnails');

                    if ($path !== null) {
                        $set('thumbnail_path', $path);
                    }
                })
                ->dehydrated(false)
                ->maxSize(4096)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->helperText('Ao enviar um arquivo, ele tem prioridade sobre a URL da thumbnail.')
                ->columnSpanFull(),
            TextInput::make('sort_order')
                ->label('Ordem')
                ->numeric()
                ->default(0)
                ->required(),
            Select::make('status')
                ->label('Status')
                ->options([
                    'draft' => 'Rascunho',
                    'published' => 'Publicado',
                    'archived' => 'Arquivado',
                ])
                ->default('draft')
                ->required(),
            TextInput::make('panda_folder_id')
                ->label('ID da pasta/playlist no Panda'),
            TextInput::make('google_doc_url')
                ->label('Google Docs')
                ->url()
                ->maxLength(2048),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail_display_url')
                    ->label('Thumb')
                    ->size(56)
                    ->square(),
                TextColumn::make('name')->label('Trilha')->searchable()->sortable(),
                TextColumn::make('module.name')->label('Módulo')->searchable()->sortable(),
                TextColumn::make('courses_count')->label('Cursos')->counts('courses'),
                TextColumn::make('lessons_count')->label('Aulas')->counts('lessons'),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('sort_order')->label('Ordem')->sortable(),
                TextColumn::make('panda_folder_id')->label('Pasta Panda')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label('Curso')
                    ->options(fn (): array => Course::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where(function ($query) use ($data): void {
                            $query->whereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                ->orWhereHas('module', fn ($query) => $query
                                    ->where('course_id', $data['value'])
                                    ->orWhereHas('courses', fn ($query) => $query->whereKey($data['value']))
                                    ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $data['value'])));
                        })
                        : $query),
                SelectFilter::make('course_module_id')
                    ->label('Módulo')
                    ->options(fn ($livewire): array => self::moduleFilterOptions(
                        self::selectedFilterValue($livewire, 'course_id'),
                    ))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                    ]),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish')
                        ->label('Publicar selecionadas')
                        ->icon('heroicon-o-eye')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'published']))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('unpublish')
                        ->label('Despublicar selecionadas')
                        ->icon('heroicon-o-eye-slash')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => 'draft']))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function moduleFilterOptions(mixed $courseId = null): array
    {
        return CourseModule::query()
            ->when(filled($courseId), fn ($query) => $query->where(function ($query) use ($courseId): void {
                $query->where('course_id', $courseId)
                    ->orWhereHas('courses', fn ($query) => $query->whereKey($courseId))
                    ->orWhereHas('studyTracks', fn ($query) => $query->where('course_id', $courseId))
                    ->orWhereHas('tracks.courses', fn ($query) => $query->whereKey($courseId));
            }))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function selectedFilterValue(mixed $livewire, string $filter): mixed
    {
        return data_get($livewire, "tableFilters.{$filter}.value");
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseModuleTracks::route('/'),
            'create' => CreateCourseModuleTrack::route('/create'),
            'edit' => EditCourseModuleTrack::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use App\Models\CourseSphere;
use App\Models\EducationLevel;
use App\Support\FilamentThumbnailUpload;
use App\Support\ThumbnailUrl;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->unique(ignoreRecord: true),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(4)
                    ->columnSpanFull(),
                Textarea::make('short_description')
                    ->label('Descrição curta')
                    ->rows(2)
                    ->helperText('Resumo usado em cards, home e listagens de cursos.')
                    ->columnSpanFull(),
                TextInput::make('thumbnail_url')
                    ->label('URL da thumbnail')
                    ->url()
                    ->maxLength(2048)
                    ->helperText('Usada como fallback quando nenhum arquivo for enviado.'),
                Hidden::make('thumbnail_path'),
                Placeholder::make('thumbnail_preview')
                    ->label('Thumbnail atual')
                    ->content(function (Get $get, ?Course $record): HtmlString {
                        $path = (string) ($get('thumbnail_path') ?: $record?->thumbnail_path ?: '');
                        $url = ThumbnailUrl::fromPathOrUrl($path, (string) ($get('thumbnail_url') ?: $record?->thumbnail_url ?: ''));

                        return new HtmlString('<img src="' . e($url) . '" alt="" style="height: 160px; width: 280px; max-width: 100%; object-fit: contain; border-radius: 12px; background: #020617; padding: 8px;">');
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
                        $path = FilamentThumbnailUpload::store($state, 'course-thumbnails');

                        if ($path !== null) {
                            $set('thumbnail_path', $path);
                        }
                    })
                    ->dehydrated(false)
                    ->maxSize(4096)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->helperText('Ao enviar um arquivo, ele tem prioridade sobre a URL da thumbnail.')
                    ->columnSpanFull(),
                TextInput::make('checkout_url')
                    ->label('URL do checkout')
                    ->url()
                    ->maxLength(2048),
                Select::make('sphere_id')
                    ->label('Esfera')
                    ->options(fn () => CourseSphere::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload(),
                Select::make('education_level_id')
                    ->label('Escolaridade')
                    ->options(fn () => EducationLevel::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload(),
                Select::make('status')
                    ->label('Status do catálogo')
                    ->options([
                        'draft' => 'Rascunho',
                        'published' => 'Publicado',
                        'archived' => 'Arquivado',
                    ])
                    ->default('published')
                    ->required(),
                TextInput::make('tutory_product_id')
                    ->label('ID do produto na Tutory'),
                TextInput::make('combo_name')
                    ->label('Combo')
                    ->helperText('Use vírgula para mais de um combo. Quando o produto comprado/importado tiver um desses nomes, o aluno será vinculado a todos os cursos ativos desse combo. Ex.: Gabaritando Prefeitura de Santos, Combo Santos.'),
                DatePicker::make('exam_date')
                    ->label('Data da prova do curso')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->helperText('Se este curso já tem prova definida, o aluno verá essa data preenchida e bloqueada no plano.'),
                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true),
                Toggle::make('is_featured')
                    ->label('Destaque na home')
                    ->default(false),
                TextInput::make('sort_order')
                    ->label('Ordem no catálogo')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }
}

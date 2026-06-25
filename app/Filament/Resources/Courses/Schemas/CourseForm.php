<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\CourseSphere;
use App\Models\EducationLevel;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
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
                FileUpload::make('thumbnail_path')
                    ->label('Thumbnail por arquivo')
                    ->image()
                    ->imageEditor()
                    ->imagePreviewHeight('180')
                    ->disk('public')
                    ->directory('course-thumbnails')
                    ->visibility('public')
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

<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Jobs\ImportGoogleDriveLessons;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\GoogleDriveImportRun;
use App\Services\PandaCourseImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Throwable;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    protected const NEW_MODULE_PREFIX = '__new_module__:';

    protected const NEW_TRACK_PREFIX = '__new_track__:';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPandaLessons')
                ->label('Importar Panda')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar aulas do Panda')
                ->modalDescription('Informe uma pasta do Panda. Os vídeos serão criados ou atualizados como aulas reutilizáveis da biblioteca, com pasta e subpasta opcionais para organização interna.')
                ->form([
                    Select::make('course_module_id')
                        ->label('Pasta')
                        ->options(fn (): array => $this->moduleOptions(null))
                        ->getSearchResultsUsing(fn (?string $search): array => $this->moduleSearchResults(null, $search))
                        ->getOptionLabelUsing(fn ($value): ?string => $this->moduleOptionLabel($value))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->nullable()
                        ->helperText('Opcional. Digite um nome e pressione Enter para criar uma pasta nova.')
                        ->afterStateUpdated(fn (Set $set) => $set('course_module_track_id', null)),
                    Select::make('course_module_track_id')
                        ->label('Subpasta')
                        ->options(fn (Get $get): array => $this->trackOptions($get('course_module_id')))
                        ->getSearchResultsUsing(fn (Get $get, ?string $search): array => $this->trackSearchResults($get('course_module_id'), $search))
                        ->getOptionLabelUsing(fn ($value): ?string => $this->trackOptionLabel($value))
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->helperText('Opcional. Digite um nome e pressione Enter para criar uma subpasta na pasta selecionada.')
                        ->afterStateUpdated(fn ($state, Set $set) => $this->syncModuleFromTrack($state, $set)),
                    TextInput::make('panda_folder_id')
                        ->label('Pasta no Panda')
                        ->helperText('Aceita URL completa, ID ou nome da pasta.')
                        ->required(),
                    Select::make('lesson_status')
                        ->label('Status inicial das aulas')
                        ->options([
                            'draft' => 'Rascunho',
                            'published' => 'Publicado',
                        ])
                        ->default('published')
                        ->required(),
                ])
                ->action(function (array $data, PandaCourseImporter $importer): void {
                    try {
                        $course = null;
                        [$module, $track] = $this->resolveImportStructure($data, $course);

                        $run = $importer->importLessons(
                            $course,
                            $module,
                            $track,
                            (string) $data['panda_folder_id'],
                            (string) ($data['lesson_status'] ?? 'published'),
                        );

                        Notification::make()
                            ->title('Aulas importadas do Panda.')
                            ->body('Vídeos: '.($run->summary['videos'] ?? 0).'. Criadas: '.($run->summary['created'] ?? 0).'. Atualizadas: '.($run->summary['updated'] ?? 0).'.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar aulas do Panda.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('importGoogleDriveLessons')
                ->label('Importar Drive')
                ->icon('heroicon-o-folder')
                ->modalHeading('Importar aulas do Google Drive')
                ->modalDescription('Informe uma pasta do Drive. Os arquivos dentro dela serão criados ou atualizados como aulas reutilizáveis da biblioteca, com pasta e subpasta opcionais para organização interna.')
                ->form([
                    Select::make('course_module_id')
                        ->label('Pasta')
                        ->options(fn (): array => $this->moduleOptions(null))
                        ->getSearchResultsUsing(fn (?string $search): array => $this->moduleSearchResults(null, $search))
                        ->getOptionLabelUsing(fn ($value): ?string => $this->moduleOptionLabel($value))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->nullable()
                        ->helperText('Opcional. Digite um nome e pressione Enter para criar uma pasta nova.')
                        ->afterStateUpdated(fn (Set $set) => $set('course_module_track_id', null)),
                    Select::make('course_module_track_id')
                        ->label('Subpasta')
                        ->options(fn (Get $get): array => $this->trackOptions($get('course_module_id')))
                        ->getSearchResultsUsing(fn (Get $get, ?string $search): array => $this->trackSearchResults($get('course_module_id'), $search))
                        ->getOptionLabelUsing(fn ($value): ?string => $this->trackOptionLabel($value))
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->helperText('Opcional. Digite um nome e pressione Enter para criar uma subpasta na pasta selecionada.')
                        ->afterStateUpdated(fn ($state, Set $set) => $this->syncModuleFromTrack($state, $set)),
                    TextInput::make('folder_url')
                        ->label('URL ou ID da pasta do Google Drive')
                        ->placeholder('https://drive.google.com/drive/folders/...')
                        ->required(),
                    TextInput::make('panda_folder_name')
                        ->label('Pasta Panda para aulas avulsas')
                        ->placeholder('Aulas avulsas')
                        ->helperText('Opcional. Usada quando nenhuma pasta ou subpasta foi escolhida. Se ficar vazia, o upload vai para a biblioteca raiz do Panda.'),
                    Select::make('lesson_status')
                        ->label('Status inicial das aulas')
                        ->options([
                            'draft' => 'Rascunho',
                            'published' => 'Publicado',
                        ])
                        ->default('published')
                        ->required(),
                    Toggle::make('create_panda_folder')
                        ->label('Criar ou reutilizar pasta no Panda')
                        ->helperText('Usa a subpasta, a pasta ou o nome informado para aulas avulsas.')
                        ->default(true),
                    Toggle::make('upload_panda_videos')
                        ->label('Enviar vídeos ao Panda')
                        ->helperText('Baixa os arquivos de vídeo do Drive e envia ao Panda. PDFs e documentos ficam como material/link.')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    try {
                        $courseId = null;
                        $course = null;
                        [$module, $track] = $this->resolveImportStructure($data, $course);
                        $moduleId = $module?->id;
                        $trackId = $track?->id;

                        $run = GoogleDriveImportRun::query()->create([
                            'course_id' => $courseId,
                            'course_module_id' => $moduleId,
                            'folder_url' => (string) $data['folder_url'],
                            'status' => 'queued',
                            'latest_message' => 'Aguardando worker.',
                        ]);

                        ImportGoogleDriveLessons::dispatch(
                            $courseId,
                            $moduleId,
                            $trackId,
                            (string) $data['folder_url'],
                            (string) ($data['lesson_status'] ?? 'published'),
                            filled($data['panda_folder_name'] ?? null) ? (string) $data['panda_folder_name'] : null,
                            (bool) ($data['create_panda_folder'] ?? true),
                            (bool) ($data['upload_panda_videos'] ?? true),
                            $run->id,
                        )->afterResponse();

                        Notification::make()
                            ->title('Importação de aulas enviada para a fila.')
                            ->body('Acompanhe o progresso em Operação > Importações Drive.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar as aulas do Drive.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }

    protected function moduleOptions(mixed $courseId): array
    {
        return $this->moduleQuery($courseId)
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    protected function moduleSearchResults(mixed $courseId, ?string $search): array
    {
        $search = trim((string) $search);
        $query = $this->moduleQuery($courseId);

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $results = $query
            ->limit(50)
            ->pluck('name', 'id')
            ->all();

        if ($search !== '' && ! $this->hasExactLabel($results, $search)) {
            return [
                self::NEW_MODULE_PREFIX.$search => 'Criar pasta: '.$search,
                ...$results,
            ];
        }

        return $results;
    }

    protected function moduleOptionLabel(mixed $value): ?string
    {
        if ($this->isNewModuleValue($value)) {
            return 'Criar pasta: '.$this->newModuleName($value);
        }

        return CourseModule::query()->whereKey($value)->value('name');
    }

    protected function trackOptions(mixed $moduleId): array
    {
        if (! $this->isExistingId($moduleId)) {
            return [];
        }

        return CourseModuleTrack::query()
            ->where('course_module_id', $moduleId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    protected function trackSearchResults(mixed $moduleId, ?string $search): array
    {
        $search = trim((string) $search);
        $results = [];

        if ($this->isExistingId($moduleId)) {
            $query = CourseModuleTrack::query()
                ->where('course_module_id', $moduleId)
                ->orderBy('sort_order')
                ->orderBy('name');

            if ($search !== '') {
                $query->where('name', 'like', '%'.$search.'%');
            }

            $results = $query
                ->limit(50)
                ->pluck('name', 'id')
                ->all();
        }

        if ($search !== '' && ! $this->hasExactLabel($results, $search) && filled($moduleId)) {
            return [
                self::NEW_TRACK_PREFIX.$search => 'Criar subpasta: '.$search,
                ...$results,
            ];
        }

        return $results;
    }

    protected function trackOptionLabel(mixed $value): ?string
    {
        if ($this->isNewTrackValue($value)) {
            return 'Criar subpasta: '.$this->newTrackName($value);
        }

        return CourseModuleTrack::query()->whereKey($value)->value('name');
    }

    /**
     * @return array{0: CourseModule|null, 1: CourseModuleTrack|null}
     */
    protected function resolveImportStructure(array $data, ?Course $course): array
    {
        $module = null;
        $track = null;

        $moduleValue = $data['course_module_id'] ?? null;
        $trackValue = $data['course_module_track_id'] ?? null;

        if ($this->isNewModuleValue($moduleValue)) {
            $moduleName = $this->newModuleName($moduleValue);

            $module = CourseModule::query()->create([
                'course_id' => null,
                'name' => $moduleName,
                'type' => 'other',
                'workload_minutes' => 0,
                'sort_order' => $this->nextModuleSortOrder($course),
                'is_active' => true,
            ]);
        } elseif (filled($moduleValue)) {
            $module = CourseModule::query()->findOrFail((int) $moduleValue);
        }

        if ($this->isNewTrackValue($trackValue)) {
            if (! $module) {
                throw new \InvalidArgumentException('Selecione ou crie uma pasta antes de criar uma subpasta.');
            }

            $trackName = $this->newTrackName($trackValue);

            $track = CourseModuleTrack::query()->create([
                'course_module_id' => $module->id,
                'name' => $trackName,
                'slug' => $this->uniqueTrackSlug($module, $trackName),
                'sort_order' => $this->nextTrackSortOrder($module),
                'status' => 'draft',
            ]);
        } elseif (filled($trackValue)) {
            $track = CourseModuleTrack::query()->findOrFail((int) $trackValue);
        }

        if ($course && $module) {
            $module->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $module->sort_order],
            ]);
        }

        if ($course && $track) {
            $track->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $track->sort_order],
            ]);
        }

        return [$module, $track];
    }

    protected function nextModuleSortOrder(?Course $course): int
    {
        if (! $course) {
            return ((int) CourseModule::query()->max('sort_order')) + 1;
        }

        return ((int) CourseModule::query()
            ->whereHas('courses', fn ($query) => $query->whereKey($course->id))
            ->max('sort_order')) + 1;
    }

    protected function nextTrackSortOrder(CourseModule $module): int
    {
        return ((int) $module->tracks()->max('sort_order')) + 1;
    }

    protected function uniqueTrackSlug(CourseModule $module, string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'trilha';
        $slug = $baseSlug;
        $suffix = 2;

        while ($module->tracks()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected function syncModuleFromTrack(mixed $state, Set $set): void
    {
        if (blank($state) || $this->isNewTrackValue($state)) {
            return;
        }

        $track = CourseModuleTrack::query()
            ->select(['id', 'course_module_id'])
            ->find($state);

        if (! $track) {
            return;
        }

        $set('course_module_id', $track->course_module_id);
    }

    protected function moduleQuery(mixed $courseId)
    {
        $query = CourseModule::query()
            ->orderBy('sort_order')
            ->orderBy('name');

        if (filled($courseId)) {
            $query->where(function ($query) use ($courseId): void {
                $query
                    ->whereHas('courses', fn ($query) => $query->whereKey($courseId))
                    ->orWhereNull('course_id');
            });
        }

        return $query;
    }

    protected function hasExactLabel(array $options, string $search): bool
    {
        return collect($options)->contains(fn (string $label): bool => Str::lower($label) === Str::lower($search));
    }

    protected function isExistingId(mixed $value): bool
    {
        return filled($value)
            && ! $this->isNewModuleValue($value)
            && ! $this->isNewTrackValue($value);
    }

    protected function isNewModuleValue(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::NEW_MODULE_PREFIX);
    }

    protected function isNewTrackValue(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::NEW_TRACK_PREFIX);
    }

    protected function newModuleName(mixed $value): string
    {
        return trim(Str::after((string) $value, self::NEW_MODULE_PREFIX));
    }

    protected function newTrackName(mixed $value): string
    {
        return trim(Str::after((string) $value, self::NEW_TRACK_PREFIX));
    }
}

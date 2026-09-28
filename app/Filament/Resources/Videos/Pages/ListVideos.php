<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Resources\Videos\VideoResource;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Services\PandaCourseImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Throwable;

class ListVideos extends ListRecords
{
    protected static string $resource = VideoResource::class;

    protected const NEW_MODULE_PREFIX = '__new_module__:';

    protected const NEW_TRACK_PREFIX = '__new_track__:';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPandaVideos')
                ->label('Importar Panda')
                ->icon('heroicon-o-video-camera')
                ->modalHeading('Importar vídeos do Panda')
                ->modalDescription('Informe uma pasta do Panda. Os vídeos serão criados ou atualizados na Biblioteca de Vídeos e vinculados às aulas reutilizáveis de compatibilidade.')
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
                ])
                ->action(function (array $data, PandaCourseImporter $importer): void {
                    try {
                        $course = null;
                        [$module, $track] = $this->resolveImportStructure($data, $course);

                        $run = $importer->importVideos(
                            $module,
                            $track,
                            (string) $data['panda_folder_id'],
                        );

                        Notification::make()
                            ->title('Vídeos importados do Panda.')
                            ->body('Vídeos: '.($run->summary['videos'] ?? 0).'. Criados: '.($run->summary['created'] ?? 0).'. Atualizados: '.($run->summary['updated'] ?? 0).'.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Não foi possível importar vídeos do Panda.')
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
            $module = CourseModule::query()->create([
                'course_id' => null,
                'name' => $this->newModuleName($moduleValue),
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

        if ($track) {
            $set('course_module_id', $track->course_module_id);
        }
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
        return filled($value) && is_numeric($value);
    }

    protected function isNewModuleValue(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::NEW_MODULE_PREFIX);
    }

    protected function newModuleName(mixed $value): string
    {
        return trim(Str::after((string) $value, self::NEW_MODULE_PREFIX));
    }

    protected function isNewTrackValue(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::NEW_TRACK_PREFIX);
    }

    protected function newTrackName(mixed $value): string
    {
        return trim(Str::after((string) $value, self::NEW_TRACK_PREFIX));
    }
}

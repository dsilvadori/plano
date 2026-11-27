<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\PandaVideoUploadForm;
use App\Jobs\ImportGoogleDriveLessons;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\GoogleDriveImportRun;
use App\Models\Lesson;
use App\Services\LessonDirectVideoUploader;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    protected const NEW_MODULE_PREFIX = '__new_module__:';

    protected const NEW_TRACK_PREFIX = '__new_track__:';

    protected const BULK_UPLOAD_MAX_FILES = 100;

    protected function getHeaderActions(): array
    {
        return [
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
                        )
                            ->onConnection('database')
                            ->onQueue('default');

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
            Action::make('createLessonFromVideo')
                ->label('Nova aula com vídeo')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Criar aula enviando um vídeo ao Panda')
                ->modalDescription('A aula é criada na hora e o vídeo segue para o Panda em segundo plano. Quando o Panda terminar de processar, a aula e a Biblioteca de Vídeos são atualizadas automaticamente.')
                ->modalSubmitActionLabel('Criar aula e enviar')
                ->form(fn (): array => [
                    TextInput::make('title')
                        ->label('Título da aula')
                        ->required()
                        ->maxLength(255),
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
                        ->helperText('Opcional. Vincula a aula a esta subpasta.')
                        ->afterStateUpdated(fn ($state, Set $set) => $this->syncModuleFromTrack($state, $set)),
                    Select::make('lesson_status')
                        ->label('Status da aula')
                        ->options([
                            'draft' => 'Rascunho',
                            'published' => 'Publicado',
                        ])
                        ->default('draft')
                        ->required(),
                    ...PandaVideoUploadForm::fields(null, folderRequired: false),
                ])
                ->action(function (array $data, LessonDirectVideoUploader $uploader): void {
                    $path = PandaVideoUploadForm::storedPath($data);

                    if ($path === null) {
                        Notification::make()
                            ->title('Selecione um arquivo de vídeo.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        [$module, $track] = $this->resolveImportStructure($data, null);

                        $folderReference = filled($data['panda_folder_reference'] ?? null)
                            ? (string) $data['panda_folder_reference']
                            : ($track?->panda_folder_id ?: $module?->panda_folder_id);

                        // Valida a pasta antes de criar a aula, para não deixar aula órfã quando a pasta não existe.
                        $folderId = $uploader->resolveFolderId($folderReference, (bool) ($data['create_panda_folder'] ?? false));
                    } catch (Throwable $exception) {
                        Storage::disk(LessonDirectVideoUploader::disk())->delete($path);

                        Notification::make()
                            ->title('Não foi possível enviar o vídeo ao Panda')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $lesson = $this->createVideoLesson(
                        trim((string) $data['title']),
                        $module,
                        $track,
                        (string) ($data['lesson_status'] ?? 'draft'),
                    );

                    try {
                        $uploader->queue(
                            $lesson,
                            $path,
                            PandaVideoUploadForm::originalName($data),
                            $folderId,
                            (string) ($data['panda_title'] ?? ''),
                        );

                        Notification::make()
                            ->title('Aula criada e vídeo recebido.')
                            ->body('O envio ao Panda segue em segundo plano. Acompanhe pelo status de origem da aula.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('A aula foi criada, mas o envio ao Panda falhou')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }

                    $this->redirect(LessonResource::getUrl('edit', ['record' => $lesson]));
                }),
            Action::make('bulkUploadVideosToPanda')
                ->label('Enviar pasta de vídeos')
                ->icon('heroicon-o-folder-arrow-down')
                ->modalHeading('Enviar vários vídeos ao Panda')
                ->modalDescription('Cada vídeo vira uma aula, com o nome do arquivo como título e na ordem dos nomes (01, 02, 03...). Os envios ao Panda seguem em segundo plano, um de cada vez.')
                ->modalSubmitActionLabel('Criar aulas e enviar')
                ->modalWidth('3xl')
                ->form(fn (): array => [
                    FileUpload::make('video_files')
                        ->label('Vídeos')
                        ->multiple()
                        ->maxFiles(self::BULK_UPLOAD_MAX_FILES)
                        ->maxParallelUploads(2)
                        ->appendFiles()
                        ->panelLayout('grid')
                        ->disk(LessonDirectVideoUploader::disk())
                        ->directory(LessonDirectVideoUploader::directory())
                        ->visibility('private')
                        ->acceptedFileTypes(PandaVideoUploadForm::ACCEPTED_VIDEO_TYPES)
                        ->maxSize(LessonDirectVideoUploader::maxSizeKilobytes())
                        ->storeFileNamesIn('video_file_names')
                        ->helperText('Arraste a pasta inteira para cá ou selecione vários arquivos (Ctrl+A na janela de arquivos). Até '.self::BULK_UPLOAD_MAX_FILES.' vídeos, cada um até '.((int) (LessonDirectVideoUploader::maxSizeKilobytes() / 1024)).' MB. Arquivos que não são vídeo são recusados. Aguarde todas as barras terminarem antes de confirmar e não feche a página.')
                        ->required(),
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
                        ->helperText('Opcional. As aulas entram no fim desta subpasta, na ordem dos nomes dos arquivos.')
                        ->afterStateUpdated(fn ($state, Set $set) => $this->syncModuleFromTrack($state, $set)),
                    Select::make('lesson_status')
                        ->label('Status das aulas')
                        ->options([
                            'draft' => 'Rascunho',
                            'published' => 'Publicado',
                        ])
                        ->default('draft')
                        ->required(),
                    TextInput::make('panda_folder_reference')
                        ->label('Pasta no Panda')
                        ->helperText('Aceita URL completa, ID ou nome da pasta. Se ficar vazio, usa a pasta Panda da subpasta ou da pasta escolhida. O envio para a raiz do Panda é bloqueado.')
                        ->maxLength(2048),
                    Toggle::make('create_panda_folder')
                        ->label('Criar a pasta no Panda se ela não existir')
                        ->helperText('Só vale quando a pasta for informada pelo nome.')
                        ->default(false),
                    Toggle::make('skip_existing_titles')
                        ->label('Pular vídeos que já têm aula com o mesmo título no destino')
                        ->helperText('Útil para reenviar uma pasta depois de uma falha sem duplicar aulas.')
                        ->default(true),
                ])
                ->action(function (array $data, LessonDirectVideoUploader $uploader): void {
                    $this->bulkUploadVideos($data, $uploader);
                }),
            CreateAction::make(),
        ];
    }

    protected function bulkUploadVideos(array $data, LessonDirectVideoUploader $uploader): void
    {
        $files = $this->bulkUploadFiles($data);
        $disk = Storage::disk(LessonDirectVideoUploader::disk());

        if ($files === []) {
            Notification::make()
                ->title('Selecione pelo menos um vídeo.')
                ->danger()
                ->send();

            return;
        }

        try {
            // Resolve a pasta do Panda uma vez só, antes de criar pasta, subpasta ou aula aqui:
            // se ela não existir, nada fica criado pela metade.
            $folderId = $uploader->resolveFolderId(
                $this->bulkUploadFolderReference($data),
                (bool) ($data['create_panda_folder'] ?? false),
            );

            [$module, $track] = $this->resolveImportStructure($data, null);
        } catch (Throwable $exception) {
            $disk->delete(array_keys($files));

            Notification::make()
                ->title('Nenhum vídeo foi enviado ao Panda')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $skipExistingTitles = (bool) ($data['skip_existing_titles'] ?? true);
        $existingTitles = $skipExistingTitles ? $this->existingLessonTitles($module, $track) : [];

        $queued = [];
        $skipped = [];
        $failed = [];

        foreach ($files as $path => $originalName) {
            $title = $this->lessonTitleFromFileName($originalName);

            if ($skipExistingTitles && isset($existingTitles[Str::lower($title)])) {
                $disk->delete($path);
                $skipped[] = $originalName;

                continue;
            }

            $lesson = null;

            try {
                $lesson = $this->createVideoLesson($title, $module, $track, (string) ($data['lesson_status'] ?? 'draft'));
                $uploader->queue($lesson, $path, $originalName, $folderId);

                $existingTitles[Str::lower($title)] = true;
                $queued[] = $originalName;
            } catch (Throwable $exception) {
                report($exception);
                $disk->delete($path);
                $this->discardLessonWithoutVideo($lesson);
                $failed[] = $originalName.': '.$exception->getMessage();
            }
        }

        $body = collect([
            count($queued).' aula(s) criada(s) com vídeo na fila do Panda.',
            $skipped !== [] ? count($skipped).' pulado(s) por já existir aula com o mesmo título: '.implode(', ', array_slice($skipped, 0, 10)).(count($skipped) > 10 ? '...' : '') : null,
            $failed !== [] ? count($failed).' com erro: '.implode(' | ', array_slice($failed, 0, 5)).(count($failed) > 5 ? '...' : '') : null,
            $queued !== [] ? 'Os envios seguem em segundo plano, um de cada vez. Acompanhe pelo status de origem de cada aula.' : null,
        ])->filter()->implode("\n");

        $notification = Notification::make()
            ->title($queued !== [] ? 'Envio em massa iniciado.' : 'Nenhum vídeo foi enviado ao Panda.')
            ->body($body)
            ->persistent();

        match (true) {
            $failed !== [] && $queued === [] => $notification->danger(),
            $failed !== [] || $skipped !== [] => $notification->warning(),
            default => $notification->success(),
        };

        $notification->send();
    }

    /**
     * Pasta do Panda informada no formulário, ou a da subpasta/pasta já existente escolhida.
     * Pasta e subpasta novas ainda não têm pasta no Panda.
     */
    protected function bulkUploadFolderReference(array $data): ?string
    {
        if (filled($data['panda_folder_reference'] ?? null)) {
            return (string) $data['panda_folder_reference'];
        }

        $trackValue = $data['course_module_track_id'] ?? null;
        $moduleValue = $data['course_module_id'] ?? null;

        $track = filled($trackValue) && ! $this->isNewTrackValue($trackValue)
            ? CourseModuleTrack::query()->find((int) $trackValue)
            : null;
        $module = filled($moduleValue) && ! $this->isNewModuleValue($moduleValue)
            ? CourseModule::query()->find((int) $moduleValue)
            : null;

        return $track?->panda_folder_id ?: $module?->panda_folder_id;
    }

    /**
     * A aula criada para um vídeo que não entrou na fila ficaria pendente para sempre.
     */
    protected function discardLessonWithoutVideo(?Lesson $lesson): void
    {
        if (! $lesson?->exists) {
            return;
        }

        try {
            LessonResource::forgetCatalogCachesForLesson($lesson);
            $lesson->tracks()->detach();
            $lesson->modules()->detach();
            $lesson->delete();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Arquivos enviados, em ordem natural pelo nome original (01, 02, ..., 10).
     *
     * @return array<string, string> caminho armazenado => nome original
     */
    protected function bulkUploadFiles(array $data): array
    {
        $paths = array_values(array_filter(
            Arr::wrap($data['video_files'] ?? []),
            fn ($path): bool => is_string($path) && $path !== '',
        ));
        $names = is_array($data['video_file_names'] ?? null) ? $data['video_file_names'] : [];

        $files = [];

        foreach ($paths as $path) {
            $name = $names[$path] ?? null;
            $files[$path] = is_string($name) && trim($name) !== '' ? trim($name) : basename($path);
        }

        uasort($files, fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $files;
    }

    protected function lessonTitleFromFileName(string $fileName): string
    {
        $title = pathinfo($fileName, PATHINFO_FILENAME);
        $title = preg_replace('/[_\s]+/u', ' ', $title) ?? $title;
        $title = Str::limit(trim($title), 255, '');

        return $title !== '' ? $title : 'Aula em vídeo';
    }

    /**
     * @return array<string, true> títulos em minúsculas já usados no destino
     */
    protected function existingLessonTitles(?CourseModule $module, ?CourseModuleTrack $track): array
    {
        $query = match (true) {
            $track !== null => $track->lessons(),
            $module !== null => $module->onlineLessons(),
            default => null,
        };

        if ($query === null) {
            return [];
        }

        return $query->pluck('lessons.title')
            ->mapWithKeys(fn (string $title): array => [Str::lower(trim($title)) => true])
            ->all();
    }

    /**
     * O slug é único por pasta principal da aula, que é definida depois pelos vínculos.
     */
    protected function uniqueLessonSlug(string $title, ?int $moduleId): string
    {
        $baseSlug = Str::slug($title) ?: 'aula-'.Str::lower(Str::random(6));
        $slug = $baseSlug;
        $suffix = 2;

        while (Lesson::query()->where('course_module_id', $moduleId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected function createVideoLesson(string $title, ?CourseModule $module, ?CourseModuleTrack $track, string $status): Lesson
    {
        $sortOrder = match (true) {
            $track !== null => ((int) $track->lessons()->max('course_module_track_lessons.sort_order')) + 1,
            $module !== null => ((int) $module->onlineLessons()->max('course_module_lessons.sort_order')) + 1,
            default => 1,
        };

        $lesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => $title,
            'slug' => $this->uniqueLessonSlug($title, $track?->course_module_id ?? $module?->id),
            'description' => 'Aula em vídeo.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => $sortOrder,
            'status' => in_array($status, ['draft', 'published'], true) ? $status : 'draft',
            'source_status' => 'awaiting_media',
        ]);

        if ($track) {
            $lesson->tracks()->syncWithoutDetaching([
                $track->id => ['sort_order' => $sortOrder],
            ]);
        } elseif ($module) {
            $lesson->modules()->syncWithoutDetaching([
                $module->id => ['sort_order' => $sortOrder],
            ]);
        }

        LessonResource::syncPrimaryCatalogLinks($lesson);
        LessonResource::forgetCatalogCachesForLesson($lesson);

        return $lesson;
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

<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoogleDriveTrackImporter
{
    public function __construct(
        protected GoogleDriveClient $drive,
    ) {}

    public function importFolderSubfoldersAsTracks(Course $course, CourseModule $module, string $folderUrlOrId, string $lessonStatus = 'draft'): array
    {
        $folderId = $this->drive->folderIdFromUrl($folderUrlOrId);
        $folders = $this->sortNaturally($this->drive->listFolders($folderId));

        return DB::transaction(function () use ($course, $module, $folderUrlOrId, $folderId, $folders, $lessonStatus): array {
            $createdTracks = 0;
            $updatedTracks = 0;
            $createdLessons = 0;
            $updatedLessons = 0;

            $module->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $module->sort_order],
            ]);

            foreach ($folders as $trackIndex => $folder) {
                $trackName = trim((string) ($folder['name'] ?? '')) ?: 'Trilha '.($trackIndex + 1);
                $trackSlug = Str::slug($trackName) ?: 'trilha-'.($trackIndex + 1);
                $track = $module->tracks()
                    ->where('slug', $trackSlug)
                    ->first()
                    ?? new CourseModuleTrack([
                        'course_module_id' => $module->id,
                        'slug' => $trackSlug,
                    ]);
                $trackWasCreated = ! $track->exists;

                $track->fill([
                    'course_module_id' => $module->id,
                    'name' => $trackName,
                    'slug' => $track->exists ? $track->slug : $trackSlug,
                    'thumbnail_url' => $folder['thumbnailLink'] ?? $track->thumbnail_url,
                    'sort_order' => $trackIndex + 1,
                    'status' => 'draft',
                    'google_doc_url' => $folder['webViewLink'] ?? $folderUrlOrId,
                    'metadata' => [
                        'source' => 'google_drive',
                        'drive_folder_id' => $folder['id'] ?? null,
                        'parent_folder_id' => $folderId,
                        'imported_at' => now()->toIso8601String(),
                    ],
                ]);
                $track->save();
                $track->courses()->syncWithoutDetaching([
                    $course->id => ['sort_order' => $trackIndex + 1],
                ]);

                $trackWasCreated ? $createdTracks++ : $updatedTracks++;

                $files = $this->sortNaturally($this->drive->listFiles((string) $folder['id']));

                foreach ($files as $lessonIndex => $file) {
                    $title = $this->lessonTitleFromFile((string) ($file['name'] ?? ''), $lessonIndex + 1);
                    $slug = Str::slug($title) ?: 'aula-'.($lessonIndex + 1);
                    $lesson = $track->lessons()
                        ->where('lessons.slug', $slug)
                        ->first()
                        ?? new Lesson([
                            'course_id' => $course->id,
                            'course_module_id' => $module->id,
                            'course_module_track_id' => $track->id,
                            'slug' => $slug,
                        ]);
                    $lessonWasCreated = ! $lesson->exists;
                    $type = $this->lessonTypeForMimeType((string) ($file['mimeType'] ?? ''));

                    $lesson->fill([
                        'course_id' => $lesson->exists ? $lesson->course_id : $course->id,
                        'course_module_id' => $lesson->exists ? $lesson->course_module_id : $module->id,
                        'course_module_track_id' => $lesson->exists ? ($lesson->course_module_track_id ?: $track->id) : $track->id,
                        'title' => $title,
                        'slug' => $lesson->exists ? $lesson->slug : $slug,
                        'description' => 'Aula criada a partir do Google Drive.',
                        'type' => $type,
                        'thumbnail_url' => $file['thumbnailLink'] ?? $lesson->thumbnail_url,
                        'duration_seconds' => $lesson->duration_seconds ?: 0,
                        'sort_order' => $lessonIndex + 1,
                        'status' => $lessonStatus,
                        'google_doc_url' => $file['webViewLink'] ?? $lesson->google_doc_url,
                        'source_status' => $type === 'pdf' ? 'media_ready' : 'awaiting_media',
                        'metadata' => [
                            'source' => 'google_drive',
                            'drive_file_id' => $file['id'] ?? null,
                            'drive_mime_type' => $file['mimeType'] ?? null,
                            'drive_modified_time' => $file['modifiedTime'] ?? null,
                            'drive_web_content_link' => $file['webContentLink'] ?? null,
                            'imported_at' => now()->toIso8601String(),
                        ],
                    ]);
                    $lesson->save();
                    $lesson->modules()->syncWithoutDetaching([
                        $module->id => ['sort_order' => $lessonIndex + 1],
                    ]);
                    $lesson->tracks()->syncWithoutDetaching([
                        $track->id => ['sort_order' => $lessonIndex + 1],
                    ]);

                    $lessonWasCreated ? $createdLessons++ : $updatedLessons++;
                }
            }

            return [
                'folder_id' => $folderId,
                'tracks' => count($folders),
                'created_tracks' => $createdTracks,
                'updated_tracks' => $updatedTracks,
                'created_lessons' => $createdLessons,
                'updated_lessons' => $updatedLessons,
            ];
        });
    }

    protected function sortNaturally(array $files): array
    {
        usort($files, fn (array $left, array $right): int => strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')));

        return $files;
    }

    protected function lessonTitleFromFile(string $name, int $fallbackIndex): string
    {
        $title = trim(pathinfo($name, PATHINFO_FILENAME));

        return $title !== '' ? $title : 'Aula '.$fallbackIndex;
    }

    protected function lessonTypeForMimeType(string $mimeType): string
    {
        return match (true) {
            $mimeType === 'application/pdf' => 'pdf',
            str_starts_with($mimeType, 'video/') => 'video',
            default => 'text',
        };
    }
}

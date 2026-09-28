<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonFolder;
use App\Models\Video;
use App\Services\LessonFolderDuplicateMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonLibraryFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_lesson_folder_path_is_hierarchical_and_reusable(): void
    {
        $folder = LessonFolder::findOrCreatePath('Português / Sintaxe', ['source' => 'test']);
        $sameFolder = LessonFolder::findOrCreatePath('Português/Sintaxe', ['source' => 'another']);

        $this->assertTrue($folder->is($sameFolder));
        $this->assertSame('portugues/sintaxe', $folder->path);
        $this->assertSame('Português', $folder->parent->name);
    }

    public function test_lesson_can_be_reused_from_library_folder_across_contexts(): void
    {
        $folder = LessonFolder::findOrCreatePath('Português / Sintaxe');
        $lesson = Lesson::factory()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'lesson_folder_id' => $folder->id,
            'title' => 'Concordância verbal',
        ]);

        $this->assertTrue($folder->lessons()->whereKey($lesson->id)->exists());
        $this->assertSame('portugues/sintaxe', $lesson->fresh('folder')->folder->path);
    }

    public function test_duplicate_library_folders_can_be_merged_globally(): void
    {
        $first = LessonFolder::findOrCreatePath('Legislação / Lei de Improbidade Administrativa');
        $second = LessonFolder::findOrCreatePath('Conhecimentos Específicos / Lei de Improbidade Administrativa');
        $lesson = Lesson::factory()->create(['lesson_folder_id' => $second->id]);
        $video = Video::query()->create([
            'lesson_folder_id' => $second->id,
            'title' => 'Aula de Improbidade',
            'slug' => 'aula-de-improbidade',
            'provider' => 'panda',
            'source_status' => 'media_ready',
        ]);

        $summary = app(LessonFolderDuplicateMerger::class)->merge(apply: true, scope: 'global');

        $this->assertSame(1, $summary['folders_merged']);
        $this->assertDatabaseMissing('lesson_folders', ['id' => $second->id]);
        $this->assertSame($first->id, $lesson->fresh()->lesson_folder_id);
        $this->assertSame($first->id, $video->fresh()->lesson_folder_id);
    }
}

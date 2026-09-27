<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonFolder;
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
}

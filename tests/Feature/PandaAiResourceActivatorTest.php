<?php

namespace Tests\Feature;

use App\Jobs\SyncPandaAiArtifacts;
use App\Models\AiArtifact;
use App\Models\Lesson;
use App\Services\PandaAiResourceActivator;
use App\Services\PandaVideoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class PandaAiResourceActivatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_ready_panda_ai_artifacts_without_requesting_a_new_workflow(): void
    {
        $lesson = Lesson::factory()->create([
            'panda_video_id' => 'video-123',
            'panda_player_url' => 'https://player-vz-abc.pandavideo.com.br/embed/?v=external-123',
            'metadata' => [
                'payload' => [
                    'pullzone_name' => 'vz-abc',
                ],
            ],
        ]);

        $panda = Mockery::mock(PandaVideoClient::class);
        $panda->shouldReceive('aiPackage')
            ->once()
            ->with('vz-abc', 'external-123')
            ->andReturn([
                'summary' => 'Resumo gerado pelo Panda',
                'quiz' => ['questions' => [['title' => 'Questao 1']]],
                'mindmap' => ['nodes' => [['label' => 'Aula']]],
            ]);
        $panda->shouldNotReceive('createAiPackage');

        $result = app(PandaAiResourceActivator::class, ['panda' => $panda])->activate($lesson);

        $lesson->refresh();

        $this->assertTrue((bool) data_get($lesson->metadata, 'panda_ai.auto_sync_enabled'));
        $this->assertSame('already_ready', data_get($lesson->metadata, 'panda_ai.last_request_status'));
        $this->assertSame('vz-abc', data_get($lesson->metadata, 'panda_ai.pullzone_name'));
        $this->assertSame('external-123', data_get($lesson->metadata, 'panda_ai.video_external_id'));
        $this->assertFalse($result['requested']);
        $this->assertSame(3, $result['created_artifacts']);

        $this->assertSame(1, AiArtifact::query()->where('artifact_type', 'summary')->count());
        $this->assertSame(1, AiArtifact::query()->where('artifact_type', 'quiz')->count());
        $this->assertSame(1, AiArtifact::query()->where('artifact_type', 'mindmap')->count());
        $this->assertSame(1, AiArtifact::query()->where('artifact_type', 'panda_payload')->count());
    }

    public function test_it_requests_panda_ai_workflow_when_artifacts_are_missing(): void
    {
        Bus::fake();

        $lesson = Lesson::factory()->create([
            'panda_video_id' => 'video-123',
            'metadata' => [],
        ]);

        $panda = Mockery::mock(PandaVideoClient::class);
        $panda->shouldNotReceive('aiPackage');
        $panda->shouldReceive('createAiPackage')
            ->once()
            ->with('video-123')
            ->andReturn(['ok' => true]);

        $result = app(PandaAiResourceActivator::class, ['panda' => $panda])->activate($lesson);

        $lesson->refresh();

        $this->assertTrue((bool) data_get($lesson->metadata, 'panda_ai.auto_sync_enabled'));
        $this->assertSame('requested', data_get($lesson->metadata, 'panda_ai.last_request_status'));
        $this->assertTrue($result['requested']);
        $this->assertSame(0, $result['created_artifacts']);

        Bus::assertDispatched(SyncPandaAiArtifacts::class, fn (SyncPandaAiArtifacts $job): bool => $job->lessonId === $lesson->id);
    }

    public function test_it_replaces_existing_panda_ai_artifacts_before_requesting_regeneration(): void
    {
        Bus::fake();

        $lesson = Lesson::factory()->create([
            'panda_video_id' => 'video-123',
            'metadata' => [],
        ]);

        AiArtifact::query()->create([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'summary',
            'provider' => 'panda',
            'status' => 'ready',
            'content' => ['text' => 'English summary'],
            'metadata' => [],
        ]);

        $panda = Mockery::mock(PandaVideoClient::class);
        $panda->shouldNotReceive('aiPackage');
        $panda->shouldReceive('createAiPackage')
            ->once()
            ->with('video-123')
            ->andReturn(['ok' => true]);

        $result = app(PandaAiResourceActivator::class, ['panda' => $panda])
            ->activate($lesson, replaceExisting: true);

        $lesson->refresh();

        $this->assertTrue($result['requested']);
        $this->assertTrue($result['replaced_existing']);
        $this->assertSame(0, AiArtifact::query()->where('source_id', $lesson->id)->count());
        $this->assertSame('pt-BR', data_get($lesson->metadata, 'panda_ai.last_request_language'));
        $this->assertSame('regenerating', data_get($lesson->metadata, 'panda_ai.last_payload_status'));
        $this->assertNotNull(data_get($lesson->metadata, 'panda_ai.last_auto_sync_attempt_at'));

        Bus::assertDispatched(SyncPandaAiArtifacts::class, fn (SyncPandaAiArtifacts $job): bool => $job->lessonId === $lesson->id);
    }

    public function test_sync_job_stores_panda_ai_artifacts_when_package_is_ready(): void
    {
        $lesson = Lesson::factory()->create([
            'panda_video_id' => 'video-123',
            'panda_player_url' => 'https://player-vz-abc.pandavideo.com.br/embed/?v=external-123',
            'metadata' => [
                'payload' => [
                    'pullzone_name' => 'vz-abc',
                ],
            ],
        ]);

        $panda = Mockery::mock(PandaVideoClient::class);
        $panda->shouldReceive('aiPackage')
            ->once()
            ->with('vz-abc', 'external-123')
            ->andReturn([
                'summary' => 'Resumo em portugues',
                'quiz' => ['questions' => [['title' => 'Questao 1']]],
                'mindmap' => ['nodes' => [['label' => 'Aula']]],
            ]);

        app(SyncPandaAiArtifacts::class, ['lessonId' => $lesson->id])
            ->handle(app(PandaAiResourceActivator::class, ['panda' => $panda]));

        $lesson->refresh();

        $this->assertSame(3, AiArtifact::query()->where('source_id', $lesson->id)->whereIn('artifact_type', ['summary', 'quiz', 'mindmap'])->count());
        $this->assertSame('ready', data_get($lesson->metadata, 'panda_ai.last_payload_status'));
    }

    public function test_generate_syncs_pending_panda_ai_package_without_requesting_a_new_workflow(): void
    {
        Bus::fake();

        $lesson = Lesson::factory()->create([
            'panda_video_id' => 'video-123',
            'panda_player_url' => 'https://player-vz-abc.pandavideo.com.br/embed/?v=external-123',
            'metadata' => [
                'payload' => [
                    'pullzone_name' => 'vz-abc',
                ],
                'panda_ai' => [
                    'last_request_status' => 'requested',
                    'last_payload_status' => 'regenerating',
                    'requested_at' => now()->subMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $panda = Mockery::mock(PandaVideoClient::class);
        $panda->shouldReceive('aiPackage')
            ->once()
            ->with('vz-abc', 'external-123')
            ->andReturn([
                'summary' => 'Resumo em portugues',
                'quiz' => ['questions' => [['title' => 'Questao 1']]],
                'mindmap' => ['nodes' => [['label' => 'Aula']]],
            ]);
        $panda->shouldNotReceive('createAiPackage');

        $result = app(PandaAiResourceActivator::class, ['panda' => $panda])->generate($lesson);

        $lesson->refresh();

        $this->assertFalse($result['requested']);
        $this->assertFalse($result['pending']);
        $this->assertSame(3, $result['created_artifacts']);
        $this->assertSame('ready', data_get($lesson->metadata, 'panda_ai.last_payload_status'));

        Bus::assertNotDispatched(SyncPandaAiArtifacts::class);
    }

    public function test_it_rejects_lessons_without_panda_video(): void
    {
        $lesson = Lesson::factory()->create([
            'panda_video_id' => null,
            'metadata' => [],
        ]);

        $panda = Mockery::mock(PandaVideoClient::class);
        $panda->shouldNotReceive('createAiPackage');
        $panda->shouldNotReceive('aiPackage');

        $this->expectExceptionMessage('Esta aula não tem ID de vídeo no Panda.');

        app(PandaAiResourceActivator::class, ['panda' => $panda])->activate($lesson);
    }
}

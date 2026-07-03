<?php

namespace Tests\Feature;

use App\Jobs\SyncPandaTutorAvailability;
use App\Models\Lesson;
use App\Services\PandaTutorActivator;
use App\Services\PandaVideoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class PandaTutorActivatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_panda_tutor_available_when_player_config_has_assistant(): void
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
        $panda->shouldReceive('playerConfig')
            ->once()
            ->with('vz-abc', 'external-123')
            ->andReturn(['assistant_id' => 'assistant-123']);
        $panda->shouldNotReceive('createAiPackage');

        $result = app(PandaTutorActivator::class, ['panda' => $panda])->activate($lesson);

        $lesson->refresh();

        $this->assertTrue($result['available']);
        $this->assertSame('assistant-123', $result['assistant_id']);
        $this->assertTrue((bool) data_get($lesson->metadata, 'panda_ai.tutor_available'));
        $this->assertSame('active', data_get($lesson->metadata, 'panda_ai.tutor_status'));
        $this->assertSame('assistant-123', data_get($lesson->metadata, 'panda_ai.tutor_assistant_id'));
    }

    public function test_it_requests_tutor_and_waits_for_player_config_before_marking_available(): void
    {
        Bus::fake();

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
        $panda->shouldReceive('playerConfig')
            ->once()
            ->with('vz-abc', 'external-123')
            ->andReturn([]);
        $panda->shouldReceive('createTutor')
            ->once()
            ->with('video-123')
            ->andReturn(['assistant' => ['id' => 'assistant-123']]);

        $result = app(PandaTutorActivator::class, ['panda' => $panda])->activate($lesson);

        $lesson->refresh();

        $this->assertFalse($result['available']);
        $this->assertTrue($result['requested']);
        $this->assertFalse((bool) data_get($lesson->metadata, 'panda_ai.tutor_available'));
        $this->assertSame('requested', data_get($lesson->metadata, 'panda_ai.tutor_status'));
        $this->assertSame('assistant-123', data_get($lesson->metadata, 'panda_ai.tutor_assistant_id'));
        $this->assertSame('Converse com a tutora LilIA', data_get($lesson->metadata, 'panda_ai.tutor_message'));
        $this->assertSame('pt-BR', data_get($lesson->metadata, 'panda_ai.tutor_last_request_language'));

        Bus::assertDispatched(SyncPandaTutorAvailability::class, fn (SyncPandaTutorAvailability $job): bool => $job->lessonId === $lesson->id);
    }

    public function test_it_still_creates_tutor_when_availability_check_fails(): void
    {
        Bus::fake();

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
        $panda->shouldReceive('playerConfig')
            ->once()
            ->with('vz-abc', 'external-123')
            ->andThrow(new \RuntimeException('Config indisponivel'));
        $panda->shouldReceive('createTutor')
            ->once()
            ->with('video-123')
            ->andReturn(['id' => 'tutor-123']);

        $result = app(PandaTutorActivator::class, ['panda' => $panda])->activate($lesson);

        $lesson->refresh();

        $this->assertFalse($result['available']);
        $this->assertTrue($result['requested']);
        $this->assertSame('requested', data_get($lesson->metadata, 'panda_ai.tutor_status'));
        $this->assertSame('tutor-123', data_get($lesson->metadata, 'panda_ai.tutor_response.id'));

        Bus::assertDispatched(SyncPandaTutorAvailability::class, fn (SyncPandaTutorAvailability $job): bool => $job->lessonId === $lesson->id);
    }

    public function test_it_activates_existing_assistant_when_player_config_is_not_updated_yet(): void
    {
        $lesson = Lesson::factory()->create([
            'panda_video_id' => 'video-123',
            'panda_player_url' => 'https://player-vz-abc.pandavideo.com.br/embed/?v=external-123',
            'metadata' => [
                'payload' => [
                    'pullzone_name' => 'vz-abc',
                ],
                'panda_ai' => [
                    'tutor_assistant_id' => 'assistant-123',
                    'tutor_status' => 'requested',
                    'tutor_available' => false,
                ],
            ],
        ]);

        $panda = Mockery::mock(PandaVideoClient::class);
        $panda->shouldReceive('playerConfig')
            ->once()
            ->with('vz-abc', 'external-123')
            ->andReturn([]);
        $panda->shouldReceive('tutorAssistant')
            ->once()
            ->with('assistant-123')
            ->andReturn([
                'id' => 'assistant-123',
                'status' => 'queued',
                'videos' => [
                    ['id' => 'video-123', 'video_external_id' => 'external-123'],
                ],
            ]);
        $panda->shouldReceive('updateTutorStatus')
            ->once()
            ->with('assistant-123', 'ready')
            ->andReturn(['ok' => true]);
        $panda->shouldReceive('updateTutorChatVisibility')
            ->once()
            ->with('assistant-123', 'video-123', true)
            ->andReturn(['ok' => true]);
        $panda->shouldNotReceive('createTutor');

        $result = app(PandaTutorActivator::class, ['panda' => $panda])->syncAvailability($lesson);

        $lesson->refresh();

        $this->assertTrue($result['available']);
        $this->assertSame('assistant-123', $result['assistant_id']);
        $this->assertTrue((bool) data_get($lesson->metadata, 'panda_ai.tutor_available'));
        $this->assertSame('active', data_get($lesson->metadata, 'panda_ai.tutor_status'));
        $this->assertSame('assistant-123', data_get($lesson->metadata, 'panda_ai.tutor_assistant_id'));
    }
}

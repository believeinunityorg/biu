<?php

namespace Tests\Unit\Services\Streaming;

use App\Models\Organization;
use App\Models\OrganizationLivestream;
use App\Models\StreamingJob;
use App\Models\User;
use App\Services\Streaming\StreamingLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class StreamingLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private StreamingLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycle = app(StreamingLifecycleService::class);
        config([
            'streaming.simulate_worker' => false,
            'streaming.lifecycle.queued_timeout_seconds' => 120,
            'streaming.lifecycle.starting_timeout_seconds' => 180,
            'streaming.lifecycle.heartbeat_stale_seconds' => 60,
            'streaming.lifecycle.stop_requested_grace_seconds' => 30,
            'streaming.lifecycle.max_duration_grace_minutes' => 0,
            'streaming.max_duration_minutes' => 120,
        ]);
    }

    public function test_fails_queued_jobs_past_queue_timeout(): void
    {
        [$livestream, $job] = $this->makeLivestreamWithJob('queued', [
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $changed = $this->lifecycle->reconcileJob($job);

        $this->assertTrue($changed);
        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('queued timeout', (string) $job->failure_reason);
        $livestream->refresh();
        $this->assertSame('meeting_live', $livestream->status);
    }

    public function test_fails_starting_jobs_past_startup_timeout(): void
    {
        [, $job] = $this->makeLivestreamWithJob('starting', [
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        $this->lifecycle->reconcileJob($job);

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('startup timed out', (string) $job->failure_reason);
    }

    public function test_force_terminate_marks_job_stopped_and_livestream_draft(): void
    {
        [$livestream, $job] = $this->makeLivestreamWithJob('live', [
            'live_at' => now()->subMinutes(10),
        ]);

        $ok = $this->lifecycle->forceTerminateActiveJob(
            'organization',
            $livestream->id,
            'Host ended stream'
        );

        $this->assertTrue($ok);
        $job->refresh();
        $this->assertSame('stopped', $job->status);
        $livestream->refresh();
        $this->assertSame('draft', $livestream->status);
    }

    public function test_stop_requested_grace_stops_job_without_worker_callback(): void
    {
        [$livestream, $job] = $this->makeLivestreamWithJob('starting');

        $livestream->update([
            'settings' => ['stream_stop_requested' => now()->subSeconds(60)->toIso8601String()],
        ]);

        $this->lifecycle->reconcileJob($job->fresh());

        $job->refresh();
        $this->assertSame('stopped', $job->status);
    }

    public function test_record_heartbeat_updates_timestamp(): void
    {
        [, $job] = $this->makeLivestreamWithJob('live');

        $this->lifecycle->recordHeartbeat($job);

        $job->refresh();
        $this->assertNotNull($job->last_heartbeat_at);
    }

    /**
     * @param  array<string, mixed>  $jobOverrides
     * @return array{0: OrganizationLivestream, 1: StreamingJob}
     */
    private function makeLivestreamWithJob(string $jobStatus, array $jobOverrides = []): array
    {
        $user = User::factory()->create();
        $org = Organization::query()->create([
            'user_id' => $user->id,
            'ein' => '123456789',
            'name' => 'Test Org',
            'street' => '1 Main St',
            'city' => 'City',
            'state' => 'NY',
            'zip' => '10001',
            'email' => 'org@example.com',
            'phone' => '5555555555',
            'contact_name' => 'Contact',
            'contact_title' => 'Director',
        ]);

        $livestream = OrganizationLivestream::query()->create([
            'organization_id' => $org->id,
            'room_name' => 'biu_mtg_test01',
            'room_password' => Crypt::encryptString('secret12'),
            'status' => 'meeting_live',
            'title' => 'Test',
        ]);

        $job = StreamingJob::query()->create(array_merge([
            'livestream_kind' => 'organization',
            'livestream_id' => $livestream->id,
            'meeting_id' => (string) $livestream->id,
            'organization_id' => (string) $org->id,
            'source_url' => 'rtmp://example/live',
            'destination_url' => 'rtmp://youtube/live2/key',
            'callback_url' => 'https://example.test/api/streaming/status',
            'max_duration_minutes' => 120,
            'status' => $jobStatus,
        ], $jobOverrides));

        return [$livestream, $job];
    }
}

<?php

namespace App\Services\Streaming;

use App\Models\OrganizationLivestream;
use App\Models\StreamingJob;
use App\Models\StreamingMonthlyUsage;
use App\Models\UserLivestream;
use App\Services\YouTubeService;
use Aws\Sqs\SqsClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class StreamingQueueService
{
    /**
     * @return array<string, mixed>
     */
    private function sqsClientConfig(): array
    {
        $config = [
            'version' => '2012-11-05',
            'region' => (string) config('streaming.region'),
            'credentials' => [
                'key' => (string) config('streaming.aws_key'),
                'secret' => (string) config('streaming.aws_secret'),
            ],
        ];

        $http = [];
        $caBundle = config('streaming.aws_ca_bundle');
        if (is_string($caBundle) && $caBundle !== '') {
            $http['verify'] = $caBundle;
        } elseif (! (bool) config('streaming.http_verify', true)) {
            $http['verify'] = false;
        }

        if ($http !== []) {
            $config['http'] = $http;
        }

        return $config;
    }

    private function shouldSimulateWorker(): bool
    {
        if (! (bool) config('streaming.simulate_worker', false)) {
            return false;
        }

        if (app()->environment('production') && ! (bool) config('streaming.simulate_worker_force', false)) {
            Log::warning('STREAMING_SIMULATE_WORKER is set but ignored in production. Use a real worker, or set STREAMING_SIMULATE_WORKER_FORCE=true (not recommended).');

            return false;
        }

        return true;
    }

    public function willUseRealSqs(): bool
    {
        if ($this->shouldSimulateWorker()) {
            return false;
        }

        return (string) config('streaming.queue_url') !== '';
    }

    /**
     * The most-recent streaming_jobs row for this livestream that's still
     * counted as "in flight" — i.e. its worker is queued, starting, or live.
     * Used by Go Live to refuse spawning a duplicate Fargate task on rapid
     * repeat clicks: if this returns non-null, the caller must NOT enqueue a
     * new job; reuse / surface the existing one instead.
     */
    public function existingActiveJobFor(string $livestreamKind, int $livestreamId): ?StreamingJob
    {
        return StreamingJob::query()
            ->where('livestream_kind', $livestreamKind)
            ->where('livestream_id', $livestreamId)
            ->whereIn('status', ['queued', 'starting', 'live'])
            ->latest('id')
            ->first();
    }

    /**
     * Any in-flight job for this billing owner (user id or organization id).
     */
    public function existingActiveJobForOwner(string $organizationId): ?StreamingJob
    {
        if (trim($organizationId) === '') {
            return null;
        }

        return StreamingJob::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['queued', 'starting', 'live'])
            ->latest('id')
            ->first();
    }

    /**
     * One active streaming_jobs row per livestream until terminal (each meeting is independent).
     *
     * @return null when a new job may be enqueued, otherwise a user-facing error message
     */
    public function goLiveBlockedReason(string $ownerId, string $livestreamKind, int $livestreamId): ?string
    {
        $onThisMeeting = $this->existingActiveJobFor($livestreamKind, $livestreamId);
        if ($onThisMeeting) {
            return 'A stream is already in progress for this meeting. End the stream first, then you can go live again.';
        }

        return null;
    }

    public function canStartNewStreamingJob(string $ownerId, string $livestreamKind, int $livestreamId): bool
    {
        return $this->goLiveBlockedReason($ownerId, $livestreamKind, $livestreamId) === null;
    }

    /**
     * Match what a real worker does: callback "starting" then "live" so the DB and UI update.
     */
    private function simulateWorkerProgress(StreamingJob $job): void
    {
        $job->refresh();
        $this->applyCallbackStatus($job, 'starting', null, null);
        $this->applyCallbackStatus($job->fresh(), 'live', null, null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(StreamingJob $job, array $payload): string
    {
        if ($this->shouldSimulateWorker()) {
            $mid = 'local-sim-'.$job->id.'-'.substr(sha1((string) microtime(true)), 0, 10);
            $job->update([
                'provider_message_id' => $mid,
                'status' => 'queued',
            ]);
            Log::notice('Streaming worker simulated (no SQS). Unset STREAMING_SIMULATE_WORKER for real AWS relay.', [
                'streaming_job_id' => $job->id,
                'livestream_kind' => $job->livestream_kind,
            ]);
            $this->simulateWorkerProgress($job);

            return $mid;
        }

        $queueUrl = (string) config('streaming.queue_url');
        if ($queueUrl === '') {
            throw new RuntimeException('SQS_STREAMING_QUEUE_URL is not configured. Set it for AWS, or enable STREAMING_SIMULATE_WORKER=true for local UI testing without a worker.');
        }

        $client = new SqsClient($this->sqsClientConfig());

        $message = [
            'QueueUrl' => $queueUrl,
            'MessageBody' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        $delaySeconds = max(0, min(900, (int) config('streaming.sqs_delay_seconds', 0)));
        if ($delaySeconds > 0) {
            $message['DelaySeconds'] = $delaySeconds;
        }

        $result = $client->sendMessage($message);

        $messageId = (string) ($result->get('MessageId') ?? '');
        $job->update([
            'provider_message_id' => $messageId !== '' ? $messageId : null,
            'status' => 'queued',
        ]);

        return $messageId;
    }

    public function applyCallbackStatus(StreamingJob $job, string $status, ?int $durationMinutes, ?string $failureReason): void
    {
        DB::transaction(function () use ($job, $status, $durationMinutes, $failureReason): void {
            $updates = ['status' => $status];
            if ($durationMinutes !== null) {
                $updates['duration_minutes'] = max(0, $durationMinutes);
            }
            if ($failureReason !== null && $failureReason !== '') {
                if (in_array($status, ['completed', 'stopped'], true) && self::isBenignTerminalFailureReason($failureReason)) {
                    $failureReason = null;
                }
                if ($failureReason !== null) {
                    $updates['failure_reason'] = $failureReason;
                }
            }
            if (in_array($status, ['starting', 'live'], true)) {
                $updates['last_heartbeat_at'] = now();
            }
            if ($status === 'live' && $job->live_at === null) {
                $updates['live_at'] = now();
            }
            if (in_array($status, ['completed', 'failed', 'stopped'], true)) {
                $updates['completed_at'] = now();
            }
            $job->fill($updates)->save();

            $this->syncLivestreamState($job, $status);
            $this->accountUsageIfNeeded($job);
        });
    }

    private function syncLivestreamState(StreamingJob $job, string $status): void
    {
        if ($job->livestream_kind === 'organization') {
            $livestream = OrganizationLivestream::find($job->livestream_id);
        } else {
            $livestream = UserLivestream::find($job->livestream_id);
        }

        if (! $livestream) {
            return;
        }

        if ($status === 'starting' && in_array($livestream->status, ['draft', 'scheduled'], true)) {
            // Fresh stream beginning — drop any stale End Stream marker so this
            // run isn't killed by the previous run's flag.
            $settings = $livestream->settings ?? [];
            unset($settings['stream_stop_requested']);

            $livestream->update([
                'status' => 'meeting_live',
                'settings' => $settings ?: null,
            ]);

            \App\Support\UnityLiveBroadcast::notifyHostDashboard($livestream, 'streaming_starting');

            return;
        }

        if ($status === 'live') {
            $livestream->update([
                'status' => 'live',
                'started_at' => $livestream->started_at ?? now(),
            ]);

            $livestream->refresh();
            $livestream->loadMissing($livestream instanceof OrganizationLivestream ? 'organization' : 'user');
            \App\Support\UnityLiveBroadcast::notifyLive($livestream);

            // Transition the YouTube broadcast to "live" NOW — not on the user's
            // Go Live click. The worker only sends status=live after ffmpeg has
            // pushed its first frame to YouTube, so the RTMP ingest is active and
            // YouTube accepts the transition. Doing it on button-click (see
            // Organization/LivestreamController:674) fired ~60-120s too early,
            // before any frames flowed; YouTube rejected it, the error was
            // swallowed, and the broadcast never went live even though video was
            // arriving — the "streaming but nothing on YouTube Studio" symptom.
            $this->transitionYoutubeBroadcastToLive($livestream, $job->livestream_kind);

            return;
        }

        if (in_array($status, ['completed', 'stopped'], true)) {
            // Clear the End Stream marker so the *next* stream on this row
            // isn't instantly stopped by a stale flag.
            $settings = $livestream->settings ?? [];
            unset($settings['stream_stop_requested']);

            $livestream->update([
                'status' => 'draft',
                'ended_at' => now(),
                'settings' => $settings ?: null,
            ]);

            if ($livestream instanceof OrganizationLivestream) {
                $this->rotateYoutubeBroadcastAfterStreamEnd($livestream, 'organization');
            } elseif ($livestream instanceof UserLivestream) {
                $this->rotateYoutubeBroadcastAfterStreamEnd($livestream, 'user');
            }

            $livestream->refresh();
            $livestream->loadMissing($livestream instanceof OrganizationLivestream ? 'organization' : 'user');
            \App\Support\UnityLiveBroadcast::notifyStreamEnded($livestream);

            return;
        }

        if ($status === 'failed') {
            $livestream->update(['status' => 'meeting_live']);
            \App\Support\UnityLiveBroadcast::notifyHostDashboard($livestream, 'streaming_failed');
        }
    }

    /**
     * Transition the bound YouTube broadcast to "live". Called from the worker
     * status=live callback (NOT the Go Live button) so YouTube's RTMP ingest is
     * already active and the transition is accepted. Best-effort: a failure here
     * must not break the callback (BIU status is already updated).
     *
     * @param  OrganizationLivestream|UserLivestream  $livestream
     */
    private function transitionYoutubeBroadcastToLive($livestream, string $kind): void
    {
        if (empty($livestream->youtube_broadcast_id)) {
            return;
        }

        $youtubeService = app(YouTubeService::class);
        $accessToken = null;

        if ($kind === 'organization' && $livestream instanceof OrganizationLivestream) {
            $livestream->loadMissing('organization');
            if ($livestream->organization) {
                $accessToken = $youtubeService->getValidAccessToken($livestream->organization);
            }
        } elseif ($livestream instanceof UserLivestream) {
            $livestream->loadMissing('user.organization');
            $user = $livestream->user;
            if ($user) {
                $accessToken = $youtubeService->getValidAccessTokenForUser($user)
                    ?: ($user->organization
                        ? $youtubeService->getValidAccessToken($user->organization)
                        : null);
            }
        }

        if (! $accessToken) {
            Log::warning('YouTube transition->live skipped: no valid access token', [
                'livestream_id' => $livestream->id,
                'kind' => $kind,
            ]);

            return;
        }

        try {
            $ok = $youtubeService->updateBroadcastStatus(
                $accessToken,
                $livestream->youtube_broadcast_id,
                'live'
            );
            Log::info('YouTube broadcast transition->live (worker callback)', [
                'livestream_id' => $livestream->id,
                'broadcast_id' => $livestream->youtube_broadcast_id,
                'ok' => $ok,
            ]);
        } catch (\Throwable $e) {
            Log::warning('YouTube transition->live failed', [
                'livestream_id' => $livestream->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Host clicked End stream: YouTube was already told to complete. In local simulate mode, apply the
     * worker "stopped" callback immediately so the UI does not wait on AWS. With a real worker, the
     * relay should callback completed/stopped shortly after YouTube ingest ends.
     */
    public function finalizeAfterHostEndStream(string $livestreamKind, int $livestreamId): bool
    {
        return app(StreamingLifecycleService::class)->forceTerminateActiveJob(
            $livestreamKind,
            $livestreamId,
            'Host ended stream'
        );
    }

    /**
     * After a cloud relay ends, create a new YouTube broadcast so the same meeting can go live again.
     *
     * @param  OrganizationLivestream|UserLivestream  $livestream
     */
    private function rotateYoutubeBroadcastAfterStreamEnd(
        OrganizationLivestream|UserLivestream $livestream,
        string $livestreamKind,
    ): void {
        $youtubeService = app(YouTubeService::class);
        $accessToken = null;
        $titleSuffix = 'Live';

        if ($livestreamKind === 'organization' && $livestream instanceof OrganizationLivestream) {
            $livestream->loadMissing('organization');
            $organization = $livestream->organization;
            if (! $organization) {
                return;
            }
            $accessToken = $youtubeService->getValidAccessToken($organization);
            $titleSuffix = $organization->name ?? 'Live';
        } elseif ($livestreamKind === 'user' && $livestream instanceof UserLivestream) {
            $livestream->loadMissing('user');
            $user = $livestream->user;
            if (! $user) {
                return;
            }
            $accessToken = $youtubeService->getValidAccessTokenForUser($user);
            if (! $accessToken && $user->organization) {
                $accessToken = $youtubeService->getValidAccessToken($user->organization);
            }
            $titleSuffix = $user->name ?? 'Live';
        }

        if (! $accessToken) {
            return;
        }

        try {
            $title = $livestream->title ?: 'Unity Meet - '.$titleSuffix;
            $broadcastData = $youtubeService->createLiveBroadcast(
                $accessToken,
                $title,
                $livestream->description,
                null
            );
            if (! $broadcastData || empty($broadcastData['stream_key'])) {
                return;
            }

            $settings = $livestream->settings ?? [];
            if (! empty($broadcastData['rtmp_url'] ?? null)) {
                $settings['rtmp_url'] = $broadcastData['rtmp_url'];
            }

            $livestream->update([
                'youtube_broadcast_id' => $broadcastData['broadcast_id'],
                'youtube_stream_key' => Crypt::encryptString($broadcastData['stream_key']),
                'settings' => $settings ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('rotateYoutubeBroadcastAfterStreamEnd failed', [
                'livestream_kind' => $livestreamKind,
                'livestream_id' => $livestream->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function accountUsageIfNeeded(StreamingJob $job): void
    {
        if (! in_array($job->status, ['completed', 'failed', 'stopped'], true)) {
            return;
        }
        if ($job->duration_minutes === null || $job->accounted_at !== null) {
            return;
        }

        $monthKey = Carbon::now()->format('Y-m');
        $usage = StreamingMonthlyUsage::firstOrCreate(
            [
                'organization_id' => $job->organization_id,
                'month_key' => $monthKey,
            ],
            [
                'total_minutes' => 0,
                'billable_minutes' => 0,
                'overage_amount_cents' => 0,
            ]
        );

        $usage->total_minutes += max(0, (int) $job->duration_minutes);

        $freeMinutes = (int) config('streaming.billing.free_minutes_per_month', 1800);
        $rateCentsPerHour = (int) config('streaming.billing.rate_cents_per_hour', 8);
        $usage->billable_minutes = max($usage->total_minutes - $freeMinutes, 0);
        $usage->overage_amount_cents = (int) round(($usage->billable_minutes / 60) * $rateCentsPerHour);
        $usage->save();

        $job->update(['accounted_at' => now()]);
    }

    /**
     * Props for the host UI "YouTube readiness" stream status row.
     *
     * @param  OrganizationLivestream|UserLivestream  $livestream
     * @return array{status: ?string, livestreamStatus: string, streamStopRequested: bool, updatedAt: ?string, failureReason: ?string}
     */
    /**
     * Worker shutdown / host end messages are operational, not user-facing failures.
     */
    public static function isBenignTerminalFailureReason(?string $reason): bool
    {
        if ($reason === null || trim($reason) === '') {
            return true;
        }

        $lower = strtolower(trim($reason));

        foreach ([
            'shutdown signal',
            'worker received shutdown',
            'host ended stream',
            'host requested stop',
            'maximum stream duration reached',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function failureReasonForUi(?StreamingJob $job): ?string
    {
        if (! $job || $job->status !== 'failed') {
            return null;
        }

        $reason = trim((string) ($job->failure_reason ?? ''));
        if ($reason === '' || self::isBenignTerminalFailureReason($reason)) {
            return null;
        }

        return $reason;
    }

    public function queueStatusForUi(?StreamingJob $job, OrganizationLivestream|UserLivestream $livestream): array
    {
        app(StreamingLifecycleService::class)->reconcileForLivestream($livestream);

        $job = StreamingJob::query()
            ->where('livestream_kind', $livestream instanceof OrganizationLivestream ? 'organization' : 'user')
            ->where('livestream_id', $livestream->id)
            ->latest('id')
            ->first();

        $settings = is_array($livestream->settings) ? $livestream->settings : [];

        return [
            'status' => $job?->status,
            'livestreamStatus' => (string) $livestream->status,
            'streamStopRequested' => ! empty($settings['stream_stop_requested']),
            'updatedAt' => $job?->updated_at?->toIso8601String(),
            'failureReason' => self::failureReasonForUi($job),
            'ecsTaskArn' => $job?->ecs_task_arn,
            'ecsLastStatus' => $job?->ecs_last_status,
        ];
    }
}

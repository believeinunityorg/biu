<?php

namespace App\Services\Streaming;

use App\Models\OrganizationLivestream;
use App\Models\StreamingJob;
use App\Models\UserLivestream;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Application-owned stream lifecycle: timeouts, heartbeat staleness, and forced cleanup
 * when AWS callbacks are missing or the worker dies unexpectedly.
 */
class StreamingLifecycleService
{
    public function __construct(
        private readonly StreamingQueueService $streamingQueue,
        private readonly StreamingEcsTaskMonitor $ecsMonitor,
    ) {}

    public function recordHeartbeat(StreamingJob $job): void
    {
        if ($job->isTerminal()) {
            return;
        }

        $job->update(['last_heartbeat_at' => now()]);
    }

    /**
     * Reconcile all non-terminal jobs (scheduled task).
     *
     * @return int Number of jobs transitioned to a terminal state
     */
    public function reconcileAll(): int
    {
        $count = 0;

        StreamingJob::query()
            ->whereIn('status', ['queued', 'starting', 'live'])
            ->orderBy('id')
            ->chunkById(50, function (Collection $jobs) use (&$count): void {
                foreach ($jobs as $job) {
                    if ($this->reconcileJob($job)) {
                        $count++;
                    }
                }
            });

        $this->reconcileOrphanLivestreams();

        return $count;
    }

    /**
     * Reconcile the latest active job for one livestream (host page poll / show).
     */
    public function reconcileForLivestream(OrganizationLivestream|UserLivestream $livestream): void
    {
        $kind = $livestream instanceof OrganizationLivestream ? 'organization' : 'user';

        $job = StreamingJob::query()
            ->where('livestream_kind', $kind)
            ->where('livestream_id', $livestream->id)
            ->whereIn('status', ['queued', 'starting', 'live'])
            ->latest('id')
            ->first();

        if ($job) {
            $this->reconcileJob($job);
        }

        $livestream->refresh();
        $this->reconcileOrphanLivestreamRow($livestream, $kind);
    }

    /**
     * Host clicked End Stream: always mark the active job stopped locally.
     */
    public function forceTerminateActiveJob(string $livestreamKind, int $livestreamId, string $reason): bool
    {
        $job = $this->activeJobForLivestream($livestreamKind, $livestreamId);

        if (! $job) {
            return false;
        }

        $this->ecsMonitor->stopTask($job->ecs_task_arn, $reason);

        $durationMinutes = $this->estimateDurationMinutes($job);

        $this->streamingQueue->applyCallbackStatus(
            $job,
            'stopped',
            $durationMinutes,
            $reason
        );

        Log::info('Streaming job force-terminated locally', [
            'streaming_job_id' => $job->id,
            'livestream_kind' => $livestreamKind,
            'livestream_id' => $livestreamId,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * @return bool True if the job status changed
     */
    public function reconcileJob(StreamingJob $job): bool
    {
        $job->refresh();

        if ($job->isTerminal()) {
            return false;
        }

        if ($this->shouldSimulateWorker()) {
            return false;
        }

        $livestream = $this->resolveLivestream($job);
        if (! $livestream) {
            return $this->failJob($job, 'Livestream row missing; clearing stale job');
        }

        $now = now();

        if ($this->ecsMonitor->reconcileJob(
            $job,
            fn (StreamingJob $j, string $reason) => $this->failJob($j, $reason),
            fn (StreamingJob $j, string $reason) => $this->stopJob($j, $reason),
        )) {
            return true;
        }

        if ($this->stopRequestedGraceElapsed($livestream, $now)) {
            return $this->stopJob($job, 'Host requested stop; worker did not confirm shutdown in time');
        }

        if ($job->status === 'queued') {
            $timeout = (int) config('streaming.lifecycle.queued_timeout_seconds', 300);
            if ($job->created_at && $job->created_at->diffInSeconds($now) >= $timeout) {
                return $this->failJob($job, 'Worker never started (queued timeout)');
            }
        }

        if ($job->status === 'starting') {
            $timeout = (int) config('streaming.lifecycle.starting_timeout_seconds', 600);
            $anchor = $job->last_heartbeat_at ?? $job->updated_at ?? $job->created_at;
            if ($anchor && $anchor->diffInSeconds($now) >= $timeout) {
                return $this->failJob($job, 'Worker startup timed out');
            }
        }

        if ($job->status === 'live') {
            if ($this->heartbeatIsStale($job, $now)) {
                return $this->failJob($job, 'Worker heartbeat lost (stream may have disconnected)');
            }

            if ($this->maxDurationExceeded($job, $now)) {
                return $this->stopJob($job, 'Maximum stream duration reached');
            }
        }

        return false;
    }

    private function reconcileOrphanLivestreams(): void
    {
        foreach (OrganizationLivestream::query()
            ->whereIn('status', ['live', 'meeting_live', 'starting'])
            ->cursor() as $livestream) {
            $this->reconcileOrphanLivestreamRow($livestream, 'organization');
        }

        foreach (UserLivestream::query()
            ->whereIn('status', ['live', 'meeting_live', 'starting'])
            ->cursor() as $livestream) {
            $this->reconcileOrphanLivestreamRow($livestream, 'user');
        }
    }

    private function reconcileOrphanLivestreamRow(
        OrganizationLivestream|UserLivestream $livestream,
        string $kind,
    ): void {
        if ($this->activeJobForLivestream($kind, $livestream->id)) {
            return;
        }

        $latest = StreamingJob::query()
            ->where('livestream_kind', $kind)
            ->where('livestream_id', $livestream->id)
            ->latest('id')
            ->first();

        if (! $latest || ! $latest->isTerminal()) {
            return;
        }

        if (! in_array($livestream->status, ['live', 'meeting_live', 'starting'], true)) {
            return;
        }

        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        unset($settings['stream_stop_requested']);

        if (in_array($latest->status, ['completed', 'stopped'], true)) {
            $livestream->update([
                'status' => 'draft',
                'ended_at' => $livestream->ended_at ?? now(),
                'settings' => $settings ?: null,
            ]);

            Log::notice('Livestream reset after terminal job with no active worker', [
                'livestream_kind' => $kind,
                'livestream_id' => $livestream->id,
                'streaming_job_id' => $latest->id,
                'job_status' => $latest->status,
            ]);

            return;
        }

        if ($latest->status === 'failed') {
            $livestream->update([
                'status' => 'meeting_live',
                'settings' => $settings ?: null,
            ]);
        }
    }

    private function stopRequestedGraceElapsed(OrganizationLivestream|UserLivestream $livestream, Carbon $now): bool
    {
        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        $requestedAt = $settings['stream_stop_requested'] ?? null;

        if (empty($requestedAt)) {
            return false;
        }

        try {
            $at = Carbon::parse($requestedAt);
        } catch (\Throwable) {
            return true;
        }

        $grace = (int) config('streaming.lifecycle.stop_requested_grace_seconds', 45);

        return $at->diffInSeconds($now) >= $grace;
    }

    private function heartbeatIsStale(StreamingJob $job, Carbon $now): bool
    {
        $staleAfter = (int) config('streaming.lifecycle.heartbeat_stale_seconds', 120);
        $anchor = $job->last_heartbeat_at;

        if ($anchor === null && $job->status === 'starting') {
            $anchor = $job->updated_at ?? $job->created_at;
        }

        if ($anchor === null && $job->status === 'live') {
            $anchor = $job->live_at ?? $job->updated_at ?? $job->created_at;
        }

        if ($anchor === null) {
            return false;
        }

        return $anchor->diffInSeconds($now) >= $staleAfter;
    }

    private function maxDurationExceeded(StreamingJob $job, Carbon $now): bool
    {
        $maxMinutes = max(1, (int) ($job->max_duration_minutes ?: config('streaming.max_duration_minutes', 120)));
        $grace = (int) config('streaming.lifecycle.max_duration_grace_minutes', 5);
        $anchor = $job->live_at ?? $job->created_at;

        if (! $anchor) {
            return false;
        }

        return $anchor->diffInMinutes($now) >= ($maxMinutes + $grace);
    }

    private function failJob(StreamingJob $job, string $reason): bool
    {
        $durationMinutes = $this->estimateDurationMinutes($job);
        $this->streamingQueue->applyCallbackStatus($job, 'failed', $durationMinutes, $reason);

        Log::warning('Streaming job failed by lifecycle reconcile', [
            'streaming_job_id' => $job->id,
            'reason' => $reason,
        ]);

        return true;
    }

    private function stopJob(StreamingJob $job, string $reason): bool
    {
        $durationMinutes = $this->estimateDurationMinutes($job);
        $this->streamingQueue->applyCallbackStatus($job, 'stopped', $durationMinutes, $reason);

        Log::info('Streaming job stopped by lifecycle reconcile', [
            'streaming_job_id' => $job->id,
            'reason' => $reason,
        ]);

        return true;
    }

    private function estimateDurationMinutes(StreamingJob $job): int
    {
        $start = $job->live_at ?? $job->created_at;

        if (! $start) {
            return 0;
        }

        return max(0, (int) $start->diffInMinutes(now()));
    }

    private function activeJobForLivestream(string $kind, int $livestreamId): ?StreamingJob
    {
        return StreamingJob::query()
            ->where('livestream_kind', $kind)
            ->where('livestream_id', $livestreamId)
            ->whereIn('status', ['queued', 'starting', 'live'])
            ->latest('id')
            ->first();
    }

    private function resolveLivestream(StreamingJob $job): OrganizationLivestream|UserLivestream|null
    {
        if ($job->livestream_kind === 'organization') {
            return OrganizationLivestream::find($job->livestream_id);
        }

        return UserLivestream::find($job->livestream_id);
    }

    private function shouldSimulateWorker(): bool
    {
        if (! (bool) config('streaming.simulate_worker', false)) {
            return false;
        }

        if (app()->environment('production') && ! (bool) config('streaming.simulate_worker_force', false)) {
            return false;
        }

        return true;
    }
}

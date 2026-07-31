<?php

namespace App\Services\Streaming;

use App\Models\StreamingJob;
use Aws\Ecs\EcsClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;

/**
 * Poll ECS Fargate task state (DescribeTasks) so UI/billing do not depend only on worker callbacks.
 */
class StreamingEcsTaskMonitor
{
    public function isEnabled(): bool
    {
        if ($this->shouldSimulateWorker()) {
            return false;
        }

        if (! (bool) config('streaming.ecs.enabled', true)) {
            return false;
        }

        return (string) config('streaming.ecs.cluster', '') !== '';
    }

    public function persistTaskReference(StreamingJob $job, string $taskArn, ?string $ecsStatus = null): void
    {
        $taskArn = trim($taskArn);
        if ($taskArn === '') {
            return;
        }

        $job->update([
            'ecs_task_arn' => $taskArn,
            'ecs_last_status' => $ecsStatus !== null && $ecsStatus !== '' ? strtoupper($ecsStatus) : $job->ecs_last_status,
            'ecs_checked_at' => now(),
        ]);
    }

    /**
     * @param  callable(StreamingJob, string): bool  $failJob
     * @param  callable(StreamingJob, string): bool  $stopJob
     */
    public function reconcileJob(StreamingJob $job, callable $failJob, callable $stopJob): bool
    {
        if (! $this->isEnabled() || empty($job->ecs_task_arn)) {
            return false;
        }

        $task = $this->describeTask((string) $job->ecs_task_arn);

        if ($task === false) {
            return false;
        }

        if ($task === null) {
            return $failJob($job, 'ECS task not found (worker exited or never launched)');
        }

        $lastStatus = strtoupper((string) ($task['lastStatus'] ?? ''));
        $desiredStatus = strtoupper((string) ($task['desiredStatus'] ?? ''));
        $stoppedReason = trim((string) ($task['stoppedReason'] ?? ''));
        $exitCode = $task['exitCode'] ?? null;

        $job->update([
            'ecs_last_status' => $lastStatus !== '' ? $lastStatus : $desiredStatus,
            'ecs_checked_at' => now(),
        ]);

        if (in_array($lastStatus, ['STOPPED'], true) || $desiredStatus === 'STOPPED') {
            $detail = $stoppedReason !== '' ? $stoppedReason : 'ECS task stopped';
            if ($exitCode !== null) {
                $detail .= " (exit {$exitCode})";
            }

            return $stopJob($job, $detail);
        }

        if ($lastStatus === 'DEPROVISIONING') {
            return $stopJob($job, 'ECS task deprovisioning');
        }

        // SQS message consumed and container running, but Laravel never got "starting" callback.
        if ($job->status === 'queued' && in_array($lastStatus, ['RUNNING', 'PENDING', 'PROVISIONING'], true)) {
            $age = $job->created_at?->diffInSeconds(now()) ?? 0;
            if ($age >= 20 && $lastStatus === 'RUNNING') {
                app(StreamingQueueService::class)->applyCallbackStatus($job->fresh(), 'starting', null, null);
            }
        }

        return false;
    }

    public function stopTask(?string $taskArn, string $reason = 'Stopped by Believe In Unity'): bool
    {
        $taskArn = trim((string) $taskArn);
        if ($taskArn === '' || ! $this->isEnabled()) {
            return false;
        }

        try {
            $this->client()->stopTask([
                'cluster' => (string) config('streaming.ecs.cluster'),
                'task' => $taskArn,
                'reason' => substr($reason, 0, 255),
            ]);

            Log::info('ECS StopTask sent', ['task_arn' => $taskArn, 'reason' => $reason]);

            return true;
        } catch (AwsException $e) {
            Log::warning('ECS StopTask failed', [
                'task_arn' => $taskArn,
                'error' => $e->getAwsErrorMessage() ?: $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{lastStatus?: string, desiredStatus?: string, stoppedReason?: string, exitCode?: int|null}|null|false
     */
    private function describeTask(string $taskArn): array|false|null
    {
        try {
            $result = $this->client()->describeTasks([
                'cluster' => (string) config('streaming.ecs.cluster'),
                'tasks' => [$taskArn],
            ]);

            $tasks = $result->get('tasks') ?? [];
            if ($tasks === []) {
                return null;
            }

            $task = $tasks[0];
            $exitCode = null;
            $containers = $task['containers'] ?? [];
            if (isset($containers[0]['exitCode'])) {
                $exitCode = (int) $containers[0]['exitCode'];
            }

            return [
                'lastStatus' => (string) ($task['lastStatus'] ?? ''),
                'desiredStatus' => (string) ($task['desiredStatus'] ?? ''),
                'stoppedReason' => (string) ($task['stoppedReason'] ?? ''),
                'exitCode' => $exitCode,
            ];
        } catch (AwsException $e) {
            Log::warning('ECS DescribeTasks failed', [
                'task_arn' => $taskArn,
                'error' => $e->getAwsErrorMessage() ?: $e->getMessage(),
            ]);

            return false;
        }
    }

    private function client(): EcsClient
    {
        $config = [
            'version' => 'latest',
            'region' => (string) config('streaming.ecs.region', config('streaming.region')),
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

        return new EcsClient($config);
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

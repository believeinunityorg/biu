<?php

namespace App\Jobs;

use App\Enums\PushNotificationModule;
use App\Jobs\Concerns\UsesPushNotificationQueue;
use App\Models\JobPost;
use App\Services\FirebaseService;
use App\Support\ShortChannelNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendJobPostNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesPushNotificationQueue;

    public $jobPost;
    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(JobPost $jobPost)
    {
        $this->jobPost = $jobPost;
        $this->configurePushNotificationQueue();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $organization = $this->jobPost->organization;

            if (!$organization) {
                Log::warning('Job post has no organization', ['job_post_id' => $this->jobPost->id]);
                return;
            }

            // Get followers who have notifications enabled
            $followers = $organization->followers()
                ->wherePivot('notifications', true)
                ->get();

            if ($followers->isEmpty()) {
                Log::info('No followers with notifications enabled', [
                    'organization_id' => $organization->id,
                    'job_post_id' => $this->jobPost->id
                ]);
                return;
            }

            $positionName = $this->jobPost->position->title ?? 'New Position';
            $title = "New Job Opportunity";
            $body = "{$organization->name} posted a new {$positionName} position";
            $jobUrl = route('jobs.show', $this->jobPost->id);


            $firebaseService = app(FirebaseService::class);

            foreach ($followers as $follower) {
                $this->sendNotificationToFollower($follower, $title, $body, $jobUrl, $firebaseService);
            }

        } catch (\Exception $e) {
            Log::error('Error in SendJobPostNotification job: ' . $e->getMessage(), [
                'job_post_id' => $this->jobPost->id ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Send notification to individual follower
     */
    private function sendNotificationToFollower($follower, $title, $body, $jobUrl, $firebaseService): void
    {
        try {
            $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::Volunteer);
            $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::Volunteer);
            $pushLinks = ShortChannelNotification::pushDeepLinkData();

            $data = [
                'content_item_id' => (string) $this->jobPost->id,
                'type' => 'job_post',
                'job_id' => (string) $this->jobPost->id,
                'url' => $pushLinks['url'],
                'click_action' => $pushLinks['click_action'],
                'source_type' => 'job_post',
                'source_id' => (string) $this->jobPost->id,
                'organization_id' => (string) $this->jobPost->organization_id,
                'module_name' => PushNotificationModule::Volunteer->value,
                'module_record_id' => $this->jobPost->id,
                'created_by' => $this->jobPost->user_id ?? $this->jobPost->organization?->user_id,
                'deep_link' => $pushLinks['deep_link'],
            ];

            $result = $firebaseService->sendToUser($follower->id, $pushTitle, $pushBody, $data);

            // Store in database notifications
            // $this->storeDatabaseNotification($follower, $title, $body, $data);

            $successCount = is_array($result) ? count(array_filter($result, fn ($r) => ($r['success'] ?? false))) : 0;
            if ($successCount > 0) {
                Log::info('✅ Firebase Job notification sent successfully', [
                    'user_id' => $follower->id,
                    'job_post_id' => $this->jobPost->id,
                ]);
            } else {
                Log::warning('❌ Firebase Job notification failed', [
                    'user_id' => $follower->id,
                    'job_post_id' => $this->jobPost->id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error sending notification to follower: ' . $e->getMessage(), [
                'follower_id' => $follower->id,
                'job_post_id' => $this->jobPost->id
            ]);
        }
    }

    /**
     * Store notification in database
     */
    private function storeDatabaseNotification($user, $title, $body, $data): void
    {
        try {
            if (class_exists('App\Models\Notification')) {
                $user->notifications()->create([
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'type' => 'job_post',
                    'read_at' => null,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error storing database notification: ' . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendJobPostNotification job failed: ' . $exception->getMessage(), [
            'job_post_id' => $this->jobPost->id ?? 'unknown',
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Enums\PushNotificationModule;
use App\Jobs\Concerns\UsesPushNotificationQueue;
use App\Models\Course;
use App\Models\Organization;
use App\Services\FirebaseService;
use App\Support\ConnectionHubType;
use App\Support\ShortChannelNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCourseNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesPushNotificationQueue;

    public $course;
    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(Course $course)
    {
        $this->course = $course;
        $this->configurePushNotificationQueue();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $organization = $this->course->organization->organization;

            if (!$organization) {
                Log::warning('Course has no organization', ['course_id' => $this->course->id]);
                return;
            }

            // Get followers who have notifications enabled
            $followers = $organization->followers()
                ->wherePivot('notifications', true)
                ->get();

            if ($followers->isEmpty()) {
                Log::info('No followers with notifications enabled', [
                    'organization_id' => $organization->id,
                    'course_id' => $this->course->id
                ]);
                return;
            }

            $courseName = $this->course->name;
            $hubType = (string) ($this->course->type ?? ConnectionHubType::LEARNING);
            $typeLabel = ConnectionHubType::label($hubType);
            $listingNoun = match ($hubType) {
                ConnectionHubType::EVENTS => 'meetup',
                ConnectionHubType::COMPANION => 'companion',
                ConnectionHubType::EARNING => 'earning',
                default => 'course',
            };
            $topicName = $this->course->topic->name ?? "New {$typeLabel}";
            $title = "New {$typeLabel} Available";
            $body = "{$organization->name} created a new {$listingNoun} in {$topicName}: {$courseName}";
            $courseUrl = route('course.show', $this->course->slug);

            $firebaseService = app(FirebaseService::class);

            foreach ($followers as $follower) {
                $this->sendNotificationToFollower($follower, $organization, $title, $body, $courseUrl, $firebaseService);
            }

            Log::info('Course notifications sent successfully', [
                'course_id' => $this->course->id,
                'organization_id' => $organization->id,
                'followers_count' => $followers->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Error in SendCourseNotification job: ' . $e->getMessage(), [
                'course_id' => $this->course->id ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Send notification to individual follower
     */
    private function sendNotificationToFollower($follower, Organization $organization, $title, $body, $courseUrl, $firebaseService): void
    {
        try {
            $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::Courses);
            $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::Courses);
            $pushLinks = ShortChannelNotification::pushDeepLinkData();

            $data = [
                'content_item_id' => (string) $this->course->id,
                'type' => 'new_course',
                'course_id' => (string) $this->course->id,
                'course_slug' => $this->course->slug,
                'topic_id' => (string) $this->course->topic_id,
                'organization_id' => (string) $organization->id,
                'module_name' => PushNotificationModule::Courses->value,
                'module_record_id' => $this->course->id,
                'created_by' => $this->course->user_id ?? $organization->user_id,
                'deep_link' => $pushLinks['deep_link'],
                'url' => $pushLinks['url'],
                'click_action' => $pushLinks['click_action'],
                'source_type' => 'course',
                'source_id' => (string) $this->course->id,
            ];

            // Push: short teaser → Notifications. Inbox DB keeps detailed $title/$body.
            $result = $firebaseService->sendToUser($follower->id, $pushTitle, $pushBody, $data);

            $this->storeDatabaseNotification($follower, $title, $body, array_merge($data, [
                'url' => $courseUrl,
                'click_action' => $courseUrl,
                'deep_link' => parse_url($courseUrl, PHP_URL_PATH) ?: $courseUrl,
            ]));

            $successCount = is_array($result) ? count(array_filter($result, fn ($r) => ($r['success'] ?? false))) : 0;
            if ($successCount > 0) {
                Log::info('✅ Firebase Course notification sent successfully', [
                    'user_id' => $follower->id,
                    'course_id' => $this->course->id,
                ]);
            } else {
                Log::warning('❌ Firebase Course notification failed', [
                    'user_id' => $follower->id,
                    'course_id' => $this->course->id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error sending notification to follower: ' . $e->getMessage(), [
                'follower_id' => $follower->id,
                'course_id' => $this->course->id
            ]);
        }
    }

    /**
     * Store notification in database
     */
    private function storeDatabaseNotification($user, $title, $body, $data): void
    {
        try {
            $user->notifications()->create([
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'type' => 'new_course',
                'read_at' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error storing database notification: ' . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendCourseNotification job failed: ' . $exception->getMessage(), [
            'course_id' => $this->course->id ?? 'unknown',
        ]);
    }
}

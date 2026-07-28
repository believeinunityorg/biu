<?php

namespace App\Jobs;

use App\Enums\PushNotificationModule;
use App\Jobs\Concerns\UsesPushNotificationQueue;
use App\Models\Event;
use App\Models\User;
use App\Services\FirebaseService;
use App\Support\ShortChannelNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendEventNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesPushNotificationQueue;

    public $event;
    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(Event $event)
    {
        $this->event = $event;
        $this->configurePushNotificationQueue();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $organization = $this->event->organization;

            // Determine who created the event and get their followers
            if ($organization) {
                // Event created by organization - notify organization followers
                $users = User::where('role', 'user')->get();
                $followers = $organization->followers()
                    ->wherePivot('notifications', true)
                    ->get();

                $creatorName = $organization->name;
                $creatorType = 'organization';
            }

            if ($this->event->visibility === 'private' && $followers->isEmpty()) {
                Log::info('No followers found for event creator', [
                    'event_id' => $this->event->id,
                    'creator_type' => $creatorType,
                    'creator_id' => $organization->id,
                ]);

                return;
            }

            $eventName = $this->event->name;
            $eventType = $this->event->eventType->name ?? 'Event';
            $location = $this->event->city ?: $this->event->location;

            $title = ShortChannelNotification::pushTitle(PushNotificationModule::Events);
            $body = ShortChannelNotification::pushBody(PushNotificationModule::Events);
            $pushLinks = ShortChannelNotification::pushDeepLinkData();

            $firebaseService = app(FirebaseService::class);

            if ($this->event->visibility === 'private') {
                $followerIds = $followers->pluck('id')->all();
            } elseif ($this->event->visibility === 'public') {
                $followerIds = $users->pluck('id')->all();
            } else {
                $followerIds = [];
            }

            if ($followerIds !== []) {
                $firebaseService->sendToUsers($followerIds, $title, $body, [
                    'content_item_id' => (string) $this->event->id,
                    'type' => 'new_event',
                    'event_id' => (string) $this->event->id,
                    'event_type_id' => $this->event->event_type_id ? (string) $this->event->event_type_id : null,
                    'organization_id' => $this->event->organization_id,
                    'user_id' => $this->event->user_id ? (string) $this->event->user_id : null,
                    'url' => $pushLinks['url'],
                    'click_action' => $pushLinks['click_action'],
                    'module_name' => PushNotificationModule::Events->value,
                    'module_record_id' => $this->event->id,
                    'deep_link' => $pushLinks['deep_link'],
                    'audience_type' => $this->event->visibility === 'public' ? 'all_users' : 'followers',
                    'created_by' => $this->event->user_id ?? $organization?->user_id,
                ]);
            }

            Log::info('Event notifications sent successfully', [
                'event_id' => $this->event->id,
                'creator_type' => $creatorType,
                'creator_name' => $creatorName,
                'event_name' => $eventName,
                'event_type' => $eventType,
                'location' => $location,
                'recipients_count' => count($followerIds),
            ]);

        } catch (\Exception $e) {
            Log::error('Error in SendEventNotification job: ' . $e->getMessage(), [
                'event_id' => $this->event->id ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendEventNotification job failed: ' . $exception->getMessage(), [
            'event_id' => $this->event->id ?? 'unknown',
        ]);
    }
}

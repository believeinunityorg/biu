<?php

namespace App\Jobs;

use App\Enums\PushNotificationModule;
use App\Jobs\Concerns\UsesPushNotificationQueue;
use App\Models\OrganizationDiscussion;
use App\Services\FirebaseService;
use App\Support\ShortChannelNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDiscussionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesPushNotificationQueue;

    public $tries = 3;

    public $timeout = 60;

    public function __construct(public OrganizationDiscussion $discussion)
    {
        $this->configurePushNotificationQueue();
    }

    public function handle(): void
    {
        try {
            $discussion = $this->discussion->fresh(['organization']);

            if (! $discussion) {
                return;
            }

            if ($discussion->approved_at === null || $discussion->is_hidden || $discussion->is_archived) {
                Log::info('Skipping discussion push — not visible yet', [
                    'discussion_id' => $discussion->id,
                    'approved_at' => $discussion->approved_at,
                    'is_hidden' => $discussion->is_hidden,
                    'is_archived' => $discussion->is_archived,
                ]);

                return;
            }

            $organization = $discussion->organization;

            if (! $organization) {
                Log::warning('Discussion has no organization', [
                    'discussion_id' => $discussion->id,
                ]);

                return;
            }

            $followerIds = $organization->followers()
                ->wherePivot('notifications', true)
                ->get()
                ->pluck('id')
                ->all();

            if ($followerIds === []) {
                Log::info('No organization followers with notifications enabled for discussion', [
                    'organization_id' => $organization->id,
                    'discussion_id' => $discussion->id,
                ]);

                return;
            }

            $title = ShortChannelNotification::pushTitle(PushNotificationModule::Discussions);
            $body = ShortChannelNotification::pushBody(PushNotificationModule::Discussions);
            $pushLinks = ShortChannelNotification::pushDeepLinkData();

            app(FirebaseService::class)->sendToUsers($followerIds, $title, $body, [
                'content_item_id' => (string) $discussion->id,
                'type' => 'new_discussion',
                'discussion_id' => (string) $discussion->id,
                'discussion_slug' => $discussion->slug,
                'organization_id' => (string) $organization->id,
                'url' => $pushLinks['url'],
                'click_action' => $pushLinks['click_action'],
                'module_name' => PushNotificationModule::Discussions->value,
                'module_record_id' => $discussion->id,
                'deep_link' => $pushLinks['deep_link'],
                'audience_type' => 'followers',
                'source_type' => 'discussion',
                'source_id' => (string) $discussion->id,
                'created_by' => $discussion->approved_by ?? $discussion->created_by ?? $organization->user_id,
            ]);

            Log::info('Discussion push notifications dispatched', [
                'discussion_id' => $discussion->id,
                'organization_id' => $organization->id,
                'recipients_count' => count($followerIds),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in SendDiscussionNotification job: '.$e->getMessage(), [
                'discussion_id' => $this->discussion->id ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendDiscussionNotification job failed: '.$exception->getMessage(), [
            'discussion_id' => $this->discussion->id ?? 'unknown',
        ]);
    }
}

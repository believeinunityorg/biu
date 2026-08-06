<?php

namespace App\Jobs;

use App\Enums\OrganizationAnnouncementStatus;
use App\Enums\PushNotificationModule;
use App\Jobs\Concerns\UsesPushNotificationQueue;
use App\Models\OrganizationAnnouncement;
use App\Services\FirebaseService;
use App\Support\ShortChannelNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAnnouncementNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesPushNotificationQueue;

    public $tries = 3;

    public $timeout = 60;

    public function __construct(public OrganizationAnnouncement $announcement)
    {
        $this->configurePushNotificationQueue();
    }

    public function handle(): void
    {
        try {
            $announcement = $this->announcement->fresh(['organization']);

            if (! $announcement) {
                return;
            }

            if ($announcement->status !== OrganizationAnnouncementStatus::Published) {
                Log::info('Skipping announcement push — not published', [
                    'announcement_id' => $announcement->id,
                    'status' => $announcement->status?->value,
                ]);

                return;
            }

            $organization = $announcement->organization;

            if (! $organization) {
                Log::warning('Announcement has no organization', [
                    'announcement_id' => $announcement->id,
                ]);

                return;
            }

            $followerIds = $organization->followers()
                ->wherePivot('notifications', true)
                ->get()
                ->pluck('id')
                ->all();

            if ($followerIds === []) {
                Log::info('No organization followers with notifications enabled for announcement', [
                    'organization_id' => $organization->id,
                    'announcement_id' => $announcement->id,
                ]);

                return;
            }

            $title = ShortChannelNotification::pushTitle(PushNotificationModule::Announcements);
            $body = ShortChannelNotification::pushBody(PushNotificationModule::Announcements);
            $pushLinks = ShortChannelNotification::pushDeepLinkData();

            app(FirebaseService::class)->sendToUsers($followerIds, $title, $body, [
                'content_item_id' => (string) $announcement->id,
                'type' => 'new_announcement',
                'announcement_id' => (string) $announcement->id,
                'announcement_slug' => $announcement->slug,
                'organization_id' => (string) $organization->id,
                'url' => $pushLinks['url'],
                'click_action' => $pushLinks['click_action'],
                'module_name' => PushNotificationModule::Announcements->value,
                'module_record_id' => $announcement->id,
                'deep_link' => $pushLinks['deep_link'],
                'audience_type' => 'followers',
                'source_type' => 'announcement',
                'source_id' => (string) $announcement->id,
                'created_by' => $announcement->published_by ?? $announcement->created_by ?? $organization->user_id,
            ]);

            Log::info('Announcement push notifications dispatched', [
                'announcement_id' => $announcement->id,
                'organization_id' => $organization->id,
                'recipients_count' => count($followerIds),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in SendAnnouncementNotification job: '.$e->getMessage(), [
                'announcement_id' => $this->announcement->id ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendAnnouncementNotification job failed: '.$exception->getMessage(), [
            'announcement_id' => $this->announcement->id ?? 'unknown',
        ]);
    }
}

<?php

namespace App\Notifications;

use App\Enums\PushNotificationModule;
use App\Mail\CampaignPrayerMail;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\Organization;
use App\Services\FirebaseService;
use App\Support\ShortChannelNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;
use Illuminate\Support\Facades\Log;

class DailyPrayerNotification extends Notification implements ShouldQueue, ShouldBroadcast
{
    use Queueable;

    protected $contentItem;
    protected $channel;
    protected $notifiable; // Add this property
    protected ?Campaign $campaign;
    protected ?int $campaignId;

    public function __construct(ContentItem $contentItem, string $channel = 'web', ?Campaign $campaign = null)
    {
        $this->contentItem = $contentItem;
        $this->channel = $channel;
        $this->campaign = $campaign;
        $this->campaignId = $campaign?->id;
        $this->connection = (string) config('services.firebase.queue_connection', 'sync');
    }

    public function via($notifiable): array
    {
        // Store the notifiable for broadcast
        $this->notifiable = $notifiable;

        // Channel-specific via methods
        switch ($this->channel) {
            case 'whatsapp':
                // WhatsApp: Only send via Twilio
                return [TwilioChannel::class];

            case 'push':
                // Push: Only broadcast and database
                return ['broadcast', 'database', 'firebase'];

            case 'web':
                // Web: Only broadcast and database
                return ['broadcast', 'database', 'firebase'];

            case 'email':
                return ['mail'];

            default:
                // Default: Only database
                return ['database'];
        }
    }

    public function toMail($notifiable): CampaignPrayerMail
    {
        $sender = $this->resolveSenderMeta();
        $organizationName = trim((string) ($sender['organization_name'] ?? ''));
        $contentUrl = route('notifications.content.show', ['content_item' => $this->contentItem->id]);
        $scriptureRef = null;
        if (is_array($this->contentItem->meta) && ! empty($this->contentItem->meta['scripture_ref'])) {
            $scriptureRef = (string) $this->contentItem->meta['scripture_ref'];
        }

        // MailChannel sends Mailables as-is and does not attach the notifiable's
        // address, so the recipient must be set explicitly on the mailable.
        $to = $notifiable->routeNotificationFor('mail', $this);
        if (empty($to)) {
            $to = trim((string) ($notifiable->email ?? ''));
        }

        return (new CampaignPrayerMail(
            recipientName: trim((string) ($notifiable->name ?? '')),
            organizationName: $organizationName,
            title: (string) $this->contentItem->title,
            bodyText: strip_tags((string) $this->contentItem->body),
            scriptureRef: $scriptureRef,
            contentUrl: $contentUrl,
        ))->to($to);
    }

    public function toFirebase($notifiable)
    {
        if (! $notifiable->hasActivePushDevice()) {
            Log::warning('User has no active push device', ['user_id' => $notifiable->id]);
            return null;
        }

        Log::info('Sending Firebase notification for content item', [
            'content_item_id' => $this->contentItem->id,
            'title' => $this->contentItem->title,
            'user_id' => $notifiable->id,
        ]);

        try {
            $firebaseService = new FirebaseService();

            $campaign = $this->resolveCampaign();

            $organizationId = $campaign?->organization_id ?? $this->contentItem->organization_id;

            if (! $organizationId && $this->campaignId) {
                $organizationId = Campaign::query()->whereKey($this->campaignId)->value('organization_id');
            }

            $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::Campaigns);
            $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::Campaigns);
            $pushLinks = ShortChannelNotification::pushDeepLinkData();

            $data = [
                'content_item_id' => (string) $this->contentItem->id,
                'type' => $this->contentItem->type,
                'channel' => $this->channel,
                'click_action' => $pushLinks['click_action'],
                'url' => $pushLinks['url'],
                'source_type' => 'campaign',
                'source_id' => (string) ($campaign?->id ?? $this->contentItem->id),
                'module_name' => PushNotificationModule::Campaigns->value,
                'module_record_id' => $campaign?->id ?? $this->contentItem->id,
                'created_by' => $campaign?->user_id ?? $this->contentItem->user_id,
                'deep_link' => $pushLinks['deep_link'],
            ];

            if ($campaign) {
                $data['campaign_id'] = (string) $campaign->id;
                $data['notification_context'] = 'organization_daily_campaign';
            } elseif ($this->campaignId) {
                $data['campaign_id'] = (string) $this->campaignId;
                $data['notification_context'] = 'organization_daily_campaign';
            }

            if ($organizationId) {
                $data['organization_id'] = (string) $organizationId;

                $organization = Organization::query()
                    ->with('user:id,image,registered_user_image')
                    ->find($organizationId);

                if ($organization) {
                    $orgName = trim((string) ($organization->name ?? ''));
                    if ($orgName !== '') {
                        $data['organization_name'] = $orgName;
                    }

                    $logoUrl = $organization->logoUrl();
                    if ($logoUrl) {
                        $data['organization_logo_url'] = $logoUrl;
                    }
                }
            }

            // Add meta data if exists
            if ($this->contentItem->meta && is_array($this->contentItem->meta)) {
                foreach ($this->contentItem->meta as $key => $value) {
                    if (is_array($value)) {
                        $data['meta_' . $key] = json_encode($value);
                    } else {
                        $data['meta_' . $key] = (string) $value;
                    }
                }
            }

            // Add image URL if available
            if (isset($this->contentItem->meta['image_url'])) {
                $data['image_url'] = $this->contentItem->meta['image_url'];
            }


            $result = $firebaseService->sendToUser(
                $notifiable->id,
                $pushTitle,
                $pushBody,
                $data
            );

            Log::info('Firebase notification sent successfully', [
                'user_id' => $notifiable->id,
                'content_item_id' => $this->contentItem->id,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Firebase notification error', [
                'user_id' => $notifiable->id,
                'content_item_id' => $this->contentItem->id,
                'error_message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveCampaign(): ?Campaign
    {
        if ($this->campaign) {
            return $this->campaign;
        }

        if (! $this->campaignId) {
            return null;
        }

        return Campaign::query()->find($this->campaignId);
    }

    /**
     * Define the broadcast channels
     */
    public function broadcastOn()
    {
        // Check if notifiable is set
        if (!$this->notifiable) {
            return [];
        }

        // Broadcast to user's private channel
        return new \Illuminate\Broadcasting\PrivateChannel('user.' . $this->notifiable->id);
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs()
    {
        return 'campaign.notification';
    }

    /**
     * Get the data to broadcast
     */
    public function toBroadcast($notifiable): BroadcastMessage
    {
        // Store notifiable if not already set
        if (!$this->notifiable) {
            $this->notifiable = $notifiable;
        }

        $sender = $this->resolveSenderMeta();

        return new BroadcastMessage([
            'id' => $this->id, // Notification ID
            'title' => $this->contentItem->title,
            'body' => strip_tags($this->contentItem->body),
            'content_item_id' => $this->contentItem->id,
            'type' => $this->contentItem->type,
            'content_type' => $this->contentItem->type,
            'channel' => $this->channel,
            'meta' => array_merge($this->contentItem->meta ?? [], $sender['meta']),
            'organization_id' => $sender['organization_id'],
            'organization_name' => $sender['organization_name'],
            'created_by' => $sender['created_by'],
            'creator_name' => $sender['creator_name'],
            'timestamp' => now()->toISOString(),
            'notification_id' => $this->id,
        ]);
    }

    public function toTwilio($notifiable)
    {
        Log::info('Sending WhatsApp notification', [
            'user_id' => $notifiable->id,
            'content_item_id' => $this->contentItem->id,
        ]);

        // Twilio WhatsApp only — short teaser + Notifications deep link (no full content body).
        $message = ShortChannelNotification::whatsAppBody(PushNotificationModule::Campaigns);

        return (new TwilioSmsMessage())
            ->content($message);
    }

    public function toDatabase($notifiable): array
    {
        $sender = $this->resolveSenderMeta();

        return [
            'title' => $this->contentItem->title,
            'body' => strip_tags($this->contentItem->body),
            'content_item_id' => $this->contentItem->id,
            'type' => $this->contentItem->type,
            'channel' => $this->channel,
            'meta' => array_merge($this->contentItem->meta ?? [], $sender['meta']),
            'organization_id' => $sender['organization_id'],
            'organization_name' => $sender['organization_name'],
            'created_by' => $sender['created_by'],
            'creator_name' => $sender['creator_name'],
            'sent_at' => now()->toISOString(),
        ];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->contentItem->title,
            'body' => strip_tags($this->contentItem->body),
            'content_item_id' => $this->contentItem->id,
            'channel' => $this->channel,
        ];
    }

    /**
     * @return array{organization_id: int|null, organization_name: string|null, created_by: int|null, creator_name: string|null, meta: array<string, mixed>}
     */
    private function resolveSenderMeta(): array
    {
        $campaign = $this->resolveCampaign();
        $organizationId = $campaign?->organization_id ?? $this->contentItem->organization_id;

        if (! $organizationId && $this->campaignId) {
            $organizationId = Campaign::query()->whereKey($this->campaignId)->value('organization_id');
        }

        $organizationName = null;
        if ($organizationId) {
            $organizationName = Organization::query()->whereKey($organizationId)->value('name');
            $organizationName = is_string($organizationName) ? trim($organizationName) : null;
            if ($organizationName === '') {
                $organizationName = null;
            }
        }

        $createdBy = $campaign?->user_id ?? $this->contentItem->user_id;
        $creatorName = null;
        if ($createdBy) {
            $creatorName = \App\Models\User::query()->whereKey($createdBy)->value('name');
            $creatorName = is_string($creatorName) ? trim($creatorName) : null;
            if ($creatorName === '') {
                $creatorName = null;
            }
        }

        $meta = array_filter([
            'organization_id' => $organizationId,
            'organization_name' => $organizationName,
            'created_by' => $createdBy,
            'creator_name' => $creatorName,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            'organization_id' => $organizationId ? (int) $organizationId : null,
            'organization_name' => $organizationName,
            'created_by' => $createdBy ? (int) $createdBy : null,
            'creator_name' => $creatorName,
            'meta' => $meta,
        ];
    }
}

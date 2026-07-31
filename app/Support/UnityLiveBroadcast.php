<?php

namespace App\Support;

use App\Events\UnityLiveViewerStatusChanged;
use App\Events\UnityMeetGiftReceived;
use App\Events\UnityMeetHostDashboardChanged;
use App\Models\LivestreamRecordingDecline;
use App\Models\OrganizationLivestream;
use App\Models\StreamingJob;
use App\Models\User;
use App\Models\UserLivestream;
use App\Services\Streaming\StreamingQueueService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UnityLiveBroadcast
{
    public static function channelName(OrganizationLivestream|UserLivestream $livestream): string
    {
        $kind = $livestream instanceof UserLivestream ? 'user' : 'organization';

        return 'unity-live.'.$kind.'.'.$livestream->id;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payloadFor(
        OrganizationLivestream|UserLivestream $livestream,
        string $reason,
        ?string $message = null,
    ): array {
        $livestream->refresh();
        $livestream->loadMissing($livestream instanceof UserLivestream ? 'user' : 'organization');

        $kind = $livestream instanceof UserLivestream ? 'user' : 'organization';
        $hostName = $kind === 'user'
            ? ($livestream->user?->name ?? 'Host')
            : ($livestream->organization?->name ?? 'Organization');

        return [
            'kind' => $kind,
            'livestreamId' => $livestream->id,
            'roomName' => $livestream->room_name,
            'status' => (string) $livestream->status,
            'isPublic' => (bool) $livestream->is_public,
            'reason' => $reason,
            'message' => $message ?? self::defaultMessage($reason),
            'hostName' => $hostName,
            'title' => $livestream->title ?: 'Live Stream',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function hostDashboardPayload(
        OrganizationLivestream|UserLivestream $livestream,
        string $reason,
    ): array {
        $livestream->refresh();
        $livestream->loadMissing($livestream instanceof UserLivestream ? 'user' : 'organization');

        /** @var StreamingQueueService $streamingQueue */
        $streamingQueue = app(StreamingQueueService::class);
        $kind = $livestream instanceof UserLivestream ? 'user' : 'organization';
        $settings = is_array($livestream->settings) ? $livestream->settings : [];

        $latestJob = StreamingJob::query()
            ->where('livestream_kind', $kind)
            ->where('livestream_id', $livestream->id)
            ->latest('id')
            ->first();

        $ownerKey = $kind === 'user'
            ? (string) ($livestream->user_id ?? '')
            : (string) ($livestream->organization_id ?? '');

        $canStartJob = $ownerKey !== '' && $streamingQueue->canStartNewStreamingJob($ownerKey, $kind, $livestream->id);

        if ($livestream instanceof UserLivestream) {
            $canQueueYoutubeLive = $livestream->canQueueYoutubeStream() && $canStartJob;
            $canSetUnityLive = $livestream->canSetUnityLive();
            $canGoLive = ($livestream->wantsYoutubeLiveAtCreate() && $canQueueYoutubeLive)
                || ($livestream->is_public && $canSetUnityLive);
        } else {
            $canQueueYoutubeLive = $livestream->canGoLive() && $canStartJob;
            $canSetUnityLive = $livestream->canGoLive();
            $canGoLive = $canQueueYoutubeLive;
        }

        $recordingConsentDeclines = LivestreamRecordingDecline::query()
            ->where('livestream_kind', $kind)
            ->where('livestream_id', $livestream->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (LivestreamRecordingDecline $row) => [
                'id' => $row->id,
                'guestLabel' => $row->guest_label,
                'createdAt' => $row->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $participantRoster = $kind === 'user'
            ? LivestreamParticipantRoster::inMeetingRosterForUserLivestream($livestream)
            : LivestreamParticipantRoster::inMeetingRosterForOrganizationLivestream($livestream);

        return [
            'reason' => $reason,
            'livestream' => [
                'status' => (string) $livestream->status,
                'isPublic' => (bool) $livestream->is_public,
                'startedAt' => $livestream->started_at?->toIso8601String(),
                'endedAt' => $livestream->ended_at?->toIso8601String(),
                'meetingSessionKey' => (int) ($settings['meeting_session'] ?? 0),
                'canStartMeeting' => $livestream->canStartMeeting(),
                'canSetUnityLive' => $canSetUnityLive,
                'canQueueYoutubeLive' => $canQueueYoutubeLive,
                'canGoLive' => $canGoLive,
                'streamingQueueStatus' => $streamingQueue->queueStatusForUi($latestJob, $livestream),
                'hasActiveStreamingJob' => $streamingQueue->existingActiveJobFor($kind, $livestream->id) !== null,
            ],
            'recordingConsentDeclines' => $recordingConsentDeclines,
            'participantRoster' => $participantRoster,
        ];
    }

    public static function notifyHostDashboard(
        OrganizationLivestream|UserLivestream $livestream,
        string $reason,
    ): void {
        try {
            event(new UnityMeetHostDashboardChanged(
                channelName: self::channelName($livestream),
                reason: $reason,
                payload: self::hostDashboardPayload($livestream, $reason),
            ));
        } catch (Throwable $e) {
            Log::warning('Unity Meet host dashboard broadcast skipped', [
                'reason' => $reason,
                'livestream_id' => $livestream->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notify(
        OrganizationLivestream|UserLivestream $livestream,
        string $reason,
        ?string $message = null,
    ): void {
        try {
            event(new UnityLiveViewerStatusChanged(
                channelName: self::channelName($livestream),
                reason: $reason,
                payload: self::payloadFor($livestream, $reason, $message),
            ));
        } catch (Throwable $e) {
            Log::warning('Unity Live broadcast skipped', [
                'reason' => $reason,
                'livestream_id' => $livestream->id,
                'error' => $e->getMessage(),
            ]);
        }

        self::notifyHostDashboard($livestream, $reason);
    }

    public static function notifyGiftReceived(
        OrganizationLivestream|UserLivestream $livestream,
        User $sender,
        User $recipient,
        float $amount,
        string $occasion,
        ?string $message = null,
    ): void {
        try {
            $amt = rtrim(rtrim(number_format($amount, 2), '0'), '.');
            event(new UnityMeetGiftReceived(
                channelName: self::channelName($livestream),
                payload: [
                    'recipientId' => $recipient->id,
                    'senderId' => $sender->id,
                    'senderName' => $sender->name,
                    'amount' => $amount,
                    'amountLabel' => $amt,
                    'occasion' => $occasion,
                    'message' => $message,
                    'title' => 'You received a gift',
                    'body' => "{$sender->name} sent you {$amt} BP during the meeting.",
                ],
            ));
        } catch (Throwable $e) {
            Log::warning('Unity Meet gift broadcast skipped', [
                'livestream_id' => $livestream->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyMeetingStarted(OrganizationLivestream|UserLivestream $livestream): void
    {
        self::notify(
            $livestream,
            'meeting_started',
            'The meeting has started. The stream will appear here when the host goes live on Unity Live.',
        );
    }

    public static function notifyLive(OrganizationLivestream|UserLivestream $livestream): void
    {
        self::notify($livestream, 'live', 'Going live now — the player will start automatically.');
    }

    public static function notifyUnityLiveEnded(OrganizationLivestream|UserLivestream $livestream): void
    {
        self::notify($livestream, 'unity_live_ended', 'Unity Live has ended. The host may still be in the meeting.');
    }

    public static function notifyStreamEnded(OrganizationLivestream|UserLivestream $livestream): void
    {
        self::notify($livestream, 'stream_ended', 'This stream has ended. Thanks for watching.');
    }

    private static function defaultMessage(string $reason): string
    {
        return match ($reason) {
            'meeting_started' => 'The meeting has started. The stream will appear here when the host goes live on Unity Live.',
            'live' => 'Going live now — the player will start automatically.',
            'unity_live_ended' => 'Unity Live has ended. The host may still be in the meeting.',
            'stream_ended' => 'This stream has ended. Thanks for watching.',
            default => 'Stream status updated.',
        };
    }
}

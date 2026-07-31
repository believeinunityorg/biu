<?php

namespace App\Services;

use App\Models\OrganizationLivestream;
use App\Models\UserLivestream;
use App\Services\Streaming\StreamingQueueService;
use App\Support\UnityLiveBroadcast;
use Illuminate\Support\Facades\Log;

class LivestreamHostAbandonService
{
    public function __construct(
        private readonly StreamingQueueService $streamingQueue,
    ) {}

    /**
     * Stop an active host session when the host closes the tab or navigates away
     * (best-effort YouTube complete; always settles local state).
     */
    public function abandonUserStream(
        UserLivestream $livestream,
        ?string $youtubeAccessToken,
        YouTubeService $youtubeService,
    ): bool {
        return $this->abandon(
            'user',
            $livestream,
            fn () => $this->completeYoutubeBroadcast($livestream->youtube_broadcast_id, $youtubeAccessToken, $youtubeService, $livestream->id, 'supporter'),
        );
    }

    public function abandonOrganizationStream(
        OrganizationLivestream $livestream,
        ?string $youtubeAccessToken,
        YouTubeService $youtubeService,
    ): bool {
        return $this->abandon(
            'organization',
            $livestream,
            fn () => $this->completeYoutubeBroadcast($livestream->youtube_broadcast_id, $youtubeAccessToken, $youtubeService, $livestream->id, 'organization'),
        );
    }

    /**
     * @param  callable(): void  $completeYoutube
     */
    private function abandon(
        string $kind,
        OrganizationLivestream|UserLivestream $livestream,
        callable $completeYoutube,
    ): bool {
        if (! in_array($livestream->status, ['live', 'meeting_live', 'starting'], true)) {
            return false;
        }

        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        $settings['stream_stop_requested'] = now()->toIso8601String();
        $settings['host_abandoned_at'] = now()->toIso8601String();
        $livestream->update(['settings' => $settings]);
        $livestream->refresh();

        UnityLiveBroadcast::notify(
            $livestream,
            'stream_ended',
            'The host has ended the stream. Playback may stop in a few seconds.',
        );

        $completeYoutube();

        $this->streamingQueue->finalizeAfterHostEndStream($kind, $livestream->id);

        $livestream->update([
            'status' => 'draft',
            'ended_at' => $livestream->ended_at ?? now(),
        ]);
        $livestream->refresh();
        UnityLiveBroadcast::notifyStreamEnded($livestream);

        return true;
    }

    private function completeYoutubeBroadcast(
        ?string $broadcastId,
        ?string $accessToken,
        YouTubeService $youtubeService,
        int $livestreamId,
        string $context,
    ): void {
        if ($broadcastId === null || $broadcastId === '' || $accessToken === null || $accessToken === '') {
            return;
        }

        try {
            $youtubeService->updateBroadcastStatus($accessToken, $broadcastId, 'complete');
        } catch (\Throwable $e) {
            Log::warning("Host abandon: YouTube complete failed ({$context})", [
                'livestream_id' => $livestreamId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

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
            fn (?string $broadcastId) => $this->completeYoutubeBroadcast(
                $broadcastId,
                $youtubeAccessToken,
                $youtubeService,
                $livestream->id,
                'supporter',
            ),
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
            fn (?string $broadcastId) => $this->completeYoutubeBroadcast(
                $broadcastId,
                $youtubeAccessToken,
                $youtubeService,
                $livestream->id,
                'organization',
            ),
        );
    }

    /**
     * @param  callable(?string): void  $completeYoutube
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
        $meetingSessionAtAbandon = (int) ($settings['meeting_session'] ?? 0);
        $settings['stream_stop_requested'] = now()->toIso8601String();
        $settings['host_abandoned_at'] = now()->toIso8601String();
        $oldBroadcastId = $livestream->youtube_broadcast_id;

        // Draft immediately — before ECS StopTask / YouTube. A slow finalize used to
        // finish after the host clicked Start Meeting on the next page load and wipe it.
        $livestream->update([
            'status' => 'draft',
            'ended_at' => $livestream->ended_at ?? now(),
            'settings' => $settings,
        ]);
        $livestream->refresh();
        UnityLiveBroadcast::notifyStreamEnded($livestream);

        $this->streamingQueue->finalizeAfterHostEndStream($kind, $livestream->id);

        // If Start Meeting already opened a newer session, do not touch it.
        $livestream->refresh();
        $currentSettings = is_array($livestream->settings) ? $livestream->settings : [];
        $currentSession = (int) ($currentSettings['meeting_session'] ?? 0);
        if ($currentSession > $meetingSessionAtAbandon) {
            return true;
        }

        $completeYoutube($oldBroadcastId);

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

        dispatch(function () use ($broadcastId, $accessToken, $youtubeService, $livestreamId, $context): void {
            try {
                $youtubeService->updateBroadcastStatus($accessToken, $broadcastId, 'complete');
            } catch (\Throwable $e) {
                Log::warning("Host abandon: YouTube complete failed ({$context})", [
                    'livestream_id' => $livestreamId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }
}

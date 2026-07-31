<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class YouTubeService
{
    public const OAUTH_SCOPE_READONLY = 'https://www.googleapis.com/auth/youtube.readonly';

    public const OAUTH_SCOPE_FORCE_SSL = 'https://www.googleapis.com/auth/youtube.force-ssl';

    public const OAUTH_SCOPE_UPLOAD = 'https://www.googleapis.com/auth/youtube.upload';

    /** Full manage scope also allows video uploads. */
    public const OAUTH_SCOPE_YOUTUBE = 'https://www.googleapis.com/auth/youtube';

    private string $apiKey;

    private string $baseUrl = 'https://www.googleapis.com/youtube/v3';

    /**
     * Scopes requested when connecting YouTube (readonly, live/manage, upload).
     *
     * @return list<string>
     */
    public static function oauthConnectScopes(): array
    {
        return self::oauthConnectScopesIncludingLiveStream();
    }

    /**
     * Full YouTube OAuth scopes including force-ssl for live streams and channel management.
     *
     * @return list<string>
     */
    public static function oauthConnectScopesIncludingLiveStream(): array
    {
        return [
            self::OAUTH_SCOPE_READONLY,
            self::OAUTH_SCOPE_FORCE_SSL,
            self::OAUTH_SCOPE_UPLOAD,
        ];
    }

    /** @return list<string> */
    public static function requiredOAuthScopes(): array
    {
        return self::oauthConnectScopes();
    }

    public function __construct()
    {
        $this->apiKey = config('services.youtube.api_key', '');
    }

    /**
     * HTTP client with SSL verification disabled (avoids cURL 60 on Windows).
     */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withOptions(['verify' => false]);
    }

    /**
     * OAuth connect: resolve the signed-in Google account's YouTube channel (channels.list?mine=true).
     *
     * @return array{channel: array<string, mixed>|null, error_body: string|null}
     */
    public function fetchOAuthUserChannel(string $accessToken): array
    {
        if ($accessToken === '') {
            return ['channel' => null, 'error_body' => null];
        }

        $attempts = [
            ['use_key' => false],
        ];
        if ($this->apiKey !== '') {
            $attempts[] = ['use_key' => true];
        }

        $lastBody = null;
        $lastStatus = null;

        foreach ($attempts as $attempt) {
            $params = [
                'part' => 'id,snippet',
                'mine' => 'true',
                'maxResults' => 1,
            ];
            if ($attempt['use_key']) {
                $params['key'] = $this->apiKey;
            }

            $response = $this->http()
                ->withToken($accessToken)
                ->get($this->baseUrl . '/channels', $params);

            if ($response->successful()) {
                $items = $response->json('items') ?? [];

                return [
                    'channel' => isset($items[0]) ? $items[0] : null,
                    'error_body' => null,
                ];
            }

            $lastBody = $response->body();
            $lastStatus = $response->status();

            // Browser-restricted API keys often 403 from the server — do not keep retrying with key.
            if ($attempt['use_key'] && $response->status() === 403) {
                break;
            }
        }

        Log::warning('YouTube OAuth channels.list failed', [
            'status' => $lastStatus,
            'body' => $lastBody,
            'api_key_configured' => $this->apiKey !== '',
        ]);

        return ['channel' => null, 'error_body' => $lastBody];
    }

    /**
     * User-facing message from a YouTube Data API error payload.
     */
    public function userMessageFromYoutubeApiError(?string $responseBody): string
    {
        if ($responseBody === null || $responseBody === '') {
            return 'Could not load your YouTube channel. Please try again.';
        }

        $json = json_decode($responseBody, true);
        if (! is_array($json)) {
            return 'Could not load your YouTube channel. Please try again.';
        }

        $message = (string) ($json['error']['message'] ?? '');
        $reason = (string) ($json['error']['errors'][0]['reason'] ?? '');
        $combined = strtolower($message . ' ' . $reason);

        if (str_contains($combined, 'accessnotconfigured') || str_contains($combined, 'has not been used') || str_contains($combined, 'disabled')) {
            return 'YouTube Data API is not enabled for this app. Enable YouTube Data API v3 in Google Cloud Console (same project as your OAuth client), then try again.';
        }

        if (str_contains($combined, 'insufficient') || str_contains($combined, 'forbidden') || $reason === 'insufficientPermissions') {
            return 'YouTube did not grant upload permission. Open Unity Meet Settings, disconnect YouTube, connect again, and allow all requested access (including upload videos).';
        }

        if (str_contains($combined, 'quota')) {
            return 'YouTube API quota exceeded. Try again later or contact support.';
        }

        if ($message !== '') {
            return 'Could not load your YouTube channel: ' . Str::limit($message, 120);
        }

        return 'Could not load your YouTube channel. Please try again.';
    }

    public function scopeStringIncludesUpload(?string $scopeString): bool
    {
        if ($scopeString === null || trim($scopeString) === '') {
            return false;
        }

        foreach (preg_split('/\s+/', trim($scopeString)) as $scope) {
            if ($scope === self::OAUTH_SCOPE_UPLOAD || $scope === self::OAUTH_SCOPE_YOUTUBE) {
                return true;
            }
        }

        return false;
    }

    public function accessTokenIncludesUploadScope(string $accessToken): bool
    {
        if ($accessToken === '') {
            return false;
        }

        $response = $this->http()->get('https://oauth2.googleapis.com/tokeninfo', [
            'access_token' => $accessToken,
        ]);

        if (! $response->successful()) {
            Log::warning('YouTube tokeninfo failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return $this->scopeStringIncludesUpload((string) $response->json('scope'));
    }

    public function userCanUploadVideos(User $user): bool
    {
        if (empty($user->youtube_refresh_token)) {
            return false;
        }

        $accessToken = $this->getValidAccessTokenForUser($user);
        if ($accessToken === null || $accessToken === '') {
            return false;
        }

        return $this->accessTokenIncludesUploadScope($accessToken);
    }

    public function organizationCanUploadVideos(Organization $organization): bool
    {
        if (empty($organization->youtube_refresh_token)) {
            return false;
        }

        $accessToken = $this->getValidAccessToken($organization);
        if ($accessToken === null || $accessToken === '') {
            return false;
        }

        return $this->accessTokenIncludesUploadScope($accessToken);
    }

    /**
     * Get channel ID from a YouTube channel URL.
     * Supports: /channel/UC..., /@handle, /c/custom
     */
    public function getChannelIdFromUrl(string $url): ?string
    {
        $url = trim($url);
        if (empty($this->apiKey)) {
            return null;
        }

        $parsed = parse_url(rtrim(preg_replace('#^https?://#', 'https://', $url), '/'));
        $host = $parsed['host'] ?? '';
        if (! str_contains($host, 'youtube.com') && ! str_contains($host, 'youtu.be')) {
            return null;
        }

        $path = $parsed['path'] ?? '';

        // https://www.youtube.com/channel/UCxxxxxxxxxx
        if (preg_match('#^/channel/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            return $m[1];
        }

        // https://www.youtube.com/@handle
        if (preg_match('#^/@([a-zA-Z0-9_.-]+)$#', $path, $m)) {
            return $this->resolveChannelIdByHandle($m[1]);
        }

        // https://www.youtube.com/c/CustomName or /user/username (legacy)
        if (preg_match('#^/(c|user)/([a-zA-Z0-9_.-]+)$#', $path, $m)) {
            return $this->resolveChannelIdBySearch($url);
        }

        return null;
    }

    /**
     * Resolve channel ID for @handle using channels.list forHandle.
     */
    private function resolveChannelIdByHandle(string $handle): ?string
    {
        $response = $this->http()->get($this->baseUrl . '/channels', [
            'part' => 'id',
            'forHandle' => $handle,
            'key' => $this->apiKey,
        ]);

        if (! $response->successful()) {
            Log::warning('YouTube API channels.forHandle failed', ['handle' => $handle, 'body' => $response->body()]);

            return null;
        }

        $data = $response->json();
        $items = $data['items'] ?? [];

        return isset($items[0]['id']) ? $items[0]['id'] : null;
    }

    /**
     * Fallback: search for channel by URL (for /c/ or /user/ URLs).
     */
    private function resolveChannelIdBySearch(string $url): ?string
    {
        $response = $this->http()->get($this->baseUrl . '/search', [
            'part' => 'snippet',
            'type' => 'channel',
            'q' => $url,
            'key' => $this->apiKey,
            'maxResults' => 1,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $items = $data['items'] ?? [];
        $channelId = $items[0]['snippet']['channelId'] ?? null;

        return $channelId;
    }

    /**
     * Drop cached channel payloads so Unity Videos reflects connect/disconnect immediately.
     */
    public function forgetCachesForChannelUrl(?string $channelUrl): void
    {
        if ($channelUrl === null || trim($channelUrl) === '') {
            return;
        }

        $hash = md5($channelUrl);
        Cache::forget('youtube_channel_details_'.$hash);
        foreach ([10, 15, 24, 30, 50] as $maxResults) {
            Cache::forget('youtube_channel_videos_v2_'.$hash.'_'.$maxResults);
            Cache::forget('youtube_channel_live_'.$hash.'_'.$maxResults);
        }
    }

    /**
     * Fetch recent uploads from a channel. Returns array of video items for frontend.
     * Cached briefly per channel; cleared on YouTube connect/disconnect.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getChannelVideos(string $channelUrl, int $maxResults = 24, bool $useCache = true): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $channelId = $this->getChannelIdFromUrl($channelUrl);
        if (! $channelId) {
            return [];
        }

        $uploadsPlaylistId = $this->getUploadsPlaylistId($channelId);
        if (! $uploadsPlaylistId) {
            return [];
        }

        // Bump cache key when video row shape changes (Shorts classification fields).
        $cacheKey = 'youtube_channel_videos_v2_' . md5($channelUrl) . '_' . $maxResults;

        if (! $useCache) {
            Cache::forget($cacheKey);

            return $this->fetchPlaylistVideos($uploadsPlaylistId, $maxResults);
        }

        return Cache::remember($cacheKey, 300, fn () => $this->fetchPlaylistVideos($uploadsPlaylistId, $maxResults));
    }

    /**
     * Fetch currently live streams for a channel (search.list eventType=live).
     * Cached briefly so live status stays reasonably fresh; cleared on disconnect.
     *
     * @return array<int, array{id: string, title: string, thumbnail_url: string, published_at: string, views: int, views_formatted: string, duration: string, watch_url: string, likes: int, likes_formatted: string, comment_count: int, comment_count_formatted: string}>
     */
    public function getChannelLiveStreams(string $channelUrl, int $maxResults = 10, bool $useCache = true): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $channelId = $this->getChannelIdFromUrl($channelUrl);
        if (! $channelId) {
            return [];
        }

        $cacheKey = 'youtube_channel_live_' . md5($channelUrl) . '_' . $maxResults;

        $fetcher = function () use ($channelId, $maxResults) {
            $response = $this->http()->get($this->baseUrl . '/search', [
                'part' => 'snippet',
                'channelId' => $channelId,
                'eventType' => 'live',
                'type' => 'video',
                'maxResults' => min(max(1, $maxResults), 50),
                'key' => $this->apiKey,
            ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $items = $data['items'] ?? [];
            $videoIds = [];
            foreach ($items as $item) {
                $videoId = $item['id']['videoId'] ?? null;
                if ($videoId) {
                    $videoIds[] = $videoId;
                }
            }
            if (empty($videoIds)) {
                return [];
            }

            $videosDetail = $this->fetchVideosDetails($videoIds);
            $out = [];
            foreach ($items as $item) {
                $videoId = $item['id']['videoId'] ?? null;
                if (! $videoId) {
                    continue;
                }
                $detail = $videosDetail[$videoId] ?? null;
                if (empty($detail)) {
                    continue;
                }
                $snippet = $item['snippet'] ?? [];
                $thumb = $this->bestThumbnail($snippet['thumbnails'] ?? []);
                if ($thumb === '') {
                    $thumb = 'https://img.youtube.com/vi/' . $videoId . '/mqdefault.jpg';
                }
                $likeCount = (int) ($detail['likeCount'] ?? 0);
                $commentCount = (int) ($detail['commentCount'] ?? 0);
                $out[] = [
                    'id' => $videoId,
                    'title' => $snippet['title'] ?? 'Video',
                    'thumbnail_url' => $thumb,
                    'published_at' => $snippet['publishedAt'] ?? '',
                    'views' => (int) ($detail['viewCount'] ?? 0),
                    'views_formatted' => number_format((int) ($detail['viewCount'] ?? 0)),
                    'duration' => 'LIVE',
                    'duration_seconds' => 0,
                    'description' => '',
                    'thumbnail_width' => 0,
                    'thumbnail_height' => 0,
                    'is_youtube_short' => false,
                    'watch_url' => 'https://www.youtube.com/watch?v=' . $videoId,
                    'likes' => $likeCount,
                    'likes_formatted' => $detail['likes_formatted'] ?? number_format($likeCount),
                    'comment_count' => $commentCount,
                    'comment_count_formatted' => $detail['comment_count_formatted'] ?? number_format($commentCount),
                ];
            }

            return $out;
        };

        if (! $useCache) {
            Cache::forget($cacheKey);

            return $fetcher();
        }

        return Cache::remember($cacheKey, 180, $fetcher);
    }

    private function getUploadsPlaylistId(string $channelId): ?string
    {
        $response = $this->http()->get($this->baseUrl . '/channels', [
            'part' => 'contentDetails',
            'id' => $channelId,
            'key' => $this->apiKey,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $items = $data['items'] ?? [];

        return $items[0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
    }

    /**
     * Get channel details for banner and stats (video count, view count).
     * Cached 1 hour per channel URL.
     *
     * @return array{banner_url: string|null, video_count: int, view_count: int}|null
     */
    public function getChannelDetails(string $channelUrl): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $channelId = $this->getChannelIdFromUrl($channelUrl);
        if (! $channelId) {
            return null;
        }

        $cacheKey = 'youtube_channel_details_' . md5($channelUrl);

        return Cache::remember($cacheKey, 3600, function () use ($channelId) {
            $response = $this->http()->get($this->baseUrl . '/channels', [
                'part' => 'snippet,brandingSettings,statistics',
                'id' => $channelId,
                'key' => $this->apiKey,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $items = $data['items'] ?? [];
            $item = $items[0] ?? null;
            if (! $item) {
                return null;
            }

            $snippet = $item['snippet'] ?? [];
            $thumbnails = $snippet['thumbnails'] ?? [];
            $thumb = $thumbnails['medium'] ?? $thumbnails['default'] ?? null;
            $avatarUrl = $thumb && isset($thumb['url']) ? (string) $thumb['url'] : null;

            $branding = $item['brandingSettings'] ?? [];
            $image = $branding['image'] ?? [];
            $bannerUrl = $image['bannerExternalUrl'] ?? null;
            $stats = $item['statistics'] ?? [];
            $videoCount = (int) ($stats['videoCount'] ?? 0);
            $viewCount = (int) ($stats['viewCount'] ?? 0);
            $subscriberCount = (int) ($stats['subscriberCount'] ?? 0);

            return [
                'name' => $snippet['title'] ?? null,
                'avatar_url' => $avatarUrl,
                'banner_url' => $bannerUrl ? (string) $bannerUrl : null,
                'video_count' => $videoCount,
                'view_count' => $viewCount,
                'subscriber_count' => $subscriberCount,
                'subscriber_count_formatted' => $subscriberCount >= 1000000
                    ? round($subscriberCount / 1000000, 1) . 'M'
                    : ($subscriberCount >= 1000 ? round($subscriberCount / 1000, 1) . 'K' : (string) $subscriberCount),
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPlaylistVideos(string $playlistId, int $maxResults): array
    {
        $response = $this->http()->get($this->baseUrl . '/playlistItems', [
            'part' => 'snippet,contentDetails',
            'playlistId' => $playlistId,
            'key' => $this->apiKey,
            'maxResults' => min($maxResults, 50),
        ]);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();
        $items = $data['items'] ?? [];
        $videoIds = array_filter(array_map(fn ($i) => $items[$i]['contentDetails']['videoId'] ?? null, array_keys($items)));

        if (empty($videoIds)) {
            return [];
        }

        $videosDetail = $this->fetchVideosDetails($videoIds);
        $out = [];
        foreach ($items as $item) {
            $videoId = $item['contentDetails']['videoId'] ?? null;
            if (! $videoId) {
                continue;
            }
            $detail = $videosDetail[$videoId] ?? null;
            if (empty($detail)) {
                continue;
            }
            $snippet = $item['snippet'] ?? [];
            $thumbMeta = $this->bestThumbnailMeta($snippet['thumbnails'] ?? []);
            $thumb = $thumbMeta['url'];
            if ($thumb === '') {
                $thumb = 'https://img.youtube.com/vi/' . $videoId . '/mqdefault.jpg';
            }
            $likeCount = (int) ($detail['likeCount'] ?? 0);
            $commentCount = (int) ($detail['commentCount'] ?? 0);
            $isoDuration = $detail['duration'] ?? 'PT0S';
            $durationSeconds = $this->iso8601DurationToSeconds($isoDuration);
            $description = (string) ($snippet['description'] ?? '');
            $row = [
                'id' => $videoId,
                'title' => $snippet['title'] ?? 'Video',
                'description' => $description,
                'thumbnail_url' => $thumb,
                'thumbnail_width' => $thumbMeta['width'],
                'thumbnail_height' => $thumbMeta['height'],
                'published_at' => $snippet['publishedAt'] ?? '',
                'views' => (int) ($detail['viewCount'] ?? 0),
                'views_formatted' => number_format((int) ($detail['viewCount'] ?? 0)),
                'duration' => $this->formatIsoDuration($isoDuration),
                'duration_seconds' => $durationSeconds,
                'watch_url' => 'https://www.youtube.com/watch?v=' . $videoId,
                'likes' => $likeCount,
                'likes_formatted' => $detail['likes_formatted'] ?? number_format($likeCount),
                'comment_count' => $commentCount,
                'comment_count_formatted' => $detail['comment_count_formatted'] ?? number_format($commentCount),
            ];
            $row['is_youtube_short'] = $this->isYoutubeShort($row);
            if ($this->shouldOmitFromVideoHub($row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Drop placeholders and API gaps: no empty or 0:00 duration in Videos or Shorts. LIVE streams are kept.
     *
     * @param  array<string, mixed>  $v
     */
    public function shouldOmitFromVideoHub(array $v): bool
    {
        $d = $v['duration'] ?? null;
        if ($d === 'LIVE') {
            return false;
        }
        if (! is_string($d) || $d === '' || trim($d) === '0:00') {
            return true;
        }
        $sec = (int) ($v['duration_seconds'] ?? 0);
        if ($sec < 1) {
            $sec = $this->formattedDurationToSeconds($d);
        }

        return $sec < 1;
    }

    /**
     * True when the upload should appear in the Shorts hub (not merely ≤60s horizontal clips).
     * Uses duration (1–60s) plus Shorts hashtag / Shorts URL in metadata or portrait thumbnail from the API.
     *
     * @param  array<string, mixed>  $v
     */
    public function isYoutubeShort(array $v): bool
    {
        if (($v['duration'] ?? '') === 'LIVE') {
            return false;
        }

        $seconds = (int) ($v['duration_seconds'] ?? 0);
        if ($seconds < 1 || $seconds > 60) {
            $seconds = $this->formattedDurationToSeconds(is_string($v['duration'] ?? null) ? (string) $v['duration'] : '');
        }
        if ($seconds < 1 || $seconds > 60) {
            return false;
        }

        $title = strtolower((string) ($v['title'] ?? ''));
        $desc = strtolower((string) ($v['description'] ?? ''));
        if (str_contains($title, '#shorts') || str_contains($desc, '#shorts')) {
            return true;
        }
        if (str_contains($desc, 'youtube.com/shorts/')) {
            return true;
        }

        $tw = (int) ($v['thumbnail_width'] ?? 0);
        $th = (int) ($v['thumbnail_height'] ?? 0);
        if ($tw > 0 && $th > 0 && $th > $tw) {
            return true;
        }

        return false;
    }

    /**
     * Parse M:SS or H:M:SS from {@see formatIsoDuration()} output.
     */
    private function formattedDurationToSeconds(string $formatted): int
    {
        $formatted = trim($formatted);
        if ($formatted === '' || $formatted === 'LIVE' || $formatted === '0:00') {
            return 0;
        }
        $parts = array_map('intval', explode(':', $formatted));
        if (count($parts) === 2) {
            return $parts[0] * 60 + $parts[1];
        }
        if (count($parts) === 3) {
            return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        }

        return 0;
    }

    /**
     * @return array{url: string, width: int, height: int}
     */
    private function bestThumbnailMeta(array $thumbnails): array
    {
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $key) {
            if (! empty($thumbnails[$key]['url'])) {
                return [
                    'url' => (string) $thumbnails[$key]['url'],
                    'width' => (int) ($thumbnails[$key]['width'] ?? 0),
                    'height' => (int) ($thumbnails[$key]['height'] ?? 0),
                ];
            }
        }

        return ['url' => '', 'width' => 0, 'height' => 0];
    }

    /**
     * Get single video details for watch page (title, description, channel, views, etc.).
     *
     * @return array<string, mixed>|null
     */
    public function getVideoDetails(string $videoId): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $response = $this->http()->get($this->baseUrl . '/videos', [
            'part' => 'snippet,statistics,contentDetails',
            'id' => $videoId,
            'key' => $this->apiKey,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $items = $data['items'] ?? [];
        $item = $items[0] ?? null;
        if (! $item) {
            return null;
        }

        $snippet = $item['snippet'] ?? [];
        $stats = $item['statistics'] ?? [];
        $publishedAt = $snippet['publishedAt'] ?? '';
        $thumb = $this->bestThumbnail($snippet['thumbnails'] ?? []);
        if ($thumb === '') {
            $thumb = 'https://img.youtube.com/vi/' . $videoId . '/mqdefault.jpg';
        }

        $likeCount = (int) ($stats['likeCount'] ?? 0);
        $commentCount = (int) ($stats['commentCount'] ?? 0);

        $durationIso = $item['contentDetails']['duration'] ?? 'PT0S';

        return [
            'id' => $videoId,
            'title' => $snippet['title'] ?? 'Video',
            'description' => $snippet['description'] ?? '',
            'channel_title' => $snippet['channelTitle'] ?? '',
            'channel_id' => $snippet['channelId'] ?? '',
            'thumbnail_url' => $thumb,
            'published_at' => $publishedAt,
            'time_ago' => $publishedAt ? \Carbon\Carbon::parse($publishedAt)->diffForHumans() : '',
            'views' => (int) ($stats['viewCount'] ?? 0),
            'views_formatted' => number_format((int) ($stats['viewCount'] ?? 0)),
            'likes' => $likeCount,
            'likes_formatted' => number_format($likeCount),
            'comment_count' => $commentCount,
            'comment_count_formatted' => number_format($commentCount),
            'duration' => $this->formatIsoDuration($durationIso),
            'duration_seconds' => $this->iso8601DurationToSeconds($durationIso),
        ];
    }

    /**
     * Parse ISO 8601 duration (e.g. PT1M30S) to total seconds for Shorts / uploads.
     */
    private function iso8601DurationToSeconds(string $iso): int
    {
        try {
            $interval = new \DateInterval($iso);

            return ($interval->days * 86400)
                + ($interval->h * 3600)
                + ($interval->i * 60)
                + $interval->s;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get top-level comments for a video. Cached 15 minutes.
     * Returns empty array if comments disabled, API error, or no key.
     *
     * @return array<int, array{authorDisplayName: string, authorProfileImageUrl: string, text: string, likeCount: int, publishedAt: string, time_ago: string}>
     */
    public function getVideoComments(string $videoId, int $maxResults = 30): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $cacheKey = 'youtube_video_comments_' . $videoId . '_' . $maxResults;

        return Cache::remember($cacheKey, 900, function () use ($videoId, $maxResults) {
            $response = $this->http()->get($this->baseUrl . '/commentThreads', [
                'part' => 'snippet',
                'videoId' => $videoId,
                'maxResults' => min(max(1, $maxResults), 100),
                'order' => 'relevance',
                'textFormat' => 'plainText',
                'key' => $this->apiKey,
            ]);

            if (! $response->successful()) {
                if ($response->status() === 403) {
                    Log::debug('YouTube comments disabled or unavailable for video: ' . $videoId);
                }

                return [];
            }

            $data = $response->json();
            $items = $data['items'] ?? [];
            $out = [];
            foreach ($items as $item) {
                $top = $item['snippet']['topLevelComment']['snippet'] ?? null;
                if (! $top) {
                    continue;
                }
                $publishedAt = $top['publishedAt'] ?? '';
                $out[] = [
                    'authorDisplayName' => $top['authorDisplayName'] ?? 'Unknown',
                    'authorProfileImageUrl' => $top['authorProfileImageUrl'] ?? '',
                    'text' => $top['textDisplay'] ?? $top['textOriginal'] ?? '',
                    'likeCount' => (int) ($top['likeCount'] ?? 0),
                    'publishedAt' => $publishedAt,
                    'time_ago' => $publishedAt ? \Carbon\Carbon::parse($publishedAt)->diffForHumans() : '',
                ];
            }

            return $out;
        });
    }

    /**
     * @param  array<string>  $videoIds
     * @return array<string, array{viewCount: string, duration: string}>
     */
    private function fetchVideosDetails(array $videoIds): array
    {
        $response = $this->http()->get($this->baseUrl . '/videos', [
            'part' => 'statistics,contentDetails',
            'id' => implode(',', $videoIds),
            'key' => $this->apiKey,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();
        $items = $data['items'] ?? [];
        $result = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if ($id) {
                $stats = $item['statistics'] ?? [];
                $likeCount = (int) ($stats['likeCount'] ?? 0);
                $commentCount = (int) ($stats['commentCount'] ?? 0);
                $result[$id] = [
                    'viewCount' => $item['statistics']['viewCount'] ?? 0,
                    'duration' => $item['contentDetails']['duration'] ?? 'PT0S',
                    'likeCount' => $likeCount,
                    'likes_formatted' => number_format($likeCount),
                    'commentCount' => $commentCount,
                    'comment_count_formatted' => number_format($commentCount),
                ];
            }
        }

        return $result;
    }

    private function bestThumbnail(array $thumbnails): string
    {
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $key) {
            if (! empty($thumbnails[$key]['url'])) {
                return $thumbnails[$key]['url'];
            }
        }

        return '';
    }

    private function formatIsoDuration(string $iso): string
    {
        try {
            $interval = new \DateInterval($iso);
            if ($interval->h > 0) {
                return sprintf('%d:%02d:%02d', $interval->h, $interval->i, $interval->s);
            }

            return sprintf('%d:%02d', $interval->i, $interval->s);
        } catch (\Exception $e) {
            return '0:00';
        }
    }

    /**
     * Return a valid YouTube OAuth access token for the organization.
     * Uses existing token if not expired (with 5 min buffer); otherwise refreshes using refresh_token and saves.
     *
     * @return string|null Access token or null if missing/expired and refresh failed
     */
    public function getValidAccessToken(Organization $organization): ?string
    {
        $expiresAt = $organization->youtube_token_expires_at;
        $bufferMinutes = 5;
        if ($expiresAt && $expiresAt->copy()->subMinutes($bufferMinutes)->isFuture()) {
            try {
                return Crypt::decryptString($organization->youtube_access_token);
            } catch (\Exception $e) {
                Log::warning('YouTube access token decrypt failed, will try refresh', ['org_id' => $organization->id]);
            }
        }

        return $this->refreshYoutubeToken($organization);
    }

    /**
     * Valid access token for the connected supporter (user) OAuth.
     */
    public function getValidAccessTokenForUser(User $user): ?string
    {
        $expiresAt = $user->youtube_token_expires_at;
        $bufferMinutes = 5;
        if ($expiresAt && $expiresAt->copy()->subMinutes($bufferMinutes)->isFuture()) {
            try {
                return Crypt::decryptString($user->youtube_access_token);
            } catch (\Exception $e) {
                Log::warning('YouTube access token decrypt failed for user, will try refresh', ['user_id' => $user->id]);
            }
        }

        return $this->refreshYoutubeTokenForUser($user);
    }

    /**
     * Refresh YouTube OAuth tokens for a user (supporter integrations).
     *
     * @return string|null New access token or null on failure
     */
    public function refreshYoutubeTokenForUser(User $user): ?string
    {
        $refreshToken = $user->youtube_refresh_token;
        if (empty($refreshToken)) {
            Log::warning('YouTube refresh token missing', ['user_id' => $user->id]);

            return null;
        }

        try {
            $decryptedRefresh = Crypt::decryptString($refreshToken);
        } catch (\Exception $e) {
            Log::warning('YouTube refresh token decrypt failed', ['user_id' => $user->id]);

            return null;
        }

        $clientId = config('services.youtube.client_id');
        $clientSecret = config('services.youtube.client_secret');
        if (empty($clientId) || empty($clientSecret)) {
            Log::warning('YouTube OAuth client not configured');

            return null;
        }

        $response = $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $decryptedRefresh,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::error('YouTube token refresh failed', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        if (! $accessToken) {
            return null;
        }

        $user->update([
            'youtube_access_token' => Crypt::encryptString($accessToken),
            'youtube_token_expires_at' => now()->addSeconds($expiresIn),
        ]);

        return $accessToken;
    }

    /**
     * Refresh YouTube OAuth access token using the organization's refresh_token and persist to DB.
     *
     * @return string|null New access token or null on failure
     */
    public function refreshYoutubeToken(Organization $organization): ?string
    {
        $refreshToken = $organization->youtube_refresh_token;
        if (empty($refreshToken)) {
            Log::warning('YouTube refresh token missing', ['organization_id' => $organization->id]);

            return null;
        }

        try {
            $decryptedRefresh = Crypt::decryptString($refreshToken);
        } catch (\Exception $e) {
            Log::warning('YouTube refresh token decrypt failed', ['organization_id' => $organization->id]);

            return null;
        }

        $clientId = config('services.youtube.client_id');
        $clientSecret = config('services.youtube.client_secret');
        if (empty($clientId) || empty($clientSecret)) {
            Log::warning('YouTube OAuth client not configured');

            return null;
        }

        $response = $this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $decryptedRefresh,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            Log::error('YouTube token refresh failed', [
                'organization_id' => $organization->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        if (! $accessToken) {
            return null;
        }

        $organization->update([
            'youtube_access_token' => Crypt::encryptString($accessToken),
            'youtube_token_expires_at' => now()->addSeconds($expiresIn),
        ]);

        return $accessToken;
    }

    /**
     * Create a YouTube live broadcast using OAuth token.
     * Requires: youtube.force-ssl scope
     *
     * @param string $accessToken OAuth access token
     * @param string $title Broadcast title
     * @param string|null $description Broadcast description
     * @param \DateTime|null $scheduledStartTime When to start (null = start immediately)
     * @return array{broadcast_id: string, stream_key: string, rtmp_url: string}|null
     */
    public function createLiveBroadcast(
        string $accessToken,
        string $title,
        ?string $description = null,
        ?\DateTime $scheduledStartTime = null
    ): ?array {
        try {
            $existingBroadcast = $this->findBroadcastStreamKeyByTitle($accessToken, $title);
            if ($existingBroadcast !== null) {
                Log::info('YouTube broadcast create skipped; existing broadcast reused by title', [
                    'title' => $title,
                    'broadcast_id' => $existingBroadcast['broadcast_id'],
                ]);

                return $existingBroadcast;
            }

            // YouTube API requires scheduledStartTime for every broadcast. Use "now" when starting immediately.
            $startTime = $scheduledStartTime ?? new \DateTime('now', new \DateTimeZone('UTC'));
            $scheduledStartTimeIso = $startTime->format('Y-m-d\TH:i:s\Z');

            // Step 1: Create the broadcast
            $broadcastData = [
                'snippet' => [
                    'title' => $title,
                    'description' => $description ?? '',
                    'scheduledStartTime' => $scheduledStartTimeIso,
                ],
                'status' => [
                    'privacyStatus' => 'public', // or 'unlisted', 'private'
                    'selfDeclaredMadeForKids' => false,
                ],
                'contentDetails' => [
                    'enableAutoStart' => $scheduledStartTime === null,
                    'enableAutoStop' => false,
                ],
            ];

            $broadcastResponse = $this->http()
                ->withToken($accessToken)
                ->asJson()
                ->post($this->baseUrl . '/liveBroadcasts?part=snippet,status,contentDetails&key=' . $this->apiKey, $broadcastData);

            if (!$broadcastResponse->successful()) {
                Log::error('YouTube broadcast creation failed', [
                    'status' => $broadcastResponse->status(),
                    'body' => $broadcastResponse->body(),
                ]);
                return null;
            }

            $broadcast = $broadcastResponse->json();
            $broadcastId = $broadcast['id'] ?? null;

            if (!$broadcastId) {
                Log::error('YouTube broadcast ID missing in response', ['response' => $broadcast]);
                return null;
            }

            // Step 2: Reuse the YouTube live stream/key with this name if it already exists.
            // This keeps one stream key per livestream title instead of creating duplicates.
            $streamTitle = $title . ' Stream';
            $existingStream = $this->findLiveStreamByTitle($accessToken, $streamTitle);
            if ($existingStream !== null) {
                Log::info('YouTube live stream reused by title', [
                    'title' => $streamTitle,
                    'stream_id' => $existingStream['stream_id'],
                ]);

                $streamId = $existingStream['stream_id'];
                $streamKey = $existingStream['stream_key'];
                $rtmpUrl = $existingStream['rtmp_url'];
            } else {
                // API requires cdn.resolution and cdn.frameRate; cdn.format is deprecated.
                $streamData = [
                    'snippet' => [
                        'title' => $streamTitle,
                    ],
                    'cdn' => [
                        'ingestionType' => 'rtmp',
                        'resolution' => '1080p',
                        'frameRate' => '30fps',
                    ],
                ];

                $streamResponse = $this->http()
                    ->withToken($accessToken)
                    ->asJson()
                    ->post($this->baseUrl . '/liveStreams?part=snippet,cdn&key=' . $this->apiKey, $streamData);

                if (!$streamResponse->successful()) {
                    Log::error('YouTube stream creation failed', [
                        'status' => $streamResponse->status(),
                        'body' => $streamResponse->body(),
                    ]);
                    // Try to delete the broadcast we created.
                    $this->http()
                        ->withToken($accessToken)
                        ->delete($this->baseUrl . '/liveBroadcasts', [
                            'id' => $broadcastId,
                            'key' => $this->apiKey,
                        ]);
                    return null;
                }

                $stream = $streamResponse->json();
                $streamId = $stream['id'] ?? null;
                $streamKey = $stream['cdn']['ingestionInfo']['streamName'] ?? null;
                $rtmpUrl = $stream['cdn']['ingestionInfo']['ingestionAddress'] ?? null;
            }

            if (!$streamId || !$streamKey || !$rtmpUrl) {
                Log::error('YouTube stream data incomplete', [
                    'stream_id' => $streamId,
                    'has_stream_key' => ! empty($streamKey),
                    'rtmp_url' => $rtmpUrl,
                ]);
                return null;
            }

            // Step 3: Bind the stream to the broadcast (id, streamId, part, key are query params; no body)
            $bindUrl = $this->baseUrl . '/liveBroadcasts/bind?' . http_build_query([
                'id' => $broadcastId,
                'streamId' => $streamId,
                'part' => 'id,snippet,contentDetails,status',
                'key' => $this->apiKey,
            ]);
            $bindResponse = $this->http()
                ->withToken($accessToken)
                ->post($bindUrl);

            if (!$bindResponse->successful()) {
                Log::error('YouTube broadcast bind failed', [
                    'status' => $bindResponse->status(),
                    'body' => $bindResponse->body(),
                ]);
                return null;
            }

            return [
                'broadcast_id' => $broadcastId,
                'stream_key' => $streamKey,
                'rtmp_url' => $rtmpUrl,
                'stream_id' => $streamId,
            ];
        } catch (\Exception $e) {
            Log::error('YouTube broadcast creation exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Find an existing non-completed broadcast on the channel by exact title and return its stream key.
     * Used so a user creating a meeting whose name matches an existing YouTube live reuses that broadcast
     * (and its stream key) instead of accidentally spawning duplicates.
     *
     * @param string $accessToken OAuth access token
     * @param string $title Broadcast title to match (case/whitespace-insensitive)
     * @return array{broadcast_id: string, stream_key: string, rtmp_url: string}|null
     */
    public function findBroadcastStreamKeyByTitle(string $accessToken, string $title): ?array
    {
        try {
            $response = $this->http()
                ->withToken($accessToken)
                ->get($this->baseUrl . '/liveBroadcasts', [
                    'mine' => 'true',
                    'part' => 'snippet,contentDetails,status',
                    'maxResults' => 50,
                    'key' => $this->apiKey,
                ]);

            if (! $response->successful()) {
                Log::error('YouTube broadcast title lookup failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'title' => $title,
                ]);
                return null;
            }

            $targetTitle = $this->normalizeBroadcastTitle($title);
            foreach (($response->json('items') ?? []) as $item) {
                $broadcastTitle = $this->normalizeBroadcastTitle((string) ($item['snippet']['title'] ?? ''));
                if ($broadcastTitle !== $targetTitle) {
                    continue;
                }

                $lifeCycleStatus = (string) ($item['status']['lifeCycleStatus'] ?? '');
                if (in_array($lifeCycleStatus, ['complete', 'revoked'], true)) {
                    continue;
                }

                $broadcastId = (string) ($item['id'] ?? '');
                if ($broadcastId === '') {
                    continue;
                }

                $streamData = $this->getBroadcastStreamKey($accessToken, $broadcastId);
                if (! $streamData || empty($streamData['stream_key'])) {
                    continue;
                }

                return [
                    'broadcast_id' => $broadcastId,
                    'stream_key' => $streamData['stream_key'],
                    'rtmp_url' => $streamData['rtmp_url'],
                ];
            }
        } catch (\Exception $e) {
            Log::error('YouTube broadcast title lookup exception', [
                'title' => $title,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function normalizeBroadcastTitle(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? ''));
    }

    /**
     * Find an existing YouTube live stream resource by exact title and return its reusable stream key.
     *
     * @param string $accessToken OAuth access token
     * @param string $title Live stream title to match (case/whitespace-insensitive)
     * @return array{stream_id: string, stream_key: string, rtmp_url: string}|null
     */
    private function findLiveStreamByTitle(string $accessToken, string $title): ?array
    {
        try {
            $response = $this->http()
                ->withToken($accessToken)
                ->get($this->baseUrl . '/liveStreams', [
                    'mine' => 'true',
                    'part' => 'id,snippet,cdn',
                    'maxResults' => 50,
                    'key' => $this->apiKey,
                ]);

            if (! $response->successful()) {
                Log::error('YouTube live stream title lookup failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'title' => $title,
                ]);

                return null;
            }

            $targetTitle = $this->normalizeBroadcastTitle($title);
            foreach (($response->json('items') ?? []) as $item) {
                $streamTitle = $this->normalizeBroadcastTitle((string) ($item['snippet']['title'] ?? ''));
                if ($streamTitle !== $targetTitle) {
                    continue;
                }

                $streamId = (string) ($item['id'] ?? '');
                $cdn = $item['cdn']['ingestionInfo'] ?? [];
                $streamKey = $cdn['streamName'] ?? null;
                $rtmpUrl = $cdn['ingestionAddress'] ?? null;

                if ($streamId === '' || ! $streamKey || ! $rtmpUrl) {
                    continue;
                }

                return [
                    'stream_id' => $streamId,
                    'stream_key' => $streamKey,
                    'rtmp_url' => $rtmpUrl,
                ];
            }
        } catch (\Exception $e) {
            Log::error('YouTube live stream title lookup exception', [
                'title' => $title,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get stream key for an existing broadcast.
     *
     * @param string $accessToken OAuth access token
     * @param string $broadcastId YouTube broadcast ID
     * @return array{stream_key: string, rtmp_url: string}|null
     */
    public function getBroadcastStreamKey(string $accessToken, string $broadcastId): ?array
    {
        try {
            $response = $this->http()
                ->withToken($accessToken)
                ->get($this->baseUrl . '/liveBroadcasts', [
                    'id' => $broadcastId,
                    'part' => 'contentDetails',
                    'key' => $this->apiKey,
                ]);

            if (!$response->successful()) {
                Log::error('YouTube broadcast fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $items = $data['items'] ?? [];
            if (empty($items)) {
                return null;
            }

            $streamId = $items[0]['contentDetails']['boundStreamId'] ?? null;
            if (!$streamId) {
                return null;
            }

            // Get stream details
            $streamResponse = $this->http()
                ->withToken($accessToken)
                ->get($this->baseUrl . '/liveStreams', [
                    'id' => $streamId,
                    'part' => 'cdn',
                    'key' => $this->apiKey,
                ]);

            if (!$streamResponse->successful()) {
                return null;
            }

            $streamData = $streamResponse->json();
            $streams = $streamData['items'] ?? [];
            if (empty($streams)) {
                return null;
            }

            $cdn = $streams[0]['cdn']['ingestionInfo'] ?? [];
            $streamKey = $cdn['streamName'] ?? null;
            $rtmpUrl = $cdn['ingestionAddress'] ?? null;

            if (!$streamKey || !$rtmpUrl) {
                return null;
            }

            return [
                'stream_key' => $streamKey,
                'rtmp_url' => $rtmpUrl,
            ];
        } catch (\Exception $e) {
            Log::error('YouTube stream key fetch exception', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if the stream bound to this broadcast is active (YouTube is receiving RTMP data).
     * Transition to "live" only succeeds when stream status is "active".
     *
     * @return array{stream_active: bool, stream_status: string|null, life_cycle_status: string|null}
     */
    public function getBroadcastStreamStatus(string $accessToken, string $broadcastId): array
    {
        $out = ['stream_active' => false, 'stream_status' => null, 'life_cycle_status' => null];
        try {
            $br = $this->http()
                ->withHeaders(['Authorization' => 'Bearer ' . $accessToken])
                ->get($this->baseUrl . '/liveBroadcasts', [
                    'id' => $broadcastId,
                    'part' => 'contentDetails,status',
                    'key' => $this->apiKey,
                ]);
            if (! $br->successful()) {
                return $out;
            }
            $data = $br->json();
            $items = $data['items'] ?? [];
            if (empty($items)) {
                return $out;
            }
            $out['life_cycle_status'] = $items[0]['status']['lifeCycleStatus'] ?? null;
            $streamId = $items[0]['contentDetails']['boundStreamId'] ?? null;
            if (! $streamId) {
                return $out;
            }
            $sr = $this->http()
                ->withHeaders(['Authorization' => 'Bearer ' . $accessToken])
                ->get($this->baseUrl . '/liveStreams', [
                    'id' => $streamId,
                    'part' => 'status',
                    'key' => $this->apiKey,
                ]);
            if (! $sr->successful()) {
                return $out;
            }
            $streamData = $sr->json();
            $streams = $streamData['items'] ?? [];
            if (empty($streams)) {
                return $out;
            }
            $out['stream_status'] = $streams[0]['status']['streamStatus'] ?? null;
            $out['stream_active'] = ($out['stream_status'] === 'active');
            return $out;
        } catch (\Exception $e) {
            Log::warning('YouTube broadcast/stream status check failed', ['message' => $e->getMessage()]);
            return $out;
        }
    }

    /**
     * Update broadcast status (transition to live, end, etc.).
     *
     * @param string $accessToken OAuth access token
     * @param string $broadcastId YouTube broadcast ID
     * @param string $broadcastStatus 'testing', 'live', 'complete'
     * @return bool
     */
    public function updateBroadcastStatus(string $accessToken, string $broadcastId, string $broadcastStatus): bool
    {
        try {
            if (empty($accessToken)) {
                Log::warning('YouTube transition skipped: empty access token');
                return false;
            }

            // YouTube API requires transition params as query string; no request body. Auth via Bearer token.
            $query = http_build_query([
                'id' => $broadcastId,
                'broadcastStatus' => $broadcastStatus,
                'part' => 'id,snippet,contentDetails,status',
                'key' => $this->apiKey,
            ]);
            $url = $this->baseUrl . '/liveBroadcasts/transition?' . $query;

            $response = $this->http()
                ->withHeaders(['Authorization' => 'Bearer ' . $accessToken])
                ->post($url);

            if (! $response->successful()) {
                Log::warning('YouTube transition failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('YouTube broadcast status update exception', [
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete a YouTube broadcast.
     *
     * @param string $accessToken OAuth access token
     * @param string $broadcastId YouTube broadcast ID
     * @return bool
     */
    public function deleteBroadcast(string $accessToken, string $broadcastId): bool
    {
        try {
            $url = $this->baseUrl . '/liveBroadcasts?' . http_build_query([
                'id' => $broadcastId,
                'key' => $this->apiKey,
            ]);
            $response = $this->http()
                ->withToken($accessToken)
                ->delete($url);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('YouTube broadcast deletion exception', [
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Upload a local video file to the user's YouTube channel (resumable upload).
     *
     * @return array{success: bool, video_id: ?string, watch_url: ?string, error: ?string}
     */
    public function uploadVideoFileForUser(
        User $user,
        string $localPath,
        string $title,
        string $description = '',
        string $privacyStatus = 'unlisted',
        ?callable $onProgress = null,
    ): array {
        $accessToken = $this->getValidAccessTokenForUser($user);

        // Nonprofit dashboard accounts usually connect YouTube on the organization.
        if (($accessToken === null || $accessToken === '') && $user->hasNonprofitDashboardRole()) {
            $organization = Organization::forAuthUser($user);
            if ($organization !== null) {
                $accessToken = $this->getValidAccessToken($organization);
            }
        }

        if ($accessToken === null || $accessToken === '') {
            return [
                'success' => false,
                'video_id' => null,
                'watch_url' => null,
                'error' => 'YouTube is not connected. Connect your channel under Integrations.',
            ];
        }

        if (! is_file($localPath) || filesize($localPath) === 0) {
            return [
                'success' => false,
                'video_id' => null,
                'watch_url' => null,
                'error' => 'Recording file is missing or empty.',
            ];
        }

        $privacyStatus = in_array($privacyStatus, ['public', 'unlisted', 'private'], true)
            ? $privacyStatus
            : 'unlisted';

        $title = Str::limit(trim($title), 100, '');
        if ($title === '') {
            $title = 'Meeting recording';
        }

        try {
            return $this->resumableUploadVideo(
                $accessToken,
                $localPath,
                $title,
                Str::limit($description, 4900, ''),
                $privacyStatus,
                $onProgress,
            );
        } catch (\Throwable $e) {
            Log::error('YouTube video upload exception', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'video_id' => null,
                'watch_url' => null,
                'error' => 'YouTube upload failed. Please try again.',
            ];
        }
    }

    /**
     * @return array{success: bool, video_id: ?string, watch_url: ?string, error: ?string}
     */
    private function resumableUploadVideo(
        string $accessToken,
        string $localPath,
        string $title,
        string $description,
        string $privacyStatus,
        ?callable $onProgress = null,
    ): array {
        $fileSize = filesize($localPath);
        $mimeType = $this->mimeTypeForVideoPath($localPath);

        $initResponse = $this->http()
            ->withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
                'snippet' => [
                    'title' => $title,
                    'description' => $description,
                ],
                'status' => [
                    'privacyStatus' => $privacyStatus,
                    'selfDeclaredMadeForKids' => false,
                ],
            ]);

        if (! $initResponse->successful()) {
            $error = $this->userMessageFromYoutubeApiError($initResponse->body());

            Log::warning('YouTube resumable upload init failed', [
                'status' => $initResponse->status(),
                'body' => $initResponse->body(),
            ]);

            return [
                'success' => false,
                'video_id' => null,
                'watch_url' => null,
                'error' => $error,
            ];
        }

        $uploadUrl = $initResponse->header('Location');
        if (! is_string($uploadUrl) || $uploadUrl === '') {
            return [
                'success' => false,
                'video_id' => null,
                'watch_url' => null,
                'error' => 'YouTube did not return an upload URL.',
            ];
        }

        $chunkSize = 2 * 1024 * 1024;
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            return [
                'success' => false,
                'video_id' => null,
                'watch_url' => null,
                'error' => 'Could not read the recording file.',
            ];
        }

        $offset = 0;
        $lastResponse = null;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    break;
                }

                $chunkLen = strlen($chunk);
                if ($chunkLen === 0) {
                    break;
                }

                $end = $offset + $chunkLen - 1;
                $contentRange = "bytes {$offset}-{$end}/{$fileSize}";

                $lastResponse = $this->http()
                    ->withToken($accessToken)
                    ->withHeaders([
                        'Content-Type' => $mimeType,
                        'Content-Length' => (string) $chunkLen,
                        'Content-Range' => $contentRange,
                    ])
                    ->withBody($chunk, $mimeType)
                    ->put($uploadUrl);

                if ($lastResponse->status() === 308) {
                    $offset += $chunkLen;

                    continue;
                }

                if (! $lastResponse->successful()) {
                    fclose($handle);

                    return [
                        'success' => false,
                        'video_id' => null,
                        'watch_url' => null,
                        'error' => $this->userMessageFromYoutubeApiError($lastResponse->body()),
                    ];
                }

                $offset += $chunkLen;

                if ($onProgress !== null && $fileSize > 0) {
                    $uploadPercent = (int) floor(($offset / $fileSize) * 100);
                    $onProgress(min(99, max(20, 20 + (int) floor($uploadPercent * 0.75))));
                }
            }
        } finally {
            fclose($handle);
        }

        if ($lastResponse === null || ! $lastResponse->successful()) {
            return [
                'success' => false,
                'video_id' => null,
                'watch_url' => null,
                'error' => $this->userMessageFromYoutubeApiError($lastResponse?->body()),
            ];
        }

        $videoId = $lastResponse->json('id');
        if (! is_string($videoId) || $videoId === '') {
            return [
                'success' => false,
                'video_id' => null,
                'watch_url' => null,
                'error' => 'YouTube upload completed but no video ID was returned.',
            ];
        }

        return [
            'success' => true,
            'video_id' => $videoId,
            'watch_url' => 'https://www.youtube.com/watch?v='.$videoId,
            'error' => null,
        ];
    }

    private function mimeTypeForVideoPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            default => 'application/octet-stream',
        };
    }
}

<?php

namespace App\Support;

use App\Models\OrganizationLivestream;
use App\Models\UserLivestream;

final class StreamingWorkerSourceUrl
{
    /**
     * Return an FFmpeg-safe source URL for the cloud worker.
     */
    public static function resolve(UserLivestream|OrganizationLivestream $livestream): string
    {
        $template = (string) config('streaming.worker_source_url_template', '');
        if ($template !== '') {
            return self::applyTemplate($template, $livestream);
        }

        $rtmpBase = self::workerRtmpPullBase();
        if ($rtmpBase !== '') {
            return rtrim($rtmpBase, '/').'/'.self::streamPath($livestream);
        }

        // Backward-compatible fallback (not FFmpeg readable).
        return (string) $livestream->getHostPushUrl(false);
    }

    public static function hasWorkerIngestConfigured(): bool
    {
        $template = (string) config('streaming.worker_source_url_template', '');

        return $template !== '' || self::workerRtmpPullBase() !== '';
    }

    public static function streamPath(UserLivestream|OrganizationLivestream $livestream): string
    {
        return 'ls_'.$livestream->id;
    }

    public static function bridgeMediaMtxHost(): ?string
    {
        $host = trim((string) config('streaming.bridge.host', ''));
        if ($host === '') {
            return null;
        }

        $host = preg_replace('#^https?://#i', '', $host);

        return rtrim($host, '/');
    }

    public static function hasBridgeConfigured(): bool
    {
        return self::bridgeMediaMtxHost() !== null;
    }

    /**
     * Whether the browser should WHIP-publish via VDO.Ninja &mediamtx=…
     * (requires bridge + worker ingest; skipped on local by default).
     */
    public static function shouldAttachVdoMediaMtxPush(): bool
    {
        if (! (bool) config('streaming.bridge.browser_push_enabled', true)) {
            return false;
        }

        if (! self::hasBridgeConfigured() || ! self::hasWorkerIngestConfigured()) {
            return false;
        }

        if (app()->environment(['local', 'development']) && ! (bool) config('streaming.bridge.push_on_local', false)) {
            return false;
        }

        return true;
    }

    /**
     * Host:port for VDO.Ninja &mediamtx (WHIP/WHEP port, default 8889 — same as canvas mixer).
     */
    public static function vdoMediaMtxHost(): ?string
    {
        if (! self::shouldAttachVdoMediaMtxPush()) {
            return null;
        }

        $host = self::bridgeMediaMtxHost();
        if ($host === null) {
            return null;
        }

        if (preg_match('/:\d+$/', $host) === 1) {
            return $host;
        }

        $port = (int) config('streaming.bridge.whip_port', 8889);

        return $host.':'.$port;
    }

    /**
     * HTTPS base for server-side canvas mixer WHEP/WHIP (https://host:port).
     */
    public static function bridgeWhepWhipBaseUrl(): ?string
    {
        $hostPort = self::vdoMediaMtxHost();
        if ($hostPort === null) {
            return null;
        }

        return 'https://'.$hostPort;
    }

    private static function workerRtmpPullBase(): string
    {
        $explicit = (string) config('streaming.worker_rtmp_pull_base', '');
        if ($explicit !== '') {
            return $explicit;
        }

        return (string) config('services.mediamtx.rtmp_public', '');
    }

    private static function applyTemplate(string $template, UserLivestream|OrganizationLivestream $livestream): string
    {
        $room = $livestream->getVdoRoomName();
        $map = [
            '{room}' => $room,
            '{room_slug}' => self::slug($room),
            '{livestream_id}' => (string) $livestream->id,
            '{mediamtx_path}' => self::streamPath($livestream),
        ];

        if ($livestream instanceof UserLivestream) {
            $map['{user_id}'] = (string) $livestream->user_id;
        }
        if ($livestream instanceof OrganizationLivestream) {
            $map['{organization_id}'] = (string) $livestream->organization_id;
        }

        return strtr($template, $map);
    }

    private static function slug(string $value): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value);

        return is_string($slug) && $slug !== '' ? trim($slug, '_') : 'room';
    }
}

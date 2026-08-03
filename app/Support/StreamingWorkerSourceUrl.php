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
            return self::withAacSuffix(self::applyTemplate($template, $livestream));
        }

        $rtmpBase = self::workerRtmpPullBase();
        if ($rtmpBase !== '') {
            return self::withAacSuffix(rtrim($rtmpBase, '/').'/'.self::streamPath($livestream));
        }

        // Backward-compatible fallback (not FFmpeg readable).
        return (string) $livestream->getHostPushUrl(false);
    }

    /**
     * The worker pulls `<path>_aac`, never the raw `<path>`.
     *
     * The browser WHIP-publishes VP8 + Opus to streamPath(); RTMP/FLV cannot
     * carry VP8, so ffmpeg reading the raw path dies in under a second with
     * "Input/output error" — indistinguishable from "the host never went live".
     * The bridge's runOnReady transcoder republishes every ready path as
     * H.264/AAC on `<path>_aac`, and that is the only RTMP-readable one.
     *
     * Applied to the finished URL rather than to streamPath() because
     * streamPath() is also the WHIP publish identity (whipUrl, push, seat
     * paths) — the publisher must keep using the raw path.
     *
     * Idempotent, and collapses accidental double-suffixing: a stale
     * STREAMING_WORKER_SOURCE_URL_TEMPLATE that already ends in `_aac` yields
     * one suffix, not `_aac_aac` (a path that does not exist on the bridge).
     */
    private static function withAacSuffix(string $url): string
    {
        if (! preg_match('#^rtmps?://#i', $url)) {
            return $url;
        }

        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        $path = preg_replace('/(?:_aac)+$/', '', rtrim($path, '/')).'_aac';

        return $query === null ? $path : $path.'?'.$query;
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

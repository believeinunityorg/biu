<?php

namespace App\Support;

use App\Models\OrganizationLivestream;
use App\Models\UserLivestream;

final class StreamingWorkerSourceUrl
{
    /**
     * Same MediaMTX NLB as STREAMING_BRIDGE_HOST=stream.believeinunity.org.
     * Used when the production CNAME is missing so browser WHIP does not fail NXDOMAIN.
     */
    private const BRIDGE_HOST_DNS_FALLBACK = 'stream.501c3ers.com';

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
        $host = rtrim((string) $host, '/');

        return self::withReachableBridgeHostname($host);
    }

    /**
     * If the configured bridge hostname does not resolve (e.g. stream.believeinunity.org
     * CNAME deleted in Cloudflare), fall back to stream.501c3ers.com — same AWS NLB.
     * Prevents VDO.Ninja "WHIP out failed" when DNS is NXDOMAIN.
     */
    private static function withReachableBridgeHostname(string $hostPort): string
    {
        $hostname = $hostPort;
        $portSuffix = '';
        if (preg_match('/^(.+):(\d+)$/', $hostPort, $m) === 1) {
            $hostname = $m[1];
            $portSuffix = ':'.$m[2];
        }

        if (self::hostnameResolves($hostname)) {
            return $hostPort;
        }

        $fallback = self::BRIDGE_HOST_DNS_FALLBACK;
        if (strcasecmp($hostname, $fallback) === 0 || self::hostnameResolves($fallback)) {
            return $fallback.$portSuffix;
        }

        return $hostPort;
    }

    private static function hostnameResolves(string $hostname): bool
    {
        if ($hostname === '' || filter_var($hostname, FILTER_VALIDATE_IP)) {
            return true;
        }

        $resolved = gethostbyname($hostname);

        return $resolved !== $hostname && filter_var($resolved, FILTER_VALIDATE_IP) !== false;
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
        if ($explicit === '') {
            $explicit = (string) config('services.mediamtx.rtmp_public', '');
        }
        if ($explicit === '') {
            return '';
        }

        return self::withReachableBridgeHostnameInUrl($explicit);
    }

    /**
     * Rewrite rtmp(s)://stream.believeinunity.org/… → stream.501c3ers.com when DNS is dead.
     */
    private static function withReachableBridgeHostnameInUrl(string $url): string
    {
        // Use ~ delimiter so `#` in the host class is literal (MediaMTX path segments use `#` rarely; ports use `:`).
        if (preg_match('~^(rtmps?://)([^/?#:]+)(.*)$~i', $url, $m) !== 1) {
            return $url;
        }

        $reachable = self::withReachableBridgeHostname($m[2]);

        return $m[1].$reachable.$m[3];
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

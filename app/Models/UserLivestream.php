<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Supporter (user) livestream — same VDO.Ninja flow as organization livestreams.
 */
class UserLivestream extends Model
{
    use HasFactory;

    protected $table = 'user_livestreams';

    protected $fillable = [
        'user_id',
        'room_name',
        'room_password',
        'youtube_stream_key',
        'youtube_broadcast_id',
        'status',
        'is_public',
        'title',
        'description',
        'scheduled_at',
        'started_at',
        'ended_at',
        'settings',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_public' => 'boolean',
        'settings' => 'array',
    ];

    private const VDO_ROOM_NAME_MAX_LENGTH = 30;
    private const ROOM_PREFIX = 'sup_';
    private const DROPBOX_FOLDER_MAX_LENGTH = 80;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateRoomName(): string
    {
        $roomName = self::ROOM_PREFIX . Str::random(8);
        $roomName = substr($roomName, 0, self::VDO_ROOM_NAME_MAX_LENGTH);
        while (self::where('room_name', $roomName)->exists()) {
            $roomName = self::ROOM_PREFIX . Str::random(8);
            $roomName = substr($roomName, 0, self::VDO_ROOM_NAME_MAX_LENGTH);
        }
        return $roomName;
    }

    public static function generatePassword(): string
    {
        return Str::random(8);
    }

    public function getDecryptedPassword(): string
    {
        try {
            return Crypt::decryptString($this->room_password);
        } catch (\Exception $e) {
            return $this->room_password;
        }
    }

    /**
     * Whether guests must enter a passcode to join. Driven by the
     * settings['require_passcode'] flag set at create/update time. A
     * room_password always exists (used for the VDO &password= param), but
     * it is only *enforced* on the join flow when this returns true.
     */
    public function requiresPasscode(): bool
    {
        return (bool) ($this->settings['require_passcode'] ?? false);
    }

    public function getDecryptedStreamKey(): ?string
    {
        if (! $this->youtube_stream_key) {
            return null;
        }
        try {
            return Crypt::decryptString($this->youtube_stream_key);
        } catch (\Exception $e) {
            return $this->youtube_stream_key;
        }
    }

    public function getVdoRoomName(): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $this->room_name);
        $safe = trim($safe, '_') ?: 'room';
        return substr($safe, 0, self::VDO_ROOM_NAME_MAX_LENGTH);
    }

    protected function getVdoGridLayouts(): array
    {
        return [
            [
                ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 50, 'c' => true, 'slot' => 0],
                ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 50, 'c' => true, 'slot' => 1],
                ['x' => 0, 'y' => 50, 'w' => 50, 'h' => 50, 'c' => true, 'slot' => 2],
                ['x' => 50, 'y' => 50, 'w' => 50, 'h' => 50, 'c' => true, 'slot' => 3],
            ],
            [
                ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 0],
                ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 1],
                ['x' => 0, 'y' => 33.333, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 2],
                ['x' => 50, 'y' => 33.333, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 3],
                ['x' => 0, 'y' => 66.667, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 4],
                ['x' => 50, 'y' => 66.667, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 5],
            ],
            [
                ['x' => 0, 'y' => 0, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 0],
                ['x' => 33.333, 'y' => 0, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 1],
                ['x' => 66.667, 'y' => 0, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 2],
                ['x' => 0, 'y' => 33.333, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 3],
                ['x' => 33.333, 'y' => 33.333, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 4],
                ['x' => 66.667, 'y' => 33.333, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 5],
                ['x' => 0, 'y' => 66.667, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 6],
                ['x' => 33.333, 'y' => 66.667, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 7],
                ['x' => 66.667, 'y' => 66.667, 'w' => 33.333, 'h' => 33.333, 'c' => true, 'slot' => 8],
            ],
        ];
    }

    public function getDirectorUrl(bool $recordToDropbox = false): string
    {
        $this->loadMissing('user');
        $settings = $this->settings ?? [];
        $displayName = $settings['display_name'] ?? null;
        $recordEnabled = (bool) ($settings['record_meeting'] ?? true);
        $hostName = $displayName ?: ($this->user?->name ?? 'Host');
        $label = rawurlencode($hostName);
        $password = $this->getDecryptedPassword();
        $room = rawurlencode($this->getVdoRoomName());
        $pass = rawurlencode((string) $password);
        $passwordParam = $pass !== '' ? '&password=' . $pass : '';
        $layouts = $this->getVdoGridLayouts();
        $layoutsParam = '&slotmode&layouts=' . rawurlencode(json_encode($layouts));
        $base = "https://vdo.ninja/?director={$room}{$passwordParam}&clearstorage&label={$label}&showlabels=zoom&fontsize=82&activespeaker=1&cleandirector&openscene{$layoutsParam}";

        if ($recordToDropbox) {
            $ctx = $this->resolveDropboxUploadContext();
            if ($ctx !== null) {
                $oauthService = app(\App\Services\DropboxOAuthService::class);
                $folderName = $ctx['folderName'];
                $folderPath = '/' . trim($folderName, '/');
                $oauthService->ensureFolderExists($ctx['token'], $folderPath);
                $base .= '&dropbox=' . rawurlencode($ctx['token']);
                $base .= '&dropboxpath=/' . rawurlencode($folderName);
                $base .= '&cloud=1';
            }
        }

        return $base;
    }

    private static function sanitizeDropboxFolderName(string $raw): string
    {
        $safe = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $raw);
        $safe = preg_replace('/\s+/', ' ', trim($safe));
        return trim($safe, " \t\n\r\0\x0B.");
    }

    public function getDropboxFolderName(): string
    {
        $userFolder = $this->user?->dropbox_folder_name;
        if ($userFolder !== null && $userFolder !== '') {
            $safe = self::sanitizeDropboxFolderName($userFolder);
            if ($safe !== '') {
                return substr($safe, 0, self::DROPBOX_FOLDER_MAX_LENGTH);
            }
        }
        return 'BIU Meeting Recordings';
    }

    /** Folder segment when recording via the supporter's organization's linked Dropbox (Integrations pattern). */
    public function getOrganizationDropboxFolderName(): string
    {
        $organization = $this->user ? Organization::forAuthUser($this->user) : null;
        $orgFolder = $organization?->dropbox_folder_name;
        if ($orgFolder !== null && $orgFolder !== '') {
            $safe = self::sanitizeDropboxFolderName($orgFolder);
            if ($safe !== '') {
                return substr($safe, 0, self::DROPBOX_FOLDER_MAX_LENGTH);
            }
        }

        return 'BIU Meeting Recordings';
    }

    /**
     * Resolves Dropbox access for VDO.Ninja uploads: user's own token first, else organization's (same as Unity Meet recordings).
     *
     * @return array{token: string, folderName: string}|null
     */
    protected function resolveDropboxUploadContext(): ?array
    {
        $this->loadMissing('user');
        if (! $this->user) {
            return null;
        }

        $oauthService = app(\App\Services\DropboxOAuthService::class);

        if (! empty($this->user->dropbox_refresh_token)) {
            $dropboxToken = $oauthService->getAccessTokenForUser($this->user);

            return ! empty($dropboxToken)
                ? ['token' => $dropboxToken, 'folderName' => $this->getDropboxFolderName()]
                : null;
        }

        $organization = Organization::forAuthUser($this->user);
        if ($organization && ! empty($organization->dropbox_refresh_token)) {
            $dropboxToken = $oauthService->getAccessTokenForOrganization($organization);

            return ! empty($dropboxToken)
                ? ['token' => $dropboxToken, 'folderName' => $this->getOrganizationDropboxFolderName()]
                : null;
        }

        return null;
    }

    /**
     * Guest / participant link. Same publisher path as host: &webcam + &ssb + &vdo=1 + &audiodevice=1 + &proaudio + &stereo=2
     * (no &intro — intro delays auto camera and shows an extra menu before permissions; host push does not use it).
     * Do not combine &showall with &style=6 — style overrides and breaks the group grid.
     * &showlabels=zoom &rows=1 &fontsize=82: names on tiles + one row (e.g. two people side-by-side).
     * &vdo=1 pre-selects the default camera; &audiodevice=1 the default mic; &autostart keeps room subscriptions stable.
     * Virtual backgrounds: curated gallery via {@see \App\Support\VdoMeetingVirtualBackground::querySegment()} (&virtualbackground + &imagelist).
     */
    public function getParticipantUrl(?int $seat = null): string
    {
        $password = $this->getDecryptedPassword();
        $room = rawurlencode($this->getVdoRoomName());
        $pass = rawurlencode((string) $password);
        $passwordParam = $pass !== '' ? '&password=' . $pass : '';
        $avatarInitialUrl = 'https://ui-avatars.com/api/?name=' . rawurlencode('Guest') . '&size=256&length=2';
        $base = 'https://vdo.ninja/?room=' . $room . $passwordParam . '&label=&webcam&ssb&vdo=1&audiodevice=1&proaudio&stereo=2&norecord&showlabels=zoom&showall&rows=1&fontsize=82&nocontrols&clock=false&avatar=' . rawurlencode($avatarInitialUrl) . \App\Support\VdoMeetingVirtualBackground::querySegment() . '&autostart&noheader';

        // Canvas mode + seat allocated -> publish this participant to MediaMTX at
        // ls_<id>_s<seat> so the canvas mixer can WHEP-subscribe. seat is null
        // elsewhere; single-host flow untouched.
        if ($seat !== null && $this->isCanvasModeEnabled()) {
            $host = \App\Support\StreamingWorkerSourceUrl::vdoMediaMtxHost();
            if ($host !== null) {
                $seatPath = \App\Support\StreamingWorkerSourceUrl::streamPath($this).'_s'.max(2, min(6, $seat));
                $base .= '&push=' . rawurlencode($seatPath) . '&mediamtx=' . $host . '&codec=vp8';
            }
        }
        return $base;
    }

    /** Canvas-mode opt-in flag — see OrganizationLivestream for full rationale. */
    public function isCanvasModeEnabled(): bool
    {
        return (bool) (($this->settings ?? [])['canvas_mode'] ?? true);
    }

    /** Best-effort seat allocator (2..6), wraps. Host = seat 1. MVP scope. */
    public function allocateNextGuestSeat(): int
    {
        $settings = $this->settings ?? [];
        $cursor = (int) ($settings['next_guest_seat'] ?? 2);
        $seat = max(2, min(6, $cursor));
        $next = $seat >= 6 ? 2 : $seat + 1;
        $settings['next_guest_seat'] = $next;
        $this->update(['settings' => $settings]);
        return $seat;
    }

    public function getRoomViewUrl(): string
    {
        $room = rawurlencode($this->getVdoRoomName());
        $pw = rawurlencode((string) $this->getDecryptedPassword());
        $passwordParam = $pw !== '' ? '&password=' . $pw : '';
        // Room gallery for Unity Live /live viewers: showall grid (no activespeaker — that mode hides other tiles).
        return "https://vdo.ninja/?room={$room}{$passwordParam}&nopush&viewonly&showall&showlabels=zoom&rows=1&fontsize=82&cleanoutput&noheader&nopreview&nocontrols&nosettings&clock=false&autostart";
    }

    public function getPublicViewUrl(): ?string
    {
        return $this->getRoomViewUrl();
    }

    public function getSoloViewUrl(): string
    {
        $roomName = $this->getVdoRoomName();
        $view = rawurlencode($roomName);
        $room = rawurlencode($roomName);
        $pw = rawurlencode((string) $this->getDecryptedPassword());
        $passwordParam = $pw !== '' ? '&password=' . $pw : '';
        return "https://vdo.ninja/?view={$view}&solo&fullscreen&room={$room}{$passwordParam}&showlabels=zoom&fontsize=82&cleanoutput&noheader&nopreview&nocontrols&nosettings&autostart";
    }

    /**
     * Public view URL with no audio (for Unity Live index card previews).
     * Uses room gallery (showall) so cards show the live meeting feed, not solo host-only.
     */
    public function getPublicViewUrlMuted(): string
    {
        return $this->getRoomViewUrl() . '&noaudio';
    }

    public function getPublicViewUrlFallback(): string
    {
        return $this->getSoloViewUrl();
    }

    /**
     * Participant canvas mixer URL (MVP, approved 2026-05-17). WHEP-subscribes
     * the 6 seat paths, composites a fixed 3x2 grid, mixes audio, and WHIP-
     * publishes the combined stream to streamPath — the path the bridge ->
     * worker -> YouTube pipeline already pulls.
     */
    public function getCanvasUrl(): string
    {
        return url('/livestreams/canvas/'.$this->room_name);
    }

    /**
     * Returns null for now. Was previously intended to publish a scene composite via VDO.Ninja's
     * &scene+&push+&mediamtx flags — but scene mode in VDO.Ninja is receive-only. Superseded by
     * the canvas mixer ({@see getCanvasUrl()}); kept returning null so old callers stay inert.
     */
    public function getScenePushUrl(): ?string
    {
        return null;
    }

    /**
     * Host push link (room + WHIP to MediaMTX when configured).
     * &webcam + &ssb: skip the camera vs screenshare fork for publishers; &ssb keeps screenshare in the bar.
     * Do not add &style=6 with &showall — style overrides and breaks the multi-participant grid (VDO treats showall as style 7).
     * &vdo=1 + &audiodevice=1: default camera/mic pre-selected; &autostart keeps room/grid subscriptions stable after load.
     *
     * `room` must match {@see getParticipantUrl()} / {@see getVdoRoomName()} so host and guests join the same VDO room
     * (multi-participant grid). `push` stays the stable stream id ({@see StreamingWorkerSourceUrl::streamPath()}) for WHIP/MediaMTX.
     * &nocontrols hides per-tile play/progress controls; &clock=false disables wall-clock overlay.
     * Virtual backgrounds: {@see \App\Support\VdoMeetingVirtualBackground::querySegment()} (gallery + &virtualbackground).
     */
    public function getHostPushUrl(bool $recordToDropbox = false): string
    {
        $this->loadMissing('user');
        $settings = $this->settings ?? [];
        $displayName = $settings['display_name'] ?? null;
        $recordEnabled = (bool) ($settings['record_meeting'] ?? true);
        $hostName = $displayName ?: ($this->user?->name ?? 'Host');
        $streamKey = \App\Support\StreamingWorkerSourceUrl::streamPath($this);
        $room = rawurlencode($this->getVdoRoomName());
        $push = rawurlencode($streamKey);
        $label = rawurlencode($hostName);
        $pass = rawurlencode((string) $this->getDecryptedPassword());
        $passwordParam = $pass !== '' ? '&password=' . $pass : '';
        $hn = trim($hostName) !== '' ? trim($hostName) : 'Host';
        $avatarImage = 'https://ui-avatars.com/api/?name=' . rawurlencode($hn) . '&size=256&length=2';
        $avatarParam = '&avatar=' . rawurlencode($avatarImage);
        // Canvas mode: host publishes to seat 1 path so the canvas mixer can WHEP-
        // subscribe alongside guests; the mixer publishes the combined stream to
        // streamPath. Single-host (default) mode publishes directly to streamPath
        // — the path the worker pulls — exactly as before.
        $effectivePush = $this->isCanvasModeEnabled()
            ? rawurlencode($streamKey.'_s1')
            : $push;

        $dropboxCtx = $recordToDropbox ? $this->resolveDropboxUploadContext() : null;

        // Host-only auto-record on first load. Do NOT use &autorecord — it also records remote
        // guests and starts a new file each time someone joins the room.
        if ($dropboxCtx !== null) {
            $recordParam = '&autorecordlocal=6000';
        } elseif ($recordEnabled) {
            $recordParam = '&autorecordlocal=6000';
        } else {
            $recordParam = '';
        }

        $base = "https://vdo.ninja/?room={$room}&push={$effectivePush}&label={$label}{$recordParam}&quality=0&bitrate=6000&webcam&ssb&vdo=1&audiodevice=1&proaudio&stereo=2&showlabels=zoom&showall&rows=1&fontsize=82&nocontrols&clock=false{$avatarParam}" . \App\Support\VdoMeetingVirtualBackground::querySegment() . "&autostart&noheader{$passwordParam}";

        // Restore the MediaMTX push so the host's webcam reaches the bridge and the AWS worker can
        // pull and forward to YouTube. (Was dropped under the assumption that getScenePushUrl
        // would replace it; that assumption was wrong — VDO.Ninja scene mode is receive-only.)
        $mediaMtxHost = \App\Support\StreamingWorkerSourceUrl::vdoMediaMtxHost();
        if ($mediaMtxHost !== null) {
            // VP8, not H264: every browser can VP8-encode for WebRTC, so the
            // video track is always published. Forcing H264 made browsers that
            // can't H264-encode drop video entirely (audio-only on YouTube).
            // The MediaMTX bridge transcodes VP8 -> H264 for the RTMP/YouTube leg.
            $base .= '&mediamtx=' . $mediaMtxHost . '&codec=vp8';
        }

        if ($dropboxCtx !== null) {
            $oauthService = app(\App\Services\DropboxOAuthService::class);
            $folderName = $dropboxCtx['folderName'];
            $folderPath = '/' . trim($folderName, '/');
            $oauthService->ensureFolderExists($dropboxCtx['token'], $folderPath);
            $base .= '&dropbox=' . rawurlencode($dropboxCtx['token']);
            $base .= '&dropboxpath=/' . rawurlencode($folderName);
            $base .= '&cloud=1';
        }

        return $base;
    }

    public function isChatAudioCall(): bool
    {
        return (($this->settings ?? [])['source'] ?? null) === 'chat_call'
            && (bool) (($this->settings ?? [])['audio_only'] ?? false);
    }

    /**
     * Audio-only host URL for chat calls (mic on, camera off, no YouTube/Dropbox).
     */
    public function getAudioCallHostUrl(): string
    {
        $this->loadMissing('user');
        $settings = $this->settings ?? [];
        $hostName = trim((string) ($settings['display_name'] ?? $this->user?->name ?? 'Host')) ?: 'Host';
        $room = rawurlencode($this->getVdoRoomName());
        $pass = rawurlencode((string) $this->getDecryptedPassword());
        $passwordParam = $pass !== '' ? '&password='.$pass : '';
        $label = rawurlencode($hostName);
        $avatarImage = 'https://ui-avatars.com/api/?name='.rawurlencode($hostName).'&size=256&length=2';

        return 'https://vdo.ninja/?room='.$room.'&push=main&label='.$label.$passwordParam
            .'&audiodevice=1&proaudio&stereo=2&norecord&showlabels=zoom&showall&rows=1&fontsize=82&clock=false'
            .'&avatar='.rawurlencode($avatarImage)
            .'&autostart&noheader&hideguests=false';
    }

    /**
     * Audio-only participant URL for chat calls.
     */
    public function getAudioCallParticipantUrl(string $displayLabel): string
    {
        $room = rawurlencode($this->getVdoRoomName());
        $pass = rawurlencode((string) $this->getDecryptedPassword());
        $passwordParam = $pass !== '' ? '&password='.$pass : '';
        $label = rawurlencode(trim($displayLabel) !== '' ? trim($displayLabel) : 'Guest');
        $avatarImage = 'https://ui-avatars.com/api/?name='.rawurlencode(trim($displayLabel) ?: 'Guest').'&size=256&length=2';

        return 'https://vdo.ninja/?room='.$room.'&label='.$label.$passwordParam
            .'&audiodevice=1&proaudio&stereo=2&norecord&showlabels=zoom&showall&rows=1&fontsize=82&clock=false'
            .'&avatar='.rawurlencode($avatarImage)
            .'&autostart&noheader&hideguests=false';
    }

    public function canStartMeeting(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    public function canGoLive(): bool
    {
        return $this->canSetUnityLive() || $this->canQueueYoutubeStream();
    }

    /** Listed on Unity Live (set-live). */
    public function canSetUnityLive(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'meeting_live', 'ended'], true);
    }

    /** Cloud relay to YouTube (may run while already live on Unity Live). */
    public function canQueueYoutubeStream(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'meeting_live', 'live', 'ended'], true);
    }

    public function wantsYoutubeLiveAtCreate(): bool
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        return ! empty($settings['go_live']);
    }
}

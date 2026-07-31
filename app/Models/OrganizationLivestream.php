<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class OrganizationLivestream extends Model
{
    use HasFactory;

    protected $table = 'org_livestreams';

    protected $fillable = [
        'organization_id',
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

    /**
     * Get the organization that owns this livestream.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Invite tokens for guest join links (/join/{token}).
     */
    public function inviteTokens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LivestreamInviteToken::class, 'organization_livestream_id');
    }

    /** VDO.Ninja room name max length (they allow &lt; 31). */
    private const VDO_ROOM_NAME_MAX_LENGTH = 30;

    /** Max length for Dropbox folder name (path segment). */
    private const DROPBOX_FOLDER_MAX_LENGTH = 80;

    /** Meeting slug prefix per client: biu_mtg_xxxx */
    private const MEETING_SLUG_PREFIX = 'biu_mtg_';

    /**
     * Generate a unique meeting/room name (slug) for BIU meetings.
     * Format: biu_mtg_ + 8 random alphanumeric. VDO.Ninja: alphanumeric only, max 31 chars.
     */
    public static function generateRoomName(Organization $organization): string
    {
        $roomName = self::MEETING_SLUG_PREFIX . Str::random(8);
        $roomName = substr($roomName, 0, self::VDO_ROOM_NAME_MAX_LENGTH);
        while (self::where('room_name', $roomName)->exists()) {
            $roomName = self::MEETING_SLUG_PREFIX . Str::random(8);
            $roomName = substr($roomName, 0, self::VDO_ROOM_NAME_MAX_LENGTH);
        }

        return $roomName;
    }

    /**
     * Generate a random 8-character password.
     */
    public static function generatePassword(): string
    {
        return Str::random(8);
    }

    /**
     * Get the encrypted password.
     */
    public function getEncryptedPassword(): string
    {
        return Crypt::encryptString($this->room_password);
    }

    /**
     * Decrypt and get the password.
     */
    public function getDecryptedPassword(): string
    {
        try {
            return Crypt::decryptString($this->room_password);
        } catch (\Exception $e) {
            return $this->room_password; // Fallback if not encrypted
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

    /**
     * Get the encrypted YouTube stream key.
     */
    public function getEncryptedStreamKey(): ?string
    {
        if (!$this->youtube_stream_key) {
            return null;
        }
        return Crypt::encryptString($this->youtube_stream_key);
    }

    /**
     * Decrypt and get the YouTube stream key.
     */
    public function getDecryptedStreamKey(): ?string
    {
        if (!$this->youtube_stream_key) {
            return null;
        }
        try {
            return Crypt::decryptString($this->youtube_stream_key);
        } catch (\Exception $e) {
            return $this->youtube_stream_key; // Fallback if not encrypted
        }
    }

    /**
     * Room name safe for VDO.Ninja (alphanumeric + underscore only, max 31 chars).
     */
    public function getVdoRoomName(): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $this->room_name);
        $safe = trim($safe, '_') ?: 'room';
        return substr($safe, 0, self::VDO_ROOM_NAME_MAX_LENGTH);
    }

    /**
     * Get the VDO.Ninja director URL.
     * Use only &director= (not &room=); VDO.Ninja says "there should be only &director OR &room".
     * &clearstorage clears any saved session so the "load previous session?" prompt won't offer the old bad URL.
     * &label= set to organization name so the director does not need to enter a name manually.
     * &cleandirector = cleaner control center; &slotmode + &layouts = guest grid layouts (2x2, 2x3, 3x3).
     * No &record here: recording only from the host push tab (one recording). Director with &record can trigger
     * a new recording when each guest joins; we avoid that by leaving record only on getHostPushUrl().
     * @param bool $recordToDropbox If true and org has Dropbox, add dropbox params so Director recordings save in folder (only when $recordToDropbox).
     */
    public function getDirectorUrl(bool $recordToDropbox = true): string
    {
        $this->loadMissing('organization');
        $settings = $this->settings ?? [];
        $displayName = $settings['display_name'] ?? null;
        $recordEnabled = (bool) ($settings['record_meeting'] ?? true);
        $orgName = $displayName ?: ($this->organization?->name ?? 'Host');
        $label = rawurlencode($orgName);
        $password = $this->getDecryptedPassword();
        $room = rawurlencode($this->getVdoRoomName());
        $pass = rawurlencode((string) $password);
        $passwordParam = $pass !== '' ? '&password=' . $pass : '';

        $layouts = $this->getVdoGridLayouts();
        $layoutsParam = '&slotmode&layouts=' . rawurlencode(json_encode($layouts));
        // openscene = allow scene viewers (e.g. Unity Live embed) to receive scene 0. showlabels=zoom = Meet-style names on tiles.
        // No &record = no recording from director tab. Do NOT add &autorecordremote (value is bitrate; 0 would enable with 0 kbps and start extra recordings when guests join).
        $base = "https://vdo.ninja/?director={$room}{$passwordParam}&clearstorage&label={$label}&showlabels=zoom&fontsize=82&activespeaker=1&cleandirector&openscene{$layoutsParam}";

        // Dropbox: same as host push — ensure folder exists, then add params when host chose cloud recording.
        if ($recordToDropbox && $this->organization) {
            $oauthService = app(\App\Services\DropboxOAuthService::class);
            $dropboxToken = $oauthService->getAccessTokenForOrganization($this->organization);
            if (! empty($dropboxToken)) {
                $folderName = $this->getDropboxFolderName();
                $folderPath = '/' . trim($folderName, '/');
                $oauthService->ensureFolderExists($dropboxToken, $folderPath);
                $base .= '&dropbox=' . rawurlencode($dropboxToken);
                $base .= '&dropboxpath=/' . rawurlencode($folderName);
                $base .= '&cloud=1';
            }
        }

        return $base;
    }

    /**
     * Default grid layouts for VDO.Ninja director: 2x2, 2x3, 3x3 guest grids.
     * Each layout is an array of slot objects: x,y,w,h (percent), c (cover), slot (guest index).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function getVdoGridLayouts(): array
    {
        return [
            // Layout 0: 2x2 grid (4 guests)
            [
                ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 50, 'c' => true, 'slot' => 0],
                ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 50, 'c' => true, 'slot' => 1],
                ['x' => 0, 'y' => 50, 'w' => 50, 'h' => 50, 'c' => true, 'slot' => 2],
                ['x' => 50, 'y' => 50, 'w' => 50, 'h' => 50, 'c' => true, 'slot' => 3],
            ],
            // Layout 1: 2x3 grid (6 guests)
            [
                ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 0],
                ['x' => 50, 'y' => 0, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 1],
                ['x' => 0, 'y' => 33.333, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 2],
                ['x' => 50, 'y' => 33.333, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 3],
                ['x' => 0, 'y' => 66.667, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 4],
                ['x' => 50, 'y' => 66.667, 'w' => 50, 'h' => 33.333, 'c' => true, 'slot' => 5],
            ],
            // Layout 2: 3x3 grid (9 guests)
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

    /**
     * Get the VDO.Ninja participant/guest URL.
     * Same publisher path as host: &webcam + &ssb + &vdo=1 + &audiodevice=1 + &proaudio + &stereo=2 (no &intro — host does not use intro).
     * &label= (empty) can be set by the app to the signed-in name. &showlabels=zoom &showall &rows=1 = group grid + names on tiles + one row (two people side-by-side).
     * Virtual backgrounds: {@see \App\Support\VdoMeetingVirtualBackground::querySegment()} (gallery + &virtualbackground).
     * Do not use &style=6 with &showall — style overrides and breaks the grid.
     * &vdo=1 + &audiodevice=1 pre-select default camera/mic; &autostart keeps the room view stable.
     */
    public function getParticipantUrl(?int $seat = null): string
    {
        $password = $this->getDecryptedPassword();
        $room = rawurlencode($this->getVdoRoomName());
        $pass = rawurlencode((string) $password);
        $passwordParam = $pass !== '' ? '&password=' . $pass : '';
        $avatarInitialUrl = 'https://ui-avatars.com/api/?name=' . rawurlencode('Guest') . '&size=256&length=2';
        $base = 'https://vdo.ninja/?room=' . $room . $passwordParam . '&label=&webcam&ssb&vdo=1&audiodevice=1&proaudio&stereo=2&norecord&showlabels=zoom&showall&rows=1&fontsize=82&nocontrols&clock=false&avatar=' . rawurlencode($avatarInitialUrl) . \App\Support\VdoMeetingVirtualBackground::querySegment() . '&autostart&noheader';

        // When canvas mode is enabled AND the caller allocated a seat, also publish
        // this participant's camera to MediaMTX at ls_<id>_s<seat> so the canvas
        // mixer can WHEP-subscribe and composite. seat is null elsewhere (e.g. UI
        // rendering of the shared URL) — single-host flow stays untouched.
        if ($seat !== null && $this->isCanvasModeEnabled()) {
            $host = \App\Support\StreamingWorkerSourceUrl::vdoMediaMtxHost();
            if ($host !== null) {
                $seatPath = \App\Support\StreamingWorkerSourceUrl::streamPath($this).'_s'.max(2, min(6, $seat));
                $base .= '&push=' . rawurlencode($seatPath) . '&mediamtx=' . $host . '&codec=vp8';
            }
        }
        return $base;
    }

    /**
     * Canvas mode opt-in flag (settings.canvas_mode). Off by default so the
     * proven single-host pipeline keeps working untouched until canvas mode is
     * explicitly enabled on a livestream.
     */
    public function isCanvasModeEnabled(): bool
    {
        return (bool) (($this->settings ?? [])['canvas_mode'] ?? true);
    }

    /**
     * Allocate the next guest seat (2..6) and persist the cursor in settings.
     * Best-effort MVP allocator: wraps after 6 (no leave/reconnect handling per
     * approved scope — fixed 6 grid, no dynamic seats). Host is always seat 1.
     */
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

    /**
     * OBS scene URL: director=ROOM&output. Not the push link — this is the director output (what Unity Live uses).
     */
    public function getObsOutputUrl(): string
    {
        $room = rawurlencode($this->getVdoRoomName());
        $pw = rawurlencode((string) $this->getDecryptedPassword());
        $passwordParam = $pw !== '' ? '&password=' . $pw : '';
        return "https://vdo.ninja/?director={$room}{$passwordParam}&output&autostart&cleanoutput&noheader&nocontrols&nosettings";
    }

    /**
     * Public viewer URL for /live/{slug}: view-only room so viewers see the same as the host.
     * Shows: host screen/share, host webcam, and every participant's screen share or webcam.
     * Uses room view (getRoomViewUrl), not solo — so all pushers in the room are visible.
     */
    public function getPublicViewUrl(): ?string
    {
        return $this->getRoomViewUrl();
    }

    /**
     * Room view URL: view-only (no camera/screen prompt). Same content the host sees.
     * - nopush / viewonly: receive only, no publishing (viewers never prompted to share).
     * - showall: show every participant (host + guests) who is pushing screen or webcam.
     * - showall + rows=1: same group grid as the meeting (all host + guest tiles). No activespeaker on
     *   public view — activespeaker hides non-speaking participants.
     */
    public function getRoomViewUrl(): string
    {
        $room = rawurlencode($this->getVdoRoomName());
        $pw = rawurlencode((string) $this->getDecryptedPassword());
        $passwordParam = $pw !== '' ? '&password=' . $pw : '';
        return "https://vdo.ninja/?room={$room}{$passwordParam}&nopush&viewonly&showall&showlabels=zoom&rows=1&fontsize=82&cleanoutput&noheader&nopreview&nocontrols&nosettings&clock=false&autostart";
    }

    /**
     * Solo view URL: one stream full screen (view=roomName&solo). Only shows host stream (ID = room name).
     * Use as fallback or when you only need the host feed.
     * &autostart = start receiving/displaying the stream as soon as the viewer page loads (helps on reload).
     */
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
     * Fallback viewer URL: solo view (host stream only) if director output is not in use.
     */
    public function getPublicViewUrlFallback(): string
    {
        return $this->getSoloViewUrl();
    }

    /**
     * Public view URL with no audio (for Unity Live index card previews).
     * Uses room gallery (showall) so cards show the live meeting feed, not solo host-only.
     */
    public function getPublicViewUrlMuted(): string
    {
        return $this->getRoomViewUrl() . '&noaudio';
    }

    /**
     * Sanitize a string for use as a Dropbox folder name (path-safe).
     */
    private static function sanitizeDropboxFolderName(string $raw): string
    {
        $safe = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $raw);
        $safe = preg_replace('/\s+/', ' ', trim($safe));
        return trim($safe, " \t\n\r\0\x0B.");
    }

    /**
     * Dropbox folder name: org-level only so one folder per org (no folder per livestream/title).
     * Uses organization dropbox_folder_name if set, otherwise "BIU Meeting Recordings".
     */
    public function getDropboxFolderName(): string
    {
        $orgFolder = $this->organization?->dropbox_folder_name;
        if ($orgFolder !== null && $orgFolder !== '') {
            $safe = self::sanitizeDropboxFolderName($orgFolder);
            if ($safe !== '') {
                return substr($safe, 0, self::DROPBOX_FOLDER_MAX_LENGTH);
            }
        }
        return 'BIU Meeting Recordings';
    }

    /**
     * Participant canvas mixer URL (MVP, approved 2026-05-17). The page WHEP-
     * subscribes the 6 seat paths from MediaMTX, composites a fixed 3x2 grid,
     * mixes audio, and WHIP-publishes the combined stream to streamPath — the
     * exact path the bridge -> FFmpeg worker -> YouTube pipeline already pulls.
     */
    public function getCanvasUrl(): string
    {
        return url('/livestreams/canvas/'.$this->room_name);
    }

    /**
     * Returns null for now. Was previously intended to publish a scene composite of all room
     * participants to MediaMTX via VDO.Ninja's &scene+&push+&mediamtx flags — but scene mode in
     * VDO.Ninja is receive-only (designed as an OBS Browser Source), so the iframe loaded but
     * never published anything and the bridge stayed empty. Superseded by the canvas mixer
     * ({@see getCanvasUrl()}); kept returning null so any old callers stay inert.
     */
    public function getScenePushUrl(): ?string
    {
        return null;
    }

    /**
     * Get the VDO.Ninja host push link (no OBS). &webcam + &ssb avoids the camera vs screenshare fork for publishers.
     * Do not use &style=6 with &showall — style overrides and breaks the multi-participant grid.
     * &vdo=1 + &audiodevice=1 pre-select default camera/mic; &autostart keeps room/grid behavior stable.
     * Custom host avatar: set livestream settings['host_avatar_url'] to a full image URL; otherwise no avatar param.
     * @param bool $recordToDropbox If true and org has Dropbox, add dropbox params so recordings save to Dropbox. If false, recording is local only (browser download).
     *
     * `room` must match {@see getParticipantUrl()} / {@see getVdoRoomName()} so host and guests share one VDO room (grid).
     * `push` remains {@see StreamingWorkerSourceUrl::streamPath()} for stable WHIP / worker ingest identity.
     * &nocontrols hides per-tile play/progress bar; &clock=false disables wall-clock overlay.
     * Virtual backgrounds: {@see \App\Support\VdoMeetingVirtualBackground::querySegment()} (gallery + &virtualbackground).
     */
    public function getHostPushUrl(bool $recordToDropbox = true): string
    {
        $this->loadMissing('organization');
        $settings = $this->settings ?? [];
        $displayName = $settings['display_name'] ?? null;
        $recordEnabled = (bool) ($settings['record_meeting'] ?? true);
        $hostName = $displayName ?: ($this->organization?->name ?? 'Host');
        $streamKey = \App\Support\StreamingWorkerSourceUrl::streamPath($this);
        $room = rawurlencode($this->getVdoRoomName());
        $push = rawurlencode($streamKey);
        $label = rawurlencode($hostName);
        $pass = rawurlencode((string) $this->getDecryptedPassword());
        $passwordParam = $pass !== '' ? '&password=' . $pass : '';
        $avatarParam = '';
        $avatarUrl = $settings['host_avatar_url'] ?? null;
        if (!empty($avatarUrl) && filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
            $avatarParam = '&avatar=' . rawurlencode($avatarUrl);
        } else {
            $hn = trim($hostName) !== '' ? trim($hostName) : 'Host';
            $avatarImage = 'https://ui-avatars.com/api/?name=' . rawurlencode($hn) . '&size=256&length=2';
            $avatarParam = '&avatar=' . rawurlencode($avatarImage);
        }
        // In canvas mode, the host publishes to seat 1 path so the canvas mixer
        // can WHEP-subscribe it alongside the guest seats. The canvas page then
        // publishes the combined output to streamPath (where the worker pulls).
        // Single-host (default) mode keeps publishing directly to streamPath.
        $effectivePush = $this->isCanvasModeEnabled()
            ? rawurlencode($streamKey.'_s1')
            : $push;

        $dropboxToken = null;
        if ($recordToDropbox && $this->organization) {
            $oauthService = app(\App\Services\DropboxOAuthService::class);
            $dropboxToken = $oauthService->getAccessTokenForOrganization($this->organization);
            $dropboxToken = ! empty($dropboxToken) ? $dropboxToken : null;
        }

        // Host-only auto-record on first load. Do NOT use &autorecord — it also records remote
        // guests and starts a new file each time someone joins the room.
        if ($dropboxToken !== null) {
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

        if ($dropboxToken !== null) {
            $oauthService = app(\App\Services\DropboxOAuthService::class);
            $folderName = $this->getDropboxFolderName();
            $folderPath = '/' . trim($folderName, '/');

            // VDO.Ninja only uploads to dropboxpath when the folder exists; otherwise it uploads to root.
            $oauthService->ensureFolderExists($dropboxToken, $folderPath);

            $base .= '&dropbox=' . rawurlencode($dropboxToken);
            $base .= '&dropboxpath=/' . rawurlencode($folderName);
            $base .= '&cloud=1';
        }

        return $base;
    }

    /**
     * Check if the stream is currently live.
     */
    public function isLive(): bool
    {
        return $this->status === 'live' && $this->started_at !== null;
    }

    /**
     * Check if the stream can be started (meeting in progress, not yet streaming).
     */
    public function canStartMeeting(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    /**
     * Check if the stream can be started (legacy).
     */
    public function canStart(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    /**
     * Check if host can trigger "Go Live" (stream to viewers).
     */
    public function canGoLive(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'meeting_live', 'ended'], true);
    }

    /**
     * Check if the stream can be ended.
     */
    public function canEnd(): bool
    {
        return $this->status === 'live';
    }

    /**
     * Scope for active/live streams.
     */
    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    /**
     * Scope for scheduled streams.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope for organization streams.
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}

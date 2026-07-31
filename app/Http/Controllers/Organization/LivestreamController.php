<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\LivestreamInviteToken;
use App\Models\LivestreamRecordingDecline;
use App\Models\Organization;
use App\Models\OrganizationLivestream;
use App\Models\StreamingJob;
use App\Models\UserLivestream;
use App\Services\Streaming\StreamingPreflight;
use App\Services\Streaming\StreamingQueueService;
use App\Support\MeetingRecordingPreference;
use App\Support\StreamingWorkerSourceUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\LivestreamHostAbandonService;
use App\Services\StreamBridgeService;
use App\Services\YouTubeService;

class LivestreamController extends Controller
{
    /**
     * Show the create stream page.
     */
    public function create(): Response
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        // Check YouTube integration directly from DB (relationship may omit token columns)
        $orgTokens = Organization::where('id', $organization->id)
            ->first(['youtube_access_token', 'youtube_refresh_token']);
        $hasYoutubeIntegrated = $orgTokens
            && (!empty($orgTokens->youtube_access_token) || !empty($orgTokens->youtube_refresh_token));

        return Inertia::render('Organization/Livestreams/Create', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => Str::slug($organization->name),
            ],
            // Force a canonical organization display name for the UI.
            'defaultDisplayName' => $organization->name,
            'hasYoutubeIntegrated' => $hasYoutubeIntegrated,
        ]);
    }

    /**
     * Store a new livestream.
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'scheduled_at' => 'nullable|date|after:now',
            'youtube_stream_key' => 'nullable|string',
            'auto_create_youtube' => 'nullable|boolean',
            'display_name' => 'nullable|string|max:255',
            'is_public' => 'nullable|boolean',
            'require_passcode' => 'nullable|boolean',
            'passcode' => 'nullable|string|min:6|max:100',
            'record_meeting' => 'nullable|boolean',
        ]);

        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            return back()->withErrors(['error' => 'Organization not found']);
        }

        // Generate room name and password
        $roomName = OrganizationLivestream::generateRoomName($organization);
        $requirePasscode = $request->boolean('require_passcode', true);
        $password = $requirePasscode
            ? (string) ($request->input('passcode') ?: OrganizationLivestream::generatePassword())
            : '';

        // Encrypt password
        $encryptedPassword = Crypt::encryptString($password);

        // Handle YouTube integration
        $encryptedStreamKey = null;
        $youtubeBroadcastId = null;
        $settings = null;
        $scheduledAt = $request->scheduled_at ? \Carbon\Carbon::parse($request->scheduled_at) : null;

        // If auto-create YouTube broadcast is requested and organization has OAuth token
        if ($request->boolean('auto_create_youtube') && ($organization->youtube_access_token || $organization->youtube_refresh_token)) {
            try {
                $youtubeService = app(YouTubeService::class);
                $accessToken = $youtubeService->getValidAccessToken($organization);
                if (! $accessToken) {
                    return redirect()->back()->withErrors([
                        'auto_create_youtube' => 'YouTube token expired or invalid. Please reconnect YouTube in Integrations.',
                    ])->withInput();
                }

                $broadcastData = $youtubeService->createLiveBroadcast(
                    $accessToken,
                    $request->title ?: 'Unity Meet - ' . $organization->name,
                    $request->description,
                    $scheduledAt
                );

                if ($broadcastData) {
                    $encryptedStreamKey = Crypt::encryptString($broadcastData['stream_key']);
                    $youtubeBroadcastId = $broadcastData['broadcast_id'];
                    $vdoRoomId = $roomName; // Use room name as VDO.Ninja room for push/view links
                    $settings = [
                        'vdo_room_id' => $vdoRoomId,
                        'rtmp_url' => $broadcastData['rtmp_url'] ?? null,
                    ];
                } else {
                    Log::warning('Failed to create YouTube broadcast for livestream', [
                        'organization_id' => $organization->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Error creating YouTube broadcast', [
                    'organization_id' => $organization->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($request->youtube_stream_key) {
            // Manual stream key entry
            $encryptedStreamKey = Crypt::encryptString($request->youtube_stream_key);
        }

        $baseSettings = $settings ?? [];
        // Organization meetings always use the organization name as display name (not the auth user's name).
        $baseSettings['display_name'] = (string) $organization->name;
        $baseSettings['record_meeting'] = $request->boolean('record_meeting', true);
        $baseSettings['require_passcode'] = $requirePasscode;

        $livestream = OrganizationLivestream::create([
            'organization_id' => $organization->id,
            'room_name' => $roomName,
            'room_password' => $encryptedPassword,
            'youtube_stream_key' => $encryptedStreamKey,
            'youtube_broadcast_id' => $youtubeBroadcastId,
            'status' => $request->scheduled_at ? 'scheduled' : 'draft',
            'is_public' => $request->boolean('is_public', true),
            'title' => $request->title,
            'description' => $request->description,
            'scheduled_at' => $scheduledAt,
            'settings' => !empty($baseSettings) ? $baseSettings : null,
        ]);

        // Action-first flow: if minimal create (no YouTube, no schedule), go to Meeting Ready
        $minimalCreate = !$request->boolean('auto_create_youtube') && !$request->scheduled_at && !$request->youtube_stream_key;
        if ($minimalCreate) {
            return redirect()->route('organization.livestreams.ready', $livestream->id)
                ->with('success', 'Meeting ready!');
        }

        return redirect()->route('organization.livestreams.show', $livestream->id)
            ->with('success', 'Meeting created successfully!');
    }

    /**
     * Meeting Ready (Pane 2) — show meeting ID, passcode, invite link; Start Meeting goes to show.
     */
    public function ready($id): Response
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        $password = $livestream->getDecryptedPassword();
        $joinUrl = \App\Support\UnityMeetUrls::guestJoinUrl($livestream->room_name);

        return Inertia::render('Organization/Livestreams/Ready', [
            'livestream' => [
                'id' => $livestream->id,
                'title' => $livestream->title,
                'roomName' => $livestream->room_name,
                'roomPassword' => $password,
                'requiresPasscode' => $livestream->requiresPasscode(),
                'joinUrl' => $joinUrl,
            ],
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
        ]);
    }

    /**
     * Show the host dashboard for a livestream.
     */
    public function show($id): Response
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        $latestInviteToken = $livestream->inviteTokens()->latest('id')->first();
        $latestInviteUrl = $latestInviteToken ? url('/join/' . $latestInviteToken->token) : null;

        // Get decrypted values for display (but don't expose in API)
        // When Dropbox is connected, offer both URLs so host can choose: record locally or to Dropbox
        $dropboxConnected = !empty($livestream->organization->dropbox_refresh_token);
        $directorUrl = $livestream->getDirectorUrl(true);
        $directorUrlLocal = $livestream->getDirectorUrl(false);
        $directorUrlDropbox = $dropboxConnected ? $livestream->getDirectorUrl(true) : null;
        $participantUrl = $livestream->getParticipantUrl();
        $hostPushUrl = $livestream->getHostPushUrl(false);
        $hostPushUrlLocal = $livestream->getHostPushUrl(false);
        $hostPushUrlDropbox = $dropboxConnected ? $livestream->getHostPushUrl(true) : null;
        // Scene-mixer URL: composite of ALL room participants → MediaMTX → worker → YouTube.
        // Frontend loads this in a hidden iframe so guests reach YouTube too.
        $scenePushUrl = $livestream->getScenePushUrl();
        $watchUrl = $livestream->getPublicViewUrl(); // Viewer link (no director) — for Unity Live / share with audience
        $password = $livestream->getDecryptedPassword();

        // VDO.Ninja: viewer embed uses ?view=room (no OBS); Unity Live uses getPublicViewUrl() which uses same format
        // OBS flow: push link + director view link + stream key when YouTube is connected
        $vdoRoom = $livestream->getVdoRoomName();
        $settings = $livestream->settings ?? [];
        $rtmpUrl = $settings['rtmp_url'] ?? null;
        $youtubeGoLiveEnabled = !empty($livestream->youtube_broadcast_id) && !empty($livestream->youtube_stream_key);
        $passwordParam = !empty($password) ? '&password=' . rawurlencode($password) : '';
        $directorLabel = '&label=' . rawurlencode($organization->name ?? 'Host');
        $pushLink = $youtubeGoLiveEnabled
            ? "https://vdo.ninja/?push={$vdoRoom}&cleanoutput{$passwordParam}{$directorLabel}"
            : null;
        // OBS Browser Source (when using OBS): director + output for capture
        $viewLink = $youtubeGoLiveEnabled
            ? "https://vdo.ninja/?director=" . rawurlencode($vdoRoom) . $passwordParam . "&output&autostart&cleanoutput"
            : null;
        $streamKeyDisplay = $youtubeGoLiveEnabled ? $livestream->getDecryptedStreamKey() : null;
        $mediamtxEnabled = !empty(config('services.mediamtx.publish_url'));

        $unityLiveUrl = url('/unity-live/' . $livestream->room_name);
        $liveViewerUrl = url('/live/' . $livestream->room_name);

        $recordingConsentDeclines = LivestreamRecordingDecline::query()
            ->where('livestream_kind', 'organization')
            ->where('livestream_id', $livestream->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (LivestreamRecordingDecline $r) => [
                'id' => $r->id,
                'guestLabel' => $r->guest_label,
                'createdAt' => $r->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $latestStreamingJob = StreamingJob::query()
            ->where('livestream_kind', 'organization')
            ->where('livestream_id', $livestream->id)
            ->latest('id')
            ->first();

        return Inertia::render('Organization/Livestreams/Show', [
            'recordingConsentDeclines' => $recordingConsentDeclines,
            'broadcastChannel' => \App\Support\UnityLiveBroadcast::channelName($livestream),
            'livestream' => [
                'id' => $livestream->id,
                'title' => $livestream->title,
                'description' => $livestream->description,
                'roomName' => $livestream->room_name,
                'roomPassword' => $password,
                'requiresPasscode' => $livestream->requiresPasscode(),
                'directorUrl' => $directorUrl,
                'directorUrlLocal' => $directorUrlLocal,
                'directorUrlDropbox' => $directorUrlDropbox,
                'participantUrl' => $participantUrl,
                'hostPushUrl' => $hostPushUrl,
                'hostPushUrlLocal' => $hostPushUrlLocal,
                'hostPushUrlDropbox' => $hostPushUrlDropbox,
                'scenePushUrl' => $scenePushUrl,
                'canvasUrl' => $livestream->getCanvasUrl(),
                'canvasMode' => $livestream->isCanvasModeEnabled(),
                'browserMediaMtxPush' => \App\Support\StreamingWorkerSourceUrl::shouldAttachVdoMediaMtxPush(),
                'dropboxRecordingAvailable' => $dropboxConnected,
                'watchUrl' => $watchUrl,
                'unityLiveUrl' => $unityLiveUrl,
                'liveViewerUrl' => $liveViewerUrl,
                'isPublic' => (bool) $livestream->is_public,
                'status' => $livestream->status,
                'scheduledAt' => $livestream->scheduled_at?->toIso8601String(),
                'startedAt' => $livestream->started_at?->toIso8601String(),
                'endedAt' => $livestream->ended_at?->toIso8601String(),
                'hasStreamKey' => !empty($livestream->youtube_stream_key),
                'youtubeBroadcastId' => $livestream->youtube_broadcast_id,
                'youtubeGoLiveEnabled' => $youtubeGoLiveEnabled,
                'pushLink' => $pushLink,
                'viewLink' => $viewLink,
                'streamKeyDisplay' => $streamKeyDisplay,
                'rtmpUrl' => $rtmpUrl,
                'canStartMeeting' => $livestream->canStartMeeting(),
                'canGoLive' => $livestream->canGoLive()
                    && app(StreamingQueueService::class)->canStartNewStreamingJob((string) $organization->id, 'organization', $livestream->id),
                'latestInviteUrl' => $latestInviteUrl,
                'youtubeConnected' => ! empty($organization->youtube_refresh_token) || ! empty($organization->youtube_access_token),
                'streamingQueueStatus' => app(StreamingQueueService::class)->queueStatusForUi($latestStreamingJob, $livestream),
            ],
            'mediamtxEnabled' => $mediamtxEnabled,
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'youtubeChannelUrl' => $organization->youtube_channel_url,
            ],
        ]);
    }

    /**
     * Show the guest join page (public, by room name). Supports both org and supporter livestreams.
     */
    public function guestJoin(Request $request, string $roomName): Response
    {
        $orgStream = OrganizationLivestream::where('room_name', $roomName)
            ->whereIn('status', ['draft', 'scheduled', 'meeting_live', 'live', 'ended'])
            ->with('organization')
            ->first();

        if ($orgStream) {
            // Canvas mode (opt-in): allocate the next guest seat so this
            // participant publishes their cam to ls_<id>_s<seat> on MediaMTX
            // for the canvas mixer to WHEP-subscribe. No-op when canvas_mode
            // is off — getParticipantUrl(null) is the original single-host URL.
            $seat = $orgStream->isCanvasModeEnabled() ? $orgStream->allocateNextGuestSeat() : null;
            $participantUrl = $orgStream->getParticipantUrl($seat);
            $password = $orgStream->getDecryptedPassword();
            $settings = $orgStream->settings ?? [];
            $participantEmails = [];
            if (is_array($settings)) {
                $raw = $settings['participant_emails'] ?? $settings['participantEmails'] ?? [];
                if (is_array($raw)) {
                    $participantEmails = array_values(array_unique(array_filter(array_map('strval', $raw))));
                }
            }
            $orgSettingsJoin = is_array($orgStream->settings) ? $orgStream->settings : [];
            return Inertia::render('Organization/Livestreams/GuestJoin', [
                'livestream' => [
                    'id' => $orgStream->id,
                    'title' => $orgStream->title,
                    'description' => $orgStream->description,
                    'roomName' => $orgStream->room_name,
                    'roomPassword' => $password,
                    'participantUrl' => $participantUrl,
                    'status' => $orgStream->status,
                    'scheduledAt' => $orgStream->scheduled_at?->toIso8601String(),
                    'participantEmails' => $participantEmails,
                    'recordingEnabled' => MeetingRecordingPreference::isEnabled($orgSettingsJoin, true),
                    'declineContext' => [
                        'kind' => 'organization',
                        'id' => $orgStream->id,
                    ],
                    'broadcastChannel' => \App\Support\UnityLiveBroadcast::channelName($orgStream),
                ],
                'organization' => [
                    'id' => $orgStream->organization->id,
                    'name' => $orgStream->organization->name,
                ],
                'recordingDeclineReturnTo' => '/'.$request->path(),
            ]);
        }

        $userStream = UserLivestream::where('room_name', $roomName)
            ->whereIn('status', ['draft', 'scheduled', 'meeting_live', 'live', 'ended'])
            ->with('user')
            ->first();

        if ($userStream) {
            $seat = $userStream->isCanvasModeEnabled() ? $userStream->allocateNextGuestSeat() : null;
            $participantUrl = $userStream->getParticipantUrl($seat);
            $password = $userStream->getDecryptedPassword();
            $settings = $userStream->settings ?? [];
            $participantEmails = [];
            if (is_array($settings)) {
                $raw = $settings['participant_emails'] ?? $settings['participantEmails'] ?? [];
                if (is_array($raw)) {
                    $participantEmails = array_values(array_unique(array_filter(array_map('strval', $raw))));
                }
            }
            $userSettingsJoin = is_array($userStream->settings) ? $userStream->settings : [];
            return Inertia::render('Organization/Livestreams/GuestJoin', [
                'livestream' => [
                    'id' => $userStream->id,
                    'title' => $userStream->title,
                    'description' => $userStream->description,
                    'roomName' => $userStream->room_name,
                    'roomPassword' => $password,
                    'participantUrl' => $participantUrl,
                    'status' => $userStream->status,
                    'scheduledAt' => $userStream->scheduled_at?->toIso8601String(),
                    'participantEmails' => $participantEmails,
                    'recordingEnabled' => MeetingRecordingPreference::isEnabled($userSettingsJoin, false),
                    'declineContext' => [
                        'kind' => 'user',
                        'id' => $userStream->id,
                    ],
                    'broadcastChannel' => \App\Support\UnityLiveBroadcast::channelName($userStream),
                ],
                'organization' => [
                    'id' => 0,
                    'name' => $userStream->user?->name ?? 'Meeting',
                ],
                'recordingDeclineReturnTo' => '/'.$request->path(),
            ]);
        }

        abort(404, 'Meeting not found.');
    }

    /**
     * Generate a secure invite token and return the guest join URL (/join/{token}).
     */
    public function generateInviteToken(Request $request, $id)
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (! $organization) {
            return redirect()->back()->withErrors(['error' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        $token = LivestreamInviteToken::createForLivestream($livestream);
        $inviteUrl = url('/join/' . $token);

        if ($request->wantsJson()) {
            return response()->json(['inviteUrl' => $inviteUrl, 'token' => $token]);
        }

        return redirect()->back()->with('inviteUrl', $inviteUrl)->with('success', 'Invite link copied.');
    }

    /**
     * Guest join by secure token (public). Renders page with embedded VDO push iframe.
     */
    public function guestJoinByToken(Request $request, string $token): Response
    {
        $invite = LivestreamInviteToken::where('token', $token)
            ->with('organizationLivestream.organization')
            ->firstOrFail();

        $livestream = $invite->organizationLivestream;

        if (! in_array($livestream->status, ['draft', 'meeting_live', 'live'], true)) {
            return Inertia::render('Organization/Livestreams/GuestJoinExpired', [
                'title' => $livestream->title,
                'organizationName' => $livestream->organization?->name,
            ]);
        }

        // Per-invite-token join: allocate a canvas seat when canvas_mode is on
        // so this specific guest publishes into the mixer.
        $seat = $livestream->isCanvasModeEnabled() ? $livestream->allocateNextGuestSeat() : null;
        $participantUrl = $livestream->getParticipantUrl($seat);
        $hasPasscode = ! empty($livestream->getDecryptedPassword());
        $inviteSettings = is_array($livestream->settings) ? $livestream->settings : [];

        return Inertia::render('Organization/Livestreams/GuestJoinByToken', [
            'livestream' => [
                'id' => $livestream->id,
                'title' => $livestream->title,
                'roomName' => $livestream->room_name,
                'participantUrl' => $participantUrl,
                'status' => $livestream->status,
                'hasPasscode' => $hasPasscode,
                'recordingEnabled' => MeetingRecordingPreference::isEnabled($inviteSettings, true),
                'declineContext' => [
                    'kind' => 'organization',
                    'id' => $livestream->id,
                ],
                'broadcastChannel' => \App\Support\UnityLiveBroadcast::channelName($livestream),
            ],
            'organization' => [
                'id' => $livestream->organization->id,
                'name' => $livestream->organization->name,
            ],
            'recordingDeclineReturnTo' => '/'.$request->path(),
        ]);
    }

    /**
     * Start the meeting (status → meeting_live). Host and guests can join; stream not yet public.
     */
    public function startMeeting(Request $request, $id)
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (! $organization) {
            return redirect()->back()->withErrors(['error' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        if (! $livestream->canStartMeeting()) {
            return redirect()->back()->withErrors(['error' => 'Meeting cannot be started in current state.']);
        }

        $livestream->update(['status' => 'meeting_live']);

        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyMeetingStarted($livestream);

        return redirect()->back()->with('success', 'Meeting started. Invite guests, then click Go Live when ready to stream to viewers.');
    }

    /**
     * Update livestream status (start, end, etc.).
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:draft,scheduled,meeting_live,live,ended,cancelled',
        ]);

        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            return back()->withErrors(['error' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        $status = $request->status;
        $updates = ['status' => $status];

        if ($status === 'live' && !$livestream->started_at) {
            $updates['started_at'] = now();
        } elseif ($status === 'ended' && !$livestream->ended_at) {
            $updates['ended_at'] = now();
        }

        $livestream->update($updates);

        return back()->with('success', 'Meeting status updated successfully!');
    }

    /**
     * Transition YouTube broadcast to "live" (make stream public).
     * Host must have started OBS streaming first so YouTube receives the RTMP signal.
     */
    public function goLive(Request $request, $id)
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            return redirect()->back()->withErrors(['go_live' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        if (! $livestream->youtube_broadcast_id) {
            return redirect()->back()->withErrors(['go_live' => 'YouTube broadcast not configured']);
        }

        $youtubeService = app(YouTubeService::class);
        $accessToken = $youtubeService->getValidAccessToken($organization);
        if (! $accessToken) {
            return redirect()->back()->withErrors([
                'go_live' => 'YouTube token expired or invalid. Please reconnect YouTube in Integrations.',
            ]);
        }

        try {
            // Transition to "live" only works when YouTube is already receiving the stream (stream status = active).
            $status = $youtubeService->getBroadcastStreamStatus($accessToken, $livestream->youtube_broadcast_id);
            if (! $status['stream_active']) {
                $hint = $status['stream_status'] === 'inactive'
                    ? 'Start OBS and start streaming to YouTube (use the RTMP URL and stream key from this page). Wait until the stream is receiving data, then click Go Live again.'
                    : 'Start streaming from OBS to YouTube first. Wait until the stream shows as receiving data, then click Go Live again.';
                return redirect()->back()->withErrors(['go_live' => $hint]);
            }

            $ok = $youtubeService->updateBroadcastStatus(
                $accessToken,
                $livestream->youtube_broadcast_id,
                'live'
            );

            if (! $ok) {
                return redirect()->back()->withErrors([
                    'go_live' => 'YouTube could not go live. Please try again in a moment.',
                ]);
            }

            $livestream->update([
                'status' => 'live',
                'started_at' => $livestream->started_at ?? now(),
            ]);

            return redirect()->back()->with('success', 'You are now live on YouTube.');
        } catch (\Exception $e) {
            Log::error('Go live failed', ['livestream_id' => $id, 'error' => $e->getMessage()]);
            return redirect()->back()->withErrors(['go_live' => $e->getMessage()]);
        }
    }

    /**
     * Set livestream status to "live" in the database only (no OBS, no YouTube).
     * Stream will then appear on the Unity Live page.
     */
    public function setLive(Request $request, $id)
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (! $organization) {
            return redirect()->back()->withErrors(['error' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        if (! $livestream->canGoLive()) {
            return redirect()->back()->withErrors(['error' => 'Stream is not in a state that can go live. Start the meeting first if needed.']);
        }

        $livestream->update([
            'status' => 'live',
            'started_at' => now(),
        ]);

        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyLive($livestream);

        $message = $livestream->is_public
            ? 'Stream is now live. It will appear on the Unity Live page.'
            : 'Stream is now live (private). Share the viewer link so people can watch.';
        return redirect()->back()->with('success', $message);
    }

    /**
     * Go live from browser only (no OBS): start server bridge, transition YouTube, return publish URL.
     * Requires MediaMTX + FFmpeg on server and MEDIAMTX_PUBLISH_URL set.
     */
    public function goLiveBrowser(Request $request, $id)
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            return redirect()->back()->withErrors(['go_live' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        if (! $livestream->youtube_broadcast_id) {
            return redirect()->back()->withErrors(['go_live' => 'YouTube broadcast not configured']);
        }

        $bridge = app(StreamBridgeService::class)->startBridge($livestream);
        if (! $bridge) {
            return redirect()->back()->withErrors([
                'go_live' => 'Server stream bridge is not configured. Set MEDIAMTX_PUBLISH_URL and run MediaMTX + FFmpeg, or use OBS.',
            ]);
        }

        $youtubeService = app(YouTubeService::class);
        $accessToken = $youtubeService->getValidAccessToken($organization);
        if ($accessToken) {
            try {
                $youtubeService->updateBroadcastStatus($accessToken, $livestream->youtube_broadcast_id, 'live');
            } catch (\Exception $e) {
                Log::warning('Go live browser: YouTube transition failed', ['error' => $e->getMessage()]);
            }
        } else {
            Log::warning('Go live browser: YouTube token invalid, stream not transitioned on YouTube', ['livestream_id' => $id]);
        }

        $livestream->update([
            'status' => 'live',
            'started_at' => $livestream->started_at ?? now(),
        ]);

        return redirect()->back()
            ->with('success', 'A new window will open—allow camera/screen there to start streaming.')
            ->with('browser_publish_url', $bridge['publish_url']);
    }

    /**
     * Enqueue AWS streaming worker (SQS): pull meeting video from the host VDO URL and push RTMP to YouTube.
     * Legacy route "go-live-obs-auto" is an alias — this does not use OBS.
     */
    public function queueStreamRelayJob(Request $request, $id, StreamingQueueService $streamingQueue, StreamingPreflight $preflight, YouTubeService $youtubeService)
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (! $organization) {
            return redirect()->back()->withErrors(['go_live' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        if (! $livestream->canGoLive()) {
            return redirect()->back()->withErrors([
                'go_live' => 'Cannot queue the cloud stream in this state (status: '.$livestream->status.'). End any active stream first, or reset the meeting.',
            ]);
        }

        // Atomic guard against rapid double-clicks / parallel tabs / replayed
        // requests. Cache::lock serialises Go Live for this livestream across
        // PHP-FPM workers; inside it, we re-check for an already-in-flight
        // streaming_jobs row (queued/starting/live) and surface it instead of
        // queuing a duplicate Fargate worker. 30s TTL auto-releases if the
        // process dies mid-create. Lock acquisition failure is fail-OPEN
        // (logged) — better to occasionally double-fire than to break Go Live
        // entirely on a flaky lock backend.
        $lock = Cache::lock("livestream-golive-org-{$livestream->id}", 30);
        $acquired = false;
        try {
            $acquired = $lock->block(2);
        } catch (\Throwable $e) {
            Log::warning('Go Live lock acquisition failed; proceeding without lock', [
                'livestream_id' => $livestream->id, 'err' => $e->getMessage(),
            ]);
        }
        try {
            $blocked = $streamingQueue->goLiveBlockedReason((string) $organization->id, 'organization', $livestream->id);
            if ($blocked !== null) {
                return redirect()->back()->withErrors(['go_live' => $blocked]);
            }
            return $this->queueStreamRelayJobImpl($livestream, $user, $organization, $streamingQueue, $preflight, $youtubeService);
        } finally {
            if ($acquired) {
                try { $lock->release(); } catch (\Throwable $e) { /* harmless */ }
            }
        }
    }

    /**
     * Body of queueStreamRelayJob — wrapped by the atomic Cache::lock dupe
     * guard above. Kept in its own method so the lock + dupe check sits at the
     * top, separate from the (longer) job-build sequence.
     */
    private function queueStreamRelayJobImpl(OrganizationLivestream $livestream, $user, $organization, StreamingQueueService $streamingQueue, StreamingPreflight $preflight, YouTubeService $youtubeService)
    {
        $gate = $preflight->check($livestream, $user);
        if (! $gate->allowed) {
            return redirect()->back()->withErrors(['go_live' => $gate->reason]);
        }

        $blocked = $streamingQueue->goLiveBlockedReason((string) $organization->id, 'organization', $livestream->id);
        if ($blocked !== null) {
            return redirect()->back()->withErrors(['go_live' => $blocked]);
        }

        // Auto-resolve a current YouTube stream key. Reuses existing broadcast (by id, then by title)
        // before creating a new one so multiple meetings under the same name share one YouTube live.
        $streamKey = $this->ensureFreshYouTubeStreamKey($organization, $livestream, $youtubeService);
        if ($streamKey === null) {
            return redirect()->back()->withErrors([
                'go_live' => 'No YouTube stream is ready. Click "Prepare YouTube Live" first, or connect YouTube under Integrations.',
            ]);
        }

        if ($streamingQueue->willUseRealSqs() && ! StreamingWorkerSourceUrl::hasWorkerIngestConfigured()) {
            return redirect()->back()->withErrors([
                'go_live' => 'Cloud relay source is not configured. Set MEDIAMTX_RTMP_PUBLIC or STREAMING_WORKER_RTMP_PULL_BASE (or STREAMING_WORKER_SOURCE_URL_TEMPLATE), then retry.',
            ]);
        }

        $meetingId = (string) $livestream->id;
        $organizationId = (string) $organization->id;
        $sourceUrl = StreamingWorkerSourceUrl::resolve($livestream);
        $destinationUrl = 'rtmp://a.rtmp.youtube.com/live2/'.$streamKey;
        $callbackUrl = streaming_status_callback_url();
        $maxDurationMinutes = (int) config('streaming.max_duration_minutes', 120);

        $job = StreamingJob::create([
            'livestream_kind' => 'organization',
            'livestream_id' => $livestream->id,
            'meeting_id' => $meetingId,
            'organization_id' => $organizationId,
            'source_url' => $sourceUrl,
            'destination_url' => $destinationUrl,
            'callback_url' => $callbackUrl,
            'max_duration_minutes' => $maxDurationMinutes,
            'status' => 'queued',
        ]);

        try {
            $streamingQueue->enqueue($job, [
                'meeting_id' => $meetingId,
                'organization_id' => $organizationId,
                'source_url' => $sourceUrl,
                'destination_url' => $destinationUrl,
                'callback_url' => $callbackUrl,
                'max_duration_minutes' => $maxDurationMinutes,
            ]);
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            return redirect()->back()->withErrors(['go_live' => 'Could not queue the cloud stream job. Check AWS/SQS configuration and try again.']);
        }

        $livestream->update(['status' => 'meeting_live']);

        return redirect()->back()->with(
            'success',
            'Cloud stream queued (AWS). Keep this meeting open as host; status will move to live when the worker connects.'
        );
    }

    /**
     * End stream: tell YouTube to complete the broadcast (and optional local OBS via frontend).
     * Livestream status returns to draft when the AWS worker reports completed/stopped; a new YouTube
     * broadcast is provisioned then so this meeting can go live again.
     */
    public function endStream(Request $request, $id, StreamingQueueService $streamingQueue)
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (! $organization) {
            return redirect()->back()->withErrors(['error' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        // Accept any active state, not just 'live'. A stream that failed (e.g.
        // bridge cold) gets reset to 'meeting_live'; the user must still be able
        // to end/reset it. Only reject genuinely-inactive states.
        if (! in_array($livestream->status, ['live', 'meeting_live', 'starting'], true)) {
            return redirect()->back()->withErrors(['error' => 'Stream is not active.']);
        }

        // Mark stop intent BEFORE the YouTube calls. The AWS worker polls the
        // callback endpoint every ~10s and shuts itself down once it sees this.
        // Without it, "End Stream" never reached the worker (Laravel has no way
        // to signal the task) so the relay ran until the browser tab closed.
        $settings = $livestream->settings ?? [];
        $settings['stream_stop_requested'] = now()->toIso8601String();
        $livestream->update(['settings' => $settings]);
        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notify(
            $livestream,
            'stream_ended',
            'The host has ended the stream. Playback may stop in a few seconds.',
        );

        $youtubeService = app(YouTubeService::class);
        $accessToken = $youtubeService->getValidAccessToken($organization);

        // Stop the current broadcast on YouTube (OBS is stopped on frontend via stopOBSStream).
        // Meeting status returns to draft when the AWS worker callback reports completed/stopped
        // (or immediately in STREAMING_SIMULATE_WORKER mode). A fresh YouTube broadcast is created then.
        if (! empty($livestream->youtube_broadcast_id) && $accessToken) {
            try {
                $endedOnYoutube = $youtubeService->updateBroadcastStatus(
                    $accessToken,
                    $livestream->youtube_broadcast_id,
                    'complete'
                );
                if (! $endedOnYoutube) {
                    return redirect()->back()->withErrors([
                        'error' => 'YouTube did not confirm the broadcast ended. Try again or end it from YouTube Studio.',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('End stream: YouTube complete failed', ['livestream_id' => $id, 'error' => $e->getMessage()]);

                return redirect()->back()->withErrors([
                    'error' => 'Could not end the YouTube broadcast. Try again from YouTube Studio, or wait and refresh.',
                ]);
            }

            $settledLocally = $streamingQueue->finalizeAfterHostEndStream('organization', $livestream->id);

            // Reset locally too. The worker (if running) stops within ~10s via
            // the stop_requested heartbeat and its 'stopped' callback re-confirms
            // draft (idempotent). If the stream had already failed and no worker
            // is running, this is the only thing that frees the user from a
            // stuck meeting_live/live state.
            $livestream->update([
                'status' => 'draft',
                'ended_at' => $livestream->ended_at ?? now(),
            ]);

            $livestream->refresh();
            \App\Support\UnityLiveBroadcast::notifyStreamEnded($livestream);

            return redirect()->back()->with(
                'success',
                $settledLocally
                    ? 'YouTube live ended. The meeting is ready — you can go live again when you want.'
                    : 'Ending stream — YouTube was told to stop and the relay is shutting down (usually within ~10s).'
            );
        }

        $streamingQueue->finalizeAfterHostEndStream('organization', $livestream->id);

        $livestream->update([
            'status' => 'draft',
            'ended_at' => $livestream->ended_at ?? now(),
        ]);

        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyStreamEnded($livestream);

        return redirect()->back()->with('success', 'Stream stopped. You can go live again from the same link when ready.');
    }

    /**
     * Host closed the meeting tab or navigated away — stop stream without a full form POST.
     */
    public function abandonHostSession(
        Request $request,
        $id,
        YouTubeService $youtubeService,
        LivestreamHostAbandonService $abandonService,
    ): JsonResponse {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (! $organization) {
            return response()->json(['ok' => false], 403);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        $accessToken = $youtubeService->getValidAccessToken($organization);
        $abandonService->abandonOrganizationStream($livestream, $accessToken, $youtubeService);

        return response()->json(['ok' => true]);
    }

    /**
     * Create a YouTube live broadcast on the organization's connected channel when no stream key exists yet.
     */
    public function prepareYouTubeLive(Request $request, int $id, YouTubeService $youtubeService): RedirectResponse
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (! $organization) {
            return redirect()->back()->withErrors(['youtube' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        if (empty($organization->youtube_access_token) && empty($organization->youtube_refresh_token)) {
            return redirect()->back()->withErrors(['youtube' => 'Connect YouTube in Integrations first, then try again.']);
        }

        $accessToken = $youtubeService->getValidAccessToken($organization);
        if (! $accessToken) {
            return redirect()->back()->withErrors(['youtube' => 'YouTube connection expired. Reconnect in Integrations.']);
        }

        $title = $livestream->title ?: 'Unity Meet - ' . $organization->name;

        // 1. Already-bound broadcast on this row → refresh its key from YouTube (handles stale keys).
        if (! empty($livestream->youtube_broadcast_id)) {
            $existing = $youtubeService->getBroadcastStreamKey($accessToken, $livestream->youtube_broadcast_id);
            if ($existing && ! empty($existing['stream_key'])) {
                $this->persistOrgYoutubeKey($livestream, $livestream->youtube_broadcast_id, $existing['stream_key'], $existing['rtmp_url'] ?? null);
                return redirect()->back()->with(
                    'success',
                    'YouTube live already created — existing broadcast and stream key reused.'
                );
            }
            Log::warning('YouTube broadcast missing on remote (org), falling back to title lookup / create', [
                'livestream_id' => $livestream->id,
                'previous_broadcast_id' => $livestream->youtube_broadcast_id,
            ]);
        }

        // 2. Reuse a non-completed broadcast on the channel with the same title (avoids duplicate
        //    YouTube lives when an org creates multiple meetings under the same name).
        $existingByTitle = $youtubeService->findBroadcastStreamKeyByTitle($accessToken, $title);
        if ($existingByTitle && ! empty($existingByTitle['stream_key'])) {
            $this->persistOrgYoutubeKey($livestream, $existingByTitle['broadcast_id'], $existingByTitle['stream_key'], $existingByTitle['rtmp_url'] ?? null);
            return redirect()->back()->with(
                'success',
                'YouTube live with this name already exists — existing broadcast and stream key reused.'
            );
        }

        // 3. No existing broadcast — create a fresh one.
        $scheduledStart = $livestream->scheduled_at && $livestream->scheduled_at->isFuture()
            ? $livestream->scheduled_at
            : null;

        $broadcastData = $youtubeService->createLiveBroadcast(
            $accessToken,
            $title,
            $livestream->description,
            $scheduledStart
        );

        if (! $broadcastData) {
            return redirect()->back()->withErrors([
                'youtube' => 'Could not create a YouTube live broadcast. Try again or add a stream key manually.',
            ]);
        }

        $this->persistOrgYoutubeKey($livestream, $broadcastData['broadcast_id'], $broadcastData['stream_key'], $broadcastData['rtmp_url'] ?? null);

        return redirect()->back()->with(
            'success',
            'YouTube live is ready on your connected channel. Your stream key was saved — click Go Live when you are ready.'
        );
    }

    private function persistOrgYoutubeKey(OrganizationLivestream $livestream, string $broadcastId, string $streamKey, ?string $rtmpUrl): void
    {
        $settings = $livestream->settings ?? [];
        if (! empty($rtmpUrl)) {
            $settings['rtmp_url'] = $rtmpUrl;
        }
        $livestream->update([
            'youtube_broadcast_id' => $broadcastId,
            'youtube_stream_key' => Crypt::encryptString($streamKey),
            'settings' => ! empty($settings) ? $settings : null,
        ]);
    }

    /**
     * Make sure the org livestream has a usable YouTube stream key right now.
     * Mirrors the supporter helper: refresh existing broadcast key, else reuse-by-title, else create.
     */
    private function ensureFreshYouTubeStreamKey(Organization $organization, OrganizationLivestream $livestream, YouTubeService $youtubeService): ?string
    {
        if (empty($organization->youtube_access_token) && empty($organization->youtube_refresh_token)) {
            $manual = $livestream->getDecryptedStreamKey();
            return $manual !== '' ? $manual : null;
        }

        $accessToken = $youtubeService->getValidAccessToken($organization);
        if (! $accessToken) {
            $manual = $livestream->getDecryptedStreamKey();
            return $manual !== '' ? $manual : null;
        }

        $title = $livestream->title ?: 'Unity Meet - ' . $organization->name;

        if (! empty($livestream->youtube_broadcast_id)) {
            $existing = $youtubeService->getBroadcastStreamKey($accessToken, $livestream->youtube_broadcast_id);
            if ($existing && ! empty($existing['stream_key'])) {
                $this->persistOrgYoutubeKey($livestream, $livestream->youtube_broadcast_id, $existing['stream_key'], $existing['rtmp_url'] ?? null);
                return $existing['stream_key'];
            }
            Log::warning('YouTube broadcast missing on remote at Go Live (org); will look up by title or create a new one', [
                'livestream_id' => $livestream->id,
                'previous_broadcast_id' => $livestream->youtube_broadcast_id,
            ]);
        }

        $byTitle = $youtubeService->findBroadcastStreamKeyByTitle($accessToken, $title);
        if ($byTitle && ! empty($byTitle['stream_key'])) {
            $this->persistOrgYoutubeKey($livestream, $byTitle['broadcast_id'], $byTitle['stream_key'], $byTitle['rtmp_url'] ?? null);
            return $byTitle['stream_key'];
        }

        $scheduledStart = $livestream->scheduled_at && $livestream->scheduled_at->isFuture()
            ? $livestream->scheduled_at
            : null;

        $created = $youtubeService->createLiveBroadcast(
            $accessToken,
            $title,
            $livestream->description,
            $scheduledStart
        );
        if ($created && ! empty($created['stream_key'])) {
            $this->persistOrgYoutubeKey($livestream, $created['broadcast_id'], $created['stream_key'], $created['rtmp_url'] ?? null);
            return $created['stream_key'];
        }

        $manual = $livestream->getDecryptedStreamKey();
        return $manual !== '' ? $manual : null;
    }

    /**
     * Update YouTube stream key.
     */
    public function updateStreamKey(Request $request, $id)
    {
        $request->validate([
            'youtube_stream_key' => 'required|string',
        ]);

        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            return back()->withErrors(['error' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        $encryptedStreamKey = Crypt::encryptString($request->youtube_stream_key);

        $livestream->update([
            'youtube_stream_key' => $encryptedStreamKey,
        ]);

        return back()->with('success', 'YouTube stream key updated successfully!');
    }

    /**
     * Update livestream visibility (public = listed on Unity Live; private = only via direct link).
     */
    public function updateVisibility(Request $request, $id)
    {
        $request->validate([
            'is_public' => 'required|boolean',
        ]);

        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (! $organization) {
            return back()->withErrors(['error' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        $livestream->update(['is_public' => $request->boolean('is_public')]);

        return back()->with('success', $livestream->is_public
            ? 'Stream is now public. It will appear on the Unity Live page when live.'
            : 'Stream is now private. Only people with the viewer link can watch.');
    }

    /**
     * List all livestreams for the organization.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $livestreams = OrganizationLivestream::where('organization_id', $organization->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->through(function ($livestream) {
                return [
                    'id' => $livestream->id,
                    'title' => $livestream->title,
                    'roomName' => $livestream->room_name,
                    'status' => $livestream->status,
                    'isPublic' => (bool) $livestream->is_public,
                    'scheduledAt' => $livestream->scheduled_at?->toIso8601String(),
                    'startedAt' => $livestream->started_at?->toIso8601String(),
                    'endedAt' => $livestream->ended_at?->toIso8601String(),
                    'createdAt' => $livestream->created_at->toIso8601String(),
                    'directorUrl' => $livestream->getDirectorUrl(),
                ];
            });

        return Inertia::render('Organization/Livestreams/Index', [
            'livestreams' => $livestreams,
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
        ]);
    }

    /**
     * Delete a livestream.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();

        if (!$organization) {
            return back()->withErrors(['error' => 'Organization not found']);
        }

        $livestream = OrganizationLivestream::where('organization_id', $organization->id)
            ->findOrFail($id);

        // Only allow deletion if stream is not live
        if ($livestream->status === 'live') {
            return back()->withErrors(['error' => 'Cannot delete a live stream. Please end it first.']);
        }

        // Delete the YouTube broadcast if this livestream had one
        if (! empty($livestream->youtube_broadcast_id)) {
            try {
                $youtubeService = app(YouTubeService::class);
                $accessToken = $youtubeService->getValidAccessToken($organization);
                if ($accessToken) {
                    $youtubeService->deleteBroadcast($accessToken, $livestream->youtube_broadcast_id);
                }
            } catch (\Exception $e) {
                Log::warning('YouTube broadcast deletion failed during livestream delete', [
                    'livestream_id' => $id,
                    'broadcast_id' => $livestream->youtube_broadcast_id,
                    'error' => $e->getMessage(),
                ]);
                // Continue to delete the livestream locally
            }
        }

        $livestream->delete();

        return redirect()->route('organization.livestreams.index')
            ->with('success', 'Meeting deleted successfully!');
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\SendUnityMeetBiuNotification;
use App\Jobs\SendUnityMeetInvitationEmail;
use App\Models\GiftOccasion;
use App\Models\LivestreamRecordingDecline;
use App\Models\Organization;
use App\Models\OrganizationLivestream;
use App\Models\StreamingJob;
use App\Models\User;
use App\Models\UserLivestream;
use App\Services\Streaming\StreamingPreflight;
use App\Services\Streaming\StreamingQueueService;
use App\Support\EmailPackageCatalog;
use App\Support\LivestreamParticipantEmails;
use App\Support\LivestreamParticipantRoster;
use App\Support\MeetingRecordingPreference;
use App\Support\UnityMeetInviteNotifyVia;
use App\Support\UnityMeetInviteRecipients;
use App\Support\UserEmailCredits;
use App\Support\StreamingWorkerSourceUrl;
use App\Services\ConnectionHubUnityMeetListing;
use App\Services\TimezoneService;
use App\Services\DropboxOAuthService;
use App\Services\UnityMeetBiuNotifier;
use App\Services\DropboxOrgApi;
use App\Services\LivestreamHostAbandonService;
use App\Services\RecordingYoutubePublishService;
use App\Services\YouTubeService;
use App\Services\LivestreamVideoOverlayService;
use App\Support\LivestreamOverlayConfig;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supporter livestream — same flow as organization livestreams (VDO.Ninja): index, create, edit, delete, room page.
 */
class SupporterLivestreamController extends Controller
{
    private function getUnityMeetingId(Request $request): string
    {
        $user = $request->user();
        $slugPart = trim((string) ($user->slug ?? ''));
        if ($slugPart === '') {
            $slugPart = Str::slug((string) $user->name) ?: 'supporter';
        }
        return 'uni-'.$slugPart.'-'.$user->id;
    }

    /**
     * Clear YouTube credentials on a meeting row (e.g. before a fresh broadcast on that meeting).
     */
    private function resetYoutubeForFreshMeetingSession(UserLivestream $livestream): void
    {
        $livestream->youtube_stream_key = null;
        $livestream->youtube_broadcast_id = null;
        $livestream->started_at = null;
        $livestream->ended_at = null;

        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        unset($settings['rtmp_url']);
        $livestream->settings = $settings !== [] ? $settings : null;
    }

    public function index(Request $request): Response
    {
        $meetingsView = $request->query('view') === 'meetings';
        $meetingsTab = (string) $request->query('tab', 'upcoming');
        if (! in_array($meetingsTab, ['upcoming', 'past'], true)) {
            $meetingsTab = 'upcoming';
        }

        $query = UserLivestream::where('user_id', $request->user()->id);

        if ($meetingsView) {
            if ($meetingsTab === 'past') {
                $query->where(function ($q) {
                    $q->whereIn('status', ['ended', 'cancelled'])
                        ->orWhere(function ($q2) {
                            $q2->where('status', 'scheduled')
                                ->whereNotNull('scheduled_at')
                                ->where('scheduled_at', '<', now());
                        });
                })
                    ->orderByDesc('ended_at')
                    ->orderByDesc('scheduled_at')
                    ->orderByDesc('created_at');
            } else {
                $query->where(function ($q) {
                    $q->whereIn('status', ['draft', 'meeting_live', 'live'])
                        ->orWhere(function ($q2) {
                            $q2->where('status', 'scheduled')
                                ->where(function ($q3) {
                                    $q3->whereNull('scheduled_at')
                                        ->orWhere('scheduled_at', '>=', now());
                                });
                        });
                })
                    ->orderByRaw('CASE WHEN scheduled_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('scheduled_at', 'asc')
                    ->orderByDesc('created_at');
            }
        } else {
            $query->orderByDesc('created_at');
        }

        $livestreams = $query
            ->paginate(20)
            ->through(fn (UserLivestream $livestream) => $this->mapPersonalLivestreamRow($livestream));

        $connectionHubMeetings = ConnectionHubUnityMeetListing::forUser(
            $request->user(),
            $meetingsView ? $meetingsTab : null,
        )->values()->all();

        return Inertia::render('frontend/livestreams/Index', [
            'livestreams' => $livestreams,
            'connectionHubMeetings' => $connectionHubMeetings,
            'meetingsView' => $meetingsView,
            'meetingsTab' => $meetingsView ? $meetingsTab : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPersonalLivestreamRow(UserLivestream $livestream): array
    {
        return [
            'id' => $livestream->id,
            'listKey' => 'user-'.$livestream->id,
            'source' => 'personal',
            'title' => $livestream->title,
            'roomName' => $livestream->room_name,
            'status' => $livestream->status,
            'isPublic' => (bool) $livestream->is_public,
            'scheduledAt' => $livestream->scheduled_at?->toIso8601String(),
            'startedAt' => $livestream->started_at?->toIso8601String(),
            'endedAt' => $livestream->ended_at?->toIso8601String(),
            'createdAt' => $livestream->created_at->toIso8601String(),
            'directorUrl' => $livestream->getDirectorUrl(false),
            'joinUrl' => \App\Support\UnityMeetUrls::guestJoinUrl($livestream->room_name),
            'hostUrl' => route('livestreams.supporter.show', $livestream->id),
        ];
    }

    /**
     * Public Unity Live streams that are live right now.
     */
    public function live(Request $request): Response
    {
        return Inertia::render('frontend/livestreams/Live', [
            'meetingsUrl' => route('livestreams.supporter.index'),
            'createMeetingUrl' => route('livestreams.supporter.create'),
            'unityMeetHomeUrl' => route('livestreams.supporter.index'),
            'publicLivestreams' => $this->buildPublicUnityLivestreamList(),
        ]);
    }

    /**
     * YouTube, Dropbox, and meeting shortcuts (moved from Live page).
     */
    public function settings(Request $request): Response
    {
        return Inertia::render('frontend/livestreams/Settings', $this->unityMeetSetupProps($request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    private function unityMeetSetupProps(User $user): array
    {
        $user->loadMissing('organization');
        $organization = Organization::forAuthUser($user);

        $youtubeService = app(YouTubeService::class);

        if ($organization) {
            $youtubeConnected = ! empty($organization->youtube_refresh_token) || ! empty($organization->youtube_access_token);
            $youtubeChannelUrl = $organization->youtube_channel_url;
            $dropboxConnected = ! empty($organization->dropbox_refresh_token);
            $youtubeManageUrl = route('integrations.youtube');
            $youtubeCanUpload = $youtubeConnected && $youtubeService->organizationCanUploadVideos($organization);
        } else {
            $youtubeConnected = ! empty($user->youtube_refresh_token) || ! empty($user->youtube_access_token);
            $youtubeChannelUrl = $user->youtube_channel_url;
            $dropboxConnected = ! empty($user->dropbox_refresh_token);
            $youtubeManageUrl = route('integrations.youtube.connect');
            $youtubeCanUpload = $youtubeConnected && $youtubeService->userCanUploadVideos($user);
        }

        return [
            'youtubeConnected' => $youtubeConnected,
            'youtubeCanUpload' => $youtubeCanUpload,
            'youtubeReconnectUrl' => route('integrations.youtube.redirect'),
            'youtubeChannelUrl' => $youtubeChannelUrl ? (string) $youtubeChannelUrl : null,
            'dropboxConnected' => $dropboxConnected,
            'youtubeManageUrl' => $youtubeManageUrl,
            'dropboxUrl' => route('livestreams.supporter.recordings'),
            'meetingsUrl' => route('livestreams.supporter.index'),
            'createMeetingUrl' => route('livestreams.supporter.create'),
        ];
    }

    /**
     * Same discovery list as {@see UnityLiveController::index()}: all org + supporter streams that are public and live.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildPublicUnityLivestreamList(): array
    {
        $orgStreams = OrganizationLivestream::query()
            ->where('status', 'live')
            ->where('is_public', true)
            ->with('organization:id,name')
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (OrganizationLivestream $ls) => $this->toPublicUnityLiveOrgItem($ls))
            ->filter()
            ->values()
            ->all();

        $userStreams = UserLivestream::query()
            ->where('status', 'live')
            ->where('is_public', true)
            ->with('user:id,name')
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (UserLivestream $ls) => $this->toPublicUnityLiveUserItem($ls))
            ->filter()
            ->values()
            ->all();

        return collect(array_merge($orgStreams, $userStreams))
            ->sortByDesc(fn ($s) => $s['startedAt'] ?? '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toPublicUnityLiveOrgItem(OrganizationLivestream $ls): ?array
    {
        $viewUrl = $ls->getPublicViewUrl();
        if (! $viewUrl) {
            return null;
        }

        return [
            'id' => 'org_'.$ls->id,
            'slug' => $ls->room_name,
            'title' => $ls->title ?: 'Live Stream',
            'organizationName' => $ls->organization?->name ?? 'Organization',
            'viewUrl' => $viewUrl,
            'viewUrlMuted' => $ls->getPublicViewUrlMuted(),
            'viewUrlFallback' => $ls->getPublicViewUrlFallback(),
            'startedAt' => $ls->started_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toPublicUnityLiveUserItem(UserLivestream $ls): ?array
    {
        $viewUrl = $ls->getPublicViewUrl();
        if (! $viewUrl) {
            return null;
        }

        return [
            'id' => 'user_'.$ls->id,
            'slug' => $ls->room_name,
            'title' => $ls->title ?: 'Live Stream',
            'organizationName' => $ls->user?->name ?? 'Host',
            'viewUrl' => $viewUrl,
            'viewUrlMuted' => $ls->getPublicViewUrlMuted(),
            'viewUrlFallback' => $ls->getPublicViewUrlFallback(),
            'startedAt' => $ls->started_at?->toIso8601String(),
        ];
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $organization = $user->organization ?? Organization::where('user_id', $user->id)->first();
        $defaultDisplayName = $organization?->name ?: ($user->name ?? '');
        return Inertia::render('frontend/livestreams/Create', [
            'authUserDisplayName' => $defaultDisplayName,
            'emailCredits' => UserEmailCredits::stats($user),
            ...$this->emailPurchaseProps(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'display_name' => 'nullable|string|max:255',
            'is_public' => 'nullable|boolean',
            'require_passcode' => 'nullable|boolean',
            'passcode' => [
                'nullable',
                'string',
                'max:100',
                function (string $_attribute, mixed $value, \Closure $fail) use ($request): void {
                    if (! $request->boolean('require_passcode')) {
                        return;
                    }
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (strlen((string) $value) < 6) {
                        $fail(__('Passcode must be at least 6 characters.'));
                    }
                },
            ],
            'record_meeting' => 'nullable|boolean',
            'go_live' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $requirePasscode = $request->boolean('require_passcode');
        $password = $requirePasscode
            ? (string) ($request->input('passcode') ?: UserLivestream::generatePassword())
            : '';
        $encryptedPassword = Crypt::encryptString($password);

        $displayName = $request->filled('display_name') ? $request->display_name : ($user->name ?? null);
        $settings = [];
        if ($displayName !== null && $displayName !== '') {
            $settings['display_name'] = $displayName;
        }
        $settings['record_meeting'] = $request->boolean('record_meeting');
        $settings['require_passcode'] = $requirePasscode;
        $settings['go_live'] = $request->boolean('go_live');

        $livestream = UserLivestream::create([
            'user_id' => $user->id,
            'room_name' => UserLivestream::generateRoomName(),
            'room_password' => $encryptedPassword,
            'status' => 'draft',
            'is_public' => $request->boolean('is_public'),
            'title' => $request->title,
            'settings' => $settings ?: null,
            'scheduled_at' => null,
        ]);

        return redirect()->route('livestreams.supporter.ready', $livestream->id)
            ->with('success', 'Meeting ready!');
    }

    /**
     * Schedule a meeting (supporter flow): saves scheduled_at and participant email.
     */
    public function schedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'display_name' => 'nullable|string|max:255',
            'is_public' => 'nullable|boolean',
            'require_passcode' => 'nullable|boolean',
            'passcode' => [
                'nullable',
                'string',
                'max:100',
                function (string $_attribute, mixed $value, \Closure $fail) use ($request): void {
                    if (! $request->boolean('require_passcode')) {
                        return;
                    }
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (strlen((string) $value) < 6) {
                        $fail(__('Passcode must be at least 6 characters.'));
                    }
                },
            ],
            'record_meeting' => 'nullable|boolean',
            'schedule_date' => 'required|date_format:Y-m-d',
            'schedule_time' => 'required|date_format:H:i',
            // Accept multiple participants (tagify-like): send as array of emails.
            'participant_emails' => 'required|array|min:1|max:50',
            'participant_emails.*' => 'required|email|max:255',
            // Backward compatibility for older clients sending a single email string.
            'participant_email' => 'nullable|email|max:255',
            'invite_notify_via' => 'nullable|string|in:email,biu,both',
        ]);

        $user = $request->user();
        $notifyVia = UnityMeetInviteNotifyVia::normalize($request->input('invite_notify_via'));

        $scheduledAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['schedule_date'].' '.$validated['schedule_time'],
            TimezoneService::requestTimezone($request)
        );
        if (! $scheduledAt || $scheduledAt->isPast()) {
            return back()->withErrors(['schedule_date' => 'Schedule time must be in the future.'])->withInput();
        }

        $requirePasscode = $request->boolean('require_passcode');
        $password = $requirePasscode
            ? (string) ($request->input('passcode') ?: UserLivestream::generatePassword())
            : '';
        $encryptedPassword = Crypt::encryptString($password);

        $displayName = $request->filled('display_name') ? (string) $request->display_name : ($user->name ?? null);
        $settings = [];
        if ($displayName !== null && $displayName !== '') {
            $settings['display_name'] = $displayName;
        }
        $settings['record_meeting'] = $request->boolean('record_meeting');
        $settings['require_passcode'] = $requirePasscode;
        $emails = $validated['participant_emails'] ?? [];
        if (empty($emails) && ! empty($validated['participant_email'])) {
            $emails = [(string) $validated['participant_email']];
        }
        $emails = array_values(array_unique(array_filter(array_map('strval', $emails))));
        $settings['participant_emails'] = $emails;

        if ($emails !== [] && UnityMeetInviteNotifyVia::usesEmailCredits($notifyVia) && ! UserEmailCredits::canSend($user, count($emails))) {
            return back()
                ->withErrors(['participant_emails' => UserEmailCredits::insufficientMessage($user, count($emails))])
                ->withInput();
        }

        if ($emails !== [] && $notifyVia === UnityMeetInviteNotifyVia::BIU) {
            $missingBiu = array_values(array_filter($emails, fn (string $addr) => $this->userForInviteEmail($addr) === null));
            if ($missingBiu !== []) {
                return back()
                    ->withErrors([
                        'participant_emails' => 'BIU notifications require a registered Believe In Unity account: '.implode(', ', array_slice($missingBiu, 0, 3)).(count($missingBiu) > 3 ? '…' : ''),
                    ])
                    ->withInput();
            }
        }

        $livestream = UserLivestream::create([
            'user_id' => $user->id,
            'room_name' => UserLivestream::generateRoomName(),
            'room_password' => $encryptedPassword,
            'status' => 'scheduled',
            'is_public' => $request->boolean('is_public'),
            'title' => $request->input('title'),
            'scheduled_at' => $scheduledAt,
            'settings' => $settings ?: null,
        ]);

        $this->sendScheduledMeetingInvitations($livestream, $notifyVia);

        return redirect()->route('livestreams.supporter.ready', $livestream->id)
            ->with('success', count($emails) > 0
                ? $this->scheduledInviteSuccessMessage($notifyVia, count($emails))
                : 'Meeting scheduled!');
    }

    /**
     * Show the "Join a meeting" page — enter meeting ID and passcode (no link).
     */
    public function joinPage(Request $request): Response
    {
        $errors = $request->session()->get('errors');
        $errorBag = $errors ? $errors->getBag('default')->getMessages() : [];

        return Inertia::render('frontend/livestreams/Join', [
            'oldMeetingId' => $request->old('meeting_id') ?? $request->query('meeting_id'),
            'errors' => $errorBag,
            'requiresPasscodeStep' => (bool) $request->session()->pull('join_requires_passcode', false),
            'pendingMeetingTitle' => $request->session()->pull('join_pending_title'),
            'connectionHubMeetings' => ConnectionHubUnityMeetListing::forUser($request->user(), 'upcoming')->values()->all(),
        ]);
    }

    /**
     * Join with meeting ID + passcode. Validates passcode then renders the guest join page.
     */
    public function joinWithPasscode(Request $request): Response|RedirectResponse
    {
        $request->validate([
            'meeting_id' => 'required|string|max:100',
            'passcode' => 'nullable|string|max:100',
        ]);

        $roomName = trim($request->input('meeting_id'));
        $passcode = (string) $request->input('passcode', '');

        $joinDisplayName = trim((string) ($request->user()->name ?? '')) !== ''
            ? trim((string) $request->user()->name)
            : (trim((string) ($request->user()->email ?? '')) ?: 'Guest');

        $orgStream = OrganizationLivestream::where('room_name', $roomName)
            ->whereIn('status', ['draft', 'scheduled', 'meeting_live', 'live', 'ended'])
            ->with('organization')
            ->first();

        if ($orgStream) {
            $password = $orgStream->getDecryptedPassword();
            if ($orgStream->requiresPasscode()) {
                if ($passcode === '') {
                    return redirect()->route('livestreams.supporter.join')
                        ->withInput($request->only('meeting_id'))
                        ->with([
                            'join_requires_passcode' => true,
                            'join_pending_title' => $orgStream->title,
                        ]);
                }
                if ($password !== $passcode) {
                    return redirect()->route('livestreams.supporter.join')
                        ->withInput($request->only('meeting_id'))
                        ->with([
                            'join_requires_passcode' => true,
                            'join_pending_title' => $orgStream->title,
                        ])
                        ->withErrors(['passcode' => 'Invalid passcode. Try again.']);
                }
            }
            // Canvas-mode seat allocation (no-op when canvas_mode is off).
            $seat = $orgStream->isCanvasModeEnabled() ? $orgStream->allocateNextGuestSeat() : null;
            $participantUrl = $orgStream->getParticipantUrl($seat);
            $settings = is_array($orgStream->settings) ? $orgStream->settings : [];
            return Inertia::render('frontend/livestreams/Join', [
                'livestream' => [
                    'id' => $orgStream->id,
                    'title' => $orgStream->title,
                    'description' => $orgStream->description,
                    'roomName' => $orgStream->room_name,
                    'roomPassword' => $password,
                    'participantUrl' => $participantUrl,
                    'status' => $orgStream->status,
                    'recordingEnabled' => MeetingRecordingPreference::isEnabled($settings, true),
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
                'joinDisplayName' => $joinDisplayName,
            ]);
        }

        $userStream = UserLivestream::where('room_name', $roomName)
            ->whereIn('status', ['draft', 'scheduled', 'meeting_live', 'live', 'ended'])
            ->with('user')
            ->first();

        if ($userStream) {
            $password = $userStream->getDecryptedPassword();
            if ($userStream->requiresPasscode()) {
                if ($passcode === '') {
                    return redirect()->route('livestreams.supporter.join')
                        ->withInput($request->only('meeting_id'))
                        ->with([
                            'join_requires_passcode' => true,
                            'join_pending_title' => $userStream->title,
                        ]);
                }
                if ($password !== $passcode) {
                    return redirect()->route('livestreams.supporter.join')
                        ->withInput($request->only('meeting_id'))
                        ->with([
                            'join_requires_passcode' => true,
                            'join_pending_title' => $userStream->title,
                        ])
                        ->withErrors(['passcode' => 'Invalid passcode. Try again.']);
                }
            }
            $seat = $userStream->isCanvasModeEnabled() ? $userStream->allocateNextGuestSeat() : null;
            $participantUrl = $userStream->getParticipantUrl($seat);
            $userSettings = is_array($userStream->settings) ? $userStream->settings : [];
            return Inertia::render('frontend/livestreams/Join', [
                'livestream' => [
                    'id' => $userStream->id,
                    'title' => $userStream->title,
                    'description' => $userStream->description,
                    'roomName' => $userStream->room_name,
                    'roomPassword' => $password,
                    'participantUrl' => $participantUrl,
                    'status' => $userStream->status,
                    'recordingEnabled' => MeetingRecordingPreference::isEnabled($userSettings, false),
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
                'joinDisplayName' => $joinDisplayName,
            ]);
        }

        return redirect()->route('livestreams.supporter.join')
            ->withInput($request->only('meeting_id'))
            ->withErrors(['meeting_id' => 'Meeting not found or not available to join.']);
    }

    public function ready(Request $request, int $id): Response
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);
        $password = $livestream->getDecryptedPassword();
        $joinUrl = \App\Support\UnityMeetUrls::guestJoinUrl($livestream->room_name);
        $settings = is_array($livestream->settings) ? $livestream->settings : [];

        return Inertia::render('frontend/livestreams/Ready', [
            'livestream' => [
                'id' => $livestream->id,
                'title' => $livestream->title,
                'roomName' => $livestream->room_name,
                'roomPassword' => $password,
                'requiresPasscode' => $livestream->requiresPasscode(),
                'joinUrl' => $joinUrl,
                'status' => $livestream->status,
                'scheduledAt' => $livestream->scheduled_at?->toIso8601String(),
                'participantEmails' => LivestreamParticipantEmails::fromSettings($settings),
                'canStartMeeting' => $livestream->canStartMeeting(),
                'isMeetingActive' => in_array($livestream->status, ['meeting_live', 'live', 'starting'], true),
            ],
            'emailCredits' => UserEmailCredits::stats($request->user()),
            ...$this->emailPurchaseProps(),
        ]);
    }

    public function show(Request $request, int $id, StreamingQueueService $streamingQueue): Response
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->with(['user.organization'])->findOrFail($id);

        $authUser = $request->user();
        $organization = Organization::forAuthUser($authUser);
        $dropboxConnected = ! empty($authUser->dropbox_refresh_token)
            || ($organization && ! empty($organization->dropbox_refresh_token));
        $youtubeConnected = ($organization && (! empty($organization->youtube_refresh_token) || ! empty($organization->youtube_access_token)))
            || (! empty($authUser->youtube_refresh_token) || ! empty($authUser->youtube_access_token));
        $youtubeChannelUrl = $organization?->youtube_channel_url ?: $authUser->youtube_channel_url;
        $password = $livestream->getDecryptedPassword();
        $directorUrl = $livestream->getDirectorUrl(false);
        $directorUrlDropbox = $dropboxConnected ? $livestream->getDirectorUrl(true) : null;
        $participantUrl = $livestream->getParticipantUrl();
        $hostPushUrl = $livestream->getHostPushUrl(false);
        $hostPushUrlDropbox = null;
        if ($dropboxConnected) {
            $dropboxHostUrl = $livestream->getHostPushUrl(true);
            if (str_contains($dropboxHostUrl, '&dropbox=')) {
                $hostPushUrlDropbox = $dropboxHostUrl;
            }
        }
        // Scene-mixer URL: composite of ALL room participants → MediaMTX → worker → YouTube.
        // Frontend loads this in a hidden iframe so guests reach YouTube too.
        $scenePushUrl = $livestream->getScenePushUrl();
        $watchUrl = $livestream->getPublicViewUrl();
        $unityLiveUrl = url('/unity-live/' . $livestream->room_name);
        $liveViewerUrl = url('/live/' . $livestream->room_name);
        $joinUrl = \App\Support\UnityMeetUrls::guestJoinUrl($livestream->room_name);

        $vdoRoom = $livestream->getVdoRoomName();
        $passwordParam = $password !== '' ? '&password=' . rawurlencode($password) : '';
        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        $displayName = $settings['display_name'] ?? $request->user()->name ?? 'Host';
        $participantEmails = LivestreamParticipantEmails::fromSettings(is_array($settings) ? $settings : null);
        $directorLabel = '&label=' . rawurlencode($displayName);
        $rtmpUrl = $settings['rtmp_url'] ?? 'rtmp://a.rtmp.youtube.com/live2';
        $youtubeGoLiveEnabled = ! empty($livestream->youtube_stream_key);
        $pushLink = $youtubeGoLiveEnabled
            ? 'https://vdo.ninja/?push=' . rawurlencode($vdoRoom) . '&cleanoutput' . $passwordParam . $directorLabel
            : null;
        $viewLink = $youtubeGoLiveEnabled
            ? 'https://vdo.ninja/?director=' . rawurlencode($vdoRoom) . $passwordParam . '&output&autostart&cleanoutput'
            : null;
        $streamKeyDisplay = $youtubeGoLiveEnabled ? $livestream->getDecryptedStreamKey() : null;

        $recordingConsentDeclines = LivestreamRecordingDecline::query()
            ->where('livestream_kind', 'user')
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
            ->where('livestream_kind', 'user')
            ->where('livestream_id', $livestream->id)
            ->latest('id')
            ->first();

        return Inertia::render('frontend/livestreams/Show', [
            'recordingConsentDeclines' => $recordingConsentDeclines,
            'emailCredits' => UserEmailCredits::stats($request->user()),
            'authUserId' => $authUser->id,
            'participantRoster' => LivestreamParticipantRoster::inMeetingRosterForUserLivestream($livestream),
            'broadcastChannel' => \App\Support\UnityLiveBroadcast::channelName($livestream),
            'giftOccasions' => GiftOccasion::query()
                ->orderBy('category')
                ->orderBy('occasion')
                ->get(['id', 'occasion', 'icon', 'category']),
            'senderGiftBalances' => [
                'purchased_believe_points' => round((float) ($authUser->believe_points ?? 0), 2),
            ],
            ...$this->emailPurchaseProps(),
            'livestream' => [
                'id' => $livestream->id,
                'title' => $livestream->title,
                'description' => $livestream->description,
                'roomName' => $livestream->room_name,
                'roomPassword' => $password,
                'directorUrl' => $directorUrl,
                'directorUrlDropbox' => $directorUrlDropbox,
                'participantUrl' => $participantUrl,
                'hostPushUrl' => $hostPushUrl,
                'hostPushUrlDropbox' => $hostPushUrlDropbox,
                'scenePushUrl' => $scenePushUrl,
                'canvasUrl' => $livestream->getCanvasUrl(),
                'canvasMode' => $livestream->isCanvasModeEnabled(),
                'browserMediaMtxPush' => \App\Support\StreamingWorkerSourceUrl::shouldAttachVdoMediaMtxPush(),
                'dropboxRecordingAvailable' => $hostPushUrlDropbox !== null,
                'watchUrl' => $watchUrl,
                'unityLiveUrl' => $unityLiveUrl,
                'liveViewerUrl' => $liveViewerUrl,
                'joinUrl' => $joinUrl,
                'isPublic' => (bool) $livestream->is_public,
                'status' => $livestream->status,
                'scheduledAt' => $livestream->scheduled_at?->toIso8601String(),
                'participantEmails' => $participantEmails,
                'meetingSessionKey' => (int) ($settings['meeting_session'] ?? 0),
                'startedAt' => $livestream->started_at?->toIso8601String(),
                'endedAt' => $livestream->ended_at?->toIso8601String(),
                'canStartMeeting' => $livestream->canStartMeeting(),
                'wantsYoutubeLive' => $livestream->wantsYoutubeLiveAtCreate(),
                'wantsUnityLive' => (bool) $livestream->is_public,
                'canSetUnityLive' => $livestream->canSetUnityLive(),
                'canQueueYoutubeLive' => $livestream->canQueueYoutubeStream()
                    && $streamingQueue->canStartNewStreamingJob((string) $request->user()->id, 'user', $livestream->id),
                'canGoLive' => ($livestream->wantsYoutubeLiveAtCreate() && $livestream->canQueueYoutubeStream()
                        && $streamingQueue->canStartNewStreamingJob((string) $request->user()->id, 'user', $livestream->id))
                    || ($livestream->is_public && $livestream->canSetUnityLive()),
                'latestInviteUrl' => $joinUrl,
                'requiresPasscode' => $livestream->requiresPasscode(),
                'hasStreamKey' => ! empty($livestream->youtube_stream_key),
                'youtubeGoLiveEnabled' => $youtubeGoLiveEnabled,
                'pushLink' => $pushLink,
                'viewLink' => $viewLink,
                'streamKeyDisplay' => $streamKeyDisplay,
                'rtmpUrl' => $rtmpUrl,
                'youtubeBroadcastId' => $livestream->youtube_broadcast_id,
                'youtubeWatchUrl' => ! empty($livestream->youtube_broadcast_id)
                    ? 'https://www.youtube.com/watch?v='.$livestream->youtube_broadcast_id
                    : null,
                'youtubeConnected' => $youtubeConnected,
                'youtubeChannelUrl' => $youtubeChannelUrl,
                'streamingQueueStatus' => $streamingQueue->queueStatusForUi($latestStreamingJob, $livestream),
                'hasActiveStreamingJob' => $streamingQueue->existingActiveJobFor('user', $livestream->id) !== null,
            ],
        ]);
    }

    public function startMeeting(Request $request, int $id): RedirectResponse
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        // Already in a meeting (e.g. Refresh left status meeting_live, or Ready page
        // clicked again) — open the host room instead of failing.
        if (in_array($livestream->status, ['meeting_live', 'live', 'starting'], true)) {
            return redirect()->route('livestreams.supporter.show', $id)
                ->with('success', 'Meeting is already in progress.');
        }

        if (! $livestream->canStartMeeting()) {
            return redirect()->back()->withErrors(['error' => 'Meeting cannot be started in current state.']);
        }

        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        $settings['meeting_session'] = (int) ($settings['meeting_session'] ?? 0) + 1;
        unset($settings['stream_stop_requested'], $settings['host_abandoned_at']);

        $livestream->update([
            'status' => 'meeting_live',
            'settings' => $settings !== [] ? $settings : null,
        ]);

        \App\Support\LivestreamMeetingPresence::clear('user', $livestream->id);

        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyMeetingStarted($livestream);

        return redirect()->route('livestreams.supporter.show', $id)->with('success', 'Meeting started. Share the invite link, then click Go Live when ready.');
    }

    /**
     * Fully end the meeting — tear down VDO session, stop relays, return to draft.
     */
    public function endMeeting(
        Request $request,
        int $id,
        YouTubeService $youtubeService,
        LivestreamHostAbandonService $abandonService,
    ): RedirectResponse {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        if (in_array($livestream->status, ['live', 'meeting_live', 'starting'], true)) {
            $accessToken = $this->resolveYouTubeAccessToken($request, $youtubeService);
            $abandonService->abandonUserStream($livestream, $accessToken, $youtubeService);
            $livestream->refresh();
        }

        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        $settings['meeting_session'] = (int) ($settings['meeting_session'] ?? 0) + 1;
        unset($settings['stream_stop_requested'], $settings['host_abandoned_at']);

        $livestream->update([
            'status' => 'draft',
            'settings' => $settings !== [] ? $settings : null,
            'ended_at' => $livestream->ended_at ?? now(),
        ]);

        \App\Support\LivestreamMeetingPresence::clear('user', $livestream->id);

        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyHostDashboard($livestream, 'meeting_ended');

        return redirect()->back()->with(
            'success',
            'Meeting ended. Click Start meeting when you want to open the room again.'
        );
    }

    /**
     * JSON roster for host UI polling (guest presence merged).
     */
    public function participantRosterJson(Request $request, int $id): JsonResponse
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        return response()->json([
            'participantRoster' => LivestreamParticipantRoster::inMeetingRosterForUserLivestream($livestream),
        ]);
    }

    public function setLive(Request $request, int $id): RedirectResponse
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        if (! $livestream->is_public) {
            return redirect()->back()->withErrors([
                'error' => 'Turn on “Show on Unity Live” for this meeting to publish on Unity Live.',
            ]);
        }

        if (! $livestream->canSetUnityLive()) {
            return redirect()->back()->withErrors([
                'error' => 'Cannot publish on Unity Live in this state (status: '.$livestream->status.'). End the stream first or open a new meeting.',
            ]);
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
     * Stop Unity Live listing only; keep the meeting open unless a YouTube relay is active.
     */
    public function endUnityLive(Request $request, int $id, StreamingQueueService $streamingQueue): RedirectResponse
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        if ($livestream->status !== 'live') {
            return redirect()->back()->withErrors([
                'error' => 'This meeting is not live on Unity Live.',
            ]);
        }

        if ($streamingQueue->existingActiveJobFor('user', $livestream->id)) {
            return redirect()->back()->withErrors([
                'error' => 'YouTube stream is still active. Use End stream to stop the full broadcast.',
            ]);
        }

        $livestream->update([
            'status' => 'meeting_live',
        ]);

        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyUnityLiveEnded($livestream);

        return redirect()->back()->with(
            'success',
            'Removed from Unity Live. Your meeting is still open for guests.'
        );
    }

    /**
     * Host closed the meeting tab or navigated away — stop stream without a full form POST.
     */
    public function abandonHostSession(
        Request $request,
        int $id,
        YouTubeService $youtubeService,
        LivestreamHostAbandonService $abandonService,
    ): JsonResponse {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);
        $accessToken = $this->resolveYouTubeAccessToken($request, $youtubeService);
        $abandonService->abandonUserStream($livestream, $accessToken, $youtubeService);

        return response()->json(['ok' => true]);
    }

    public function edit(Request $request, int $id): Response
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);
        $settings = $livestream->settings ?? [];

        return Inertia::render('frontend/livestreams/Edit', [
            'livestream' => [
                'id' => $livestream->id,
                'title' => $livestream->title,
                'description' => $livestream->description,
                'scheduledAt' => $livestream->scheduled_at?->toIso8601String(),
                'isPublic' => (bool) $livestream->is_public,
                'displayName' => $settings['display_name'] ?? $request->user()->name,
                'status' => $livestream->status,
            ],
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        if (in_array($livestream->status, ['live'], true)) {
            return redirect()->back()->withErrors(['error' => 'Cannot edit a live stream. End it first.']);
        }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'scheduled_at' => 'nullable|date',
            'display_name' => 'nullable|string|max:255',
            'is_public' => 'nullable|boolean',
        ]);

        $settings = $livestream->settings ?? [];
        if ($request->filled('display_name')) {
            $settings['display_name'] = $request->display_name;
        } elseif (array_key_exists('display_name', $settings)) {
            unset($settings['display_name']);
        }

        $livestream->update([
            'title' => $request->title,
            'description' => $request->description,
            'scheduled_at' => $request->filled('scheduled_at') ? \Carbon\Carbon::parse($request->scheduled_at) : null,
            'is_public' => $request->boolean('is_public', $livestream->is_public),
            'settings' => $settings ?: null,
        ]);

        return redirect()->route('livestreams.supporter.show', $id)->with('success', 'Meeting updated.');
    }

    public function removeParticipant(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        $email = strtolower(trim((string) $validated['email']));
        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        $emails = LivestreamParticipantEmails::fromSettings($settings);

        if (! in_array($email, $emails, true)) {
            return redirect()->back()->withErrors(['email' => 'Participant not found on this meeting.']);
        }

        $settings['participant_emails'] = array_values(array_filter(
            $emails,
            static fn (string $participantEmail) => $participantEmail !== $email
        ));

        $livestream->update([
            'settings' => $settings !== [] ? $settings : null,
        ]);

        \App\Support\LivestreamMeetingPresence::leaveByEmail('user', $livestream->id, $email);
        \App\Support\UnityLiveBroadcast::notifyHostDashboard($livestream->fresh(), 'participant_removed');

        return redirect()->back()->with('success', 'Participant removed.');
    }

    /**
     * Typeahead for Unity Meet invite: verified supporters by name/email.
     */
    public function searchInviteRecipients(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));

        return response()->json([
            'results' => UnityMeetInviteRecipients::search($user, $q),
        ]);
    }

    public function inviteParticipant(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'resend' => 'nullable|boolean',
            'notify_via' => 'nullable|string|in:email,biu,both',
        ]);

        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        if (in_array($livestream->status, ['cancelled', 'ended'], true)) {
            return redirect()->back()->withErrors(['email' => 'Cannot invite participants to this meeting.']);
        }

        $user = $request->user();
        $notifyVia = UnityMeetInviteNotifyVia::normalize($request->input('notify_via'));
        $email = strtolower(trim((string) $validated['email']));
        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        $emails = LivestreamParticipantEmails::fromSettings($settings);
        $resend = $request->boolean('resend');

        if (UnityMeetInviteNotifyVia::usesEmailCredits($notifyVia) && ! UserEmailCredits::canSend($user)) {
            return redirect()->back()->withErrors([
                'email' => UserEmailCredits::insufficientMessage($user),
            ]);
        }

        if ($notifyVia === UnityMeetInviteNotifyVia::BIU && $this->userForInviteEmail($email) === null) {
            return redirect()->back()->withErrors([
                'email' => 'No Believe In Unity account found for this email. Use email invitation or ask them to sign up first.',
            ]);
        }

        if ($resend) {
            if (! in_array($email, $emails, true)) {
                return redirect()->back()->withErrors(['email' => 'Participant not found on this meeting.']);
            }

            $this->dispatchParticipantInvitation($livestream, $email, $notifyVia);

            return redirect()->back()->with('success', $this->inviteSentSuccessMessage($notifyVia));
        }

        if (in_array($email, $emails, true)) {
            return redirect()->back()->withErrors(['email' => 'This email is already invited. Use resend to send the invitation again.']);
        }

        if (count($emails) >= 50) {
            return redirect()->back()->withErrors(['email' => 'Maximum of 50 invited participants reached.']);
        }

        $emails[] = $email;
        $settings['participant_emails'] = LivestreamParticipantEmails::fromSettings(['participant_emails' => $emails]);
        $livestream->update([
            'settings' => $settings !== [] ? $settings : null,
        ]);

        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyHostDashboard($livestream, 'participant_invited');

        $this->dispatchParticipantInvitation($livestream, $email, $notifyVia);

        return redirect()->back()->with('success', $this->inviteSentSuccessMessage($notifyVia));
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        if ($livestream->status === 'live') {
            return redirect()->back()->withErrors(['error' => 'Cannot delete a live stream. End it first.']);
        }

        $livestream->delete();

        return redirect()->route('livestreams.supporter.index')->with('success', 'Meeting deleted.');
    }

    public function endStream(
        Request $request,
        int $id,
        YouTubeService $youtubeService,
        StreamingQueueService $streamingQueue,
    ): RedirectResponse {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        // Accept any active state, not just 'live'. A stream that failed (e.g.
        // bridge cold) gets reset to 'meeting_live'; the user must still be able
        // to end/reset it. Only reject genuinely-inactive states.
        if (! in_array($livestream->status, ['live', 'meeting_live', 'starting'], true)) {
            $settings = $livestream->settings ?? [];
            if (! empty($settings['stream_stop_requested'])) {
                unset($settings['stream_stop_requested']);
                $livestream->update(['settings' => $settings ?: null]);
            }

            return redirect()->back()->withErrors(['error' => 'Stream is not active.']);
        }

        // Mark stop intent BEFORE teardown. The AWS worker polls the callback
        // endpoint every ~10s and shuts itself down once it sees this.
        $settings = $livestream->settings ?? [];
        $settings['stream_stop_requested'] = now()->toIso8601String();
        unset($settings['host_abandoned_at']);
        $livestream->update(['settings' => $settings]);
        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notify(
            $livestream,
            'stream_ended',
            'The host has ended the stream. Playback may stop in a few seconds.',
        );

        // Stop the relay locally first (DB + ECS StopTask) so the UI unblocks quickly.
        // YouTube "complete" is slow and is deferred after the response.
        $oldBroadcastId = $livestream->youtube_broadcast_id;
        $accessToken = $this->resolveYouTubeAccessToken($request, $youtubeService);
        $settledLocally = $streamingQueue->finalizeAfterHostEndStream('user', $livestream->id);

        // Keep the Unity Meet room open — End YouTube Live must not End Meeting.
        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        unset($settings['stream_stop_requested'], $settings['host_abandoned_at']);
        $livestream->update([
            'status' => 'meeting_live',
            'settings' => $settings !== [] ? $settings : null,
        ]);
        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyStreamEnded($livestream);

        if (! empty($oldBroadcastId) && $accessToken) {
            dispatch(function () use ($accessToken, $oldBroadcastId, $id): void {
                try {
                    app(YouTubeService::class)->updateBroadcastStatus(
                        $accessToken,
                        $oldBroadcastId,
                        'complete'
                    );
                } catch (\Throwable $e) {
                    Log::warning('End stream: deferred YouTube complete failed (supporter)', [
                        'livestream_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            })->afterResponse();
        }

        return redirect()->back()->with(
            'success',
            $settledLocally
                ? 'YouTube live ended. Your meeting is still open — you can go live again anytime.'
                : 'Stream ended. Your meeting is still open — you can go live again when ready.'
        );
    }

    public function updateVisibility(Request $request, int $id): RedirectResponse
    {
        $request->validate(['is_public' => 'required|boolean']);

        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);
        $livestream->update(['is_public' => $request->boolean('is_public')]);

        return redirect()->back()->with('success', $livestream->is_public
            ? 'Stream is now public. It will appear on the Unity Live page when live.'
            : 'Stream is now private. Only people with the viewer link can watch.');
    }

    public function updateStreamKey(Request $request, int $id): RedirectResponse
    {
        $request->validate(['youtube_stream_key' => 'required|string']);

        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);
        $livestream->update([
            'youtube_stream_key' => Crypt::encryptString($request->youtube_stream_key),
        ]);

        return redirect()->back()->with('success', 'YouTube stream key saved. You can start your cloud stream when ready.');
    }

    /**
     * Create a YouTube live broadcast on the connected account (user OAuth first, else organization) and save stream key.
     */
    public function prepareYouTubeLive(Request $request, int $id, YouTubeService $youtubeService): RedirectResponse
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        $authUser = $request->user()->loadMissing('organization');
        $accessToken = null;
        if (! empty($authUser->youtube_access_token) || ! empty($authUser->youtube_refresh_token)) {
            $accessToken = $youtubeService->getValidAccessTokenForUser($authUser);
        }
        if (! $accessToken && $authUser->organization
            && (! empty($authUser->organization->youtube_access_token) || ! empty($authUser->organization->youtube_refresh_token))) {
            $accessToken = $youtubeService->getValidAccessToken($authUser->organization);
        }

        if (! $accessToken) {
            return redirect()->back()->withErrors([
                'youtube' => 'Connect YouTube in Integrations (your account or organization), then try again.',
            ]);
        }

        $title = $livestream->title ?: 'Unity Meet';

        // 1. Reuse existing broadcast only if YouTube still allows going live (not complete/revoked).
        if (! empty($livestream->youtube_broadcast_id)) {
            $life = strtolower((string) ($youtubeService->getBroadcastStreamStatus(
                $accessToken,
                $livestream->youtube_broadcast_id
            )['life_cycle_status'] ?? ''));

            if (in_array($life, ['complete', 'revoked'], true)) {
                Log::info('YouTube broadcast already ended; creating a new one', [
                    'livestream_id' => $livestream->id,
                    'previous_broadcast_id' => $livestream->youtube_broadcast_id,
                    'life_cycle_status' => $life,
                ]);
            } else {
                $existing = $youtubeService->getBroadcastStreamKey($accessToken, $livestream->youtube_broadcast_id);
                if ($existing && ! empty($existing['stream_key'])) {
                    $this->persistYoutubeKey($livestream, $livestream->youtube_broadcast_id, $existing['stream_key'], $existing['rtmp_url'] ?? null);

                    return redirect()->back()->with(
                        'success',
                        'YouTube live already created — existing broadcast and stream key reused.'
                    );
                }
                Log::warning('YouTube broadcast missing on remote, falling back to title lookup / create', [
                    'livestream_id' => $livestream->id,
                    'previous_broadcast_id' => $livestream->youtube_broadcast_id,
                ]);
            }
        }

        // 2. Channel may already have a non-completed YouTube live with this exact title — reuse it
        //    so multiple meetings under the same name share one YouTube broadcast (no duplicates).
        $existingByTitle = $youtubeService->findBroadcastStreamKeyByTitle($accessToken, $title);
        if ($existingByTitle && ! empty($existingByTitle['stream_key'])) {
            $this->persistYoutubeKey($livestream, $existingByTitle['broadcast_id'], $existingByTitle['stream_key'], $existingByTitle['rtmp_url'] ?? null);
            return redirect()->back()->with(
                'success',
                'YouTube live with this name already exists — existing broadcast and stream key reused.'
            );
        }

        // 3. No existing broadcast we can reuse — create a fresh one.
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
                'youtube' => 'Could not create a YouTube live broadcast. Try again or paste a stream key from YouTube Studio.',
            ]);
        }

        $this->persistYoutubeKey($livestream, $broadcastData['broadcast_id'], $broadcastData['stream_key'], $broadcastData['rtmp_url'] ?? null);

        return redirect()->back()->with(
            'success',
            'YouTube live is ready on your connected channel. Your stream key was saved — click Go Live when you are ready.'
        );
    }

    private function persistYoutubeKey(UserLivestream $livestream, string $broadcastId, string $streamKey, ?string $rtmpUrl): void
    {
        $settings = $livestream->settings ?? [];
        if (! empty($rtmpUrl)) {
            $settings['rtmp_url'] = $rtmpUrl;
        }
        $livestream->update([
            'youtube_broadcast_id' => $broadcastId,
            'youtube_stream_key' => Crypt::encryptString($streamKey),
            'settings' => $settings ?: null,
        ]);
    }

    /**
     * Resolve the YouTube access token for the supporter (user OAuth first, else organization).
     */
    private function resolveYouTubeAccessToken(Request $request, YouTubeService $youtubeService): ?string
    {
        $authUser = $request->user()->loadMissing('organization');

        $accessToken = null;
        if (! empty($authUser->youtube_access_token) || ! empty($authUser->youtube_refresh_token)) {
            $accessToken = $youtubeService->getValidAccessTokenForUser($authUser);
        }
        if (! $accessToken && $authUser->organization
            && (! empty($authUser->organization->youtube_access_token) || ! empty($authUser->organization->youtube_refresh_token))) {
            $accessToken = $youtubeService->getValidAccessToken($authUser->organization);
        }

        return $accessToken;
    }

    /**
     * Make sure the livestream has a usable YouTube stream key right now.
     *
     * Order:
     *  1. Existing broadcast id on the row → fetch fresh key from YouTube (covers stale keys).
     *  2. YouTube channel already has a non-completed broadcast with the same title → reuse it
     *     (this is the "user creates multiple meetings with the same name" case).
     *  3. Create a brand new broadcast.
     *  4. Fall back to a manually pasted key on the row.
     */
    private function ensureFreshYouTubeStreamKey(Request $request, UserLivestream $livestream, YouTubeService $youtubeService): ?string
    {
        $accessToken = $this->resolveYouTubeAccessToken($request, $youtubeService);

        if (! $accessToken) {
            $manual = $livestream->getDecryptedStreamKey();
            return $manual !== '' ? $manual : null;
        }

        $title = $livestream->title ?: 'Unity Meet';

        if (! empty($livestream->youtube_broadcast_id)) {
            $life = strtolower((string) ($youtubeService->getBroadcastStreamStatus(
                $accessToken,
                $livestream->youtube_broadcast_id
            )['life_cycle_status'] ?? ''));

            if (! in_array($life, ['complete', 'revoked'], true)) {
                $existing = $youtubeService->getBroadcastStreamKey($accessToken, $livestream->youtube_broadcast_id);
                if ($existing && ! empty($existing['stream_key'])) {
                    $this->persistYoutubeKey($livestream, $livestream->youtube_broadcast_id, $existing['stream_key'], $existing['rtmp_url'] ?? null);

                    return $existing['stream_key'];
                }
            }

            Log::warning('YouTube broadcast not reusable at Go Live; will look up by title or create a new one', [
                'livestream_id' => $livestream->id,
                'previous_broadcast_id' => $livestream->youtube_broadcast_id,
                'life_cycle_status' => $life !== '' ? $life : null,
            ]);
        }

        $byTitle = $youtubeService->findBroadcastStreamKeyByTitle($accessToken, $title);
        if ($byTitle && ! empty($byTitle['stream_key'])) {
            $this->persistYoutubeKey($livestream, $byTitle['broadcast_id'], $byTitle['stream_key'], $byTitle['rtmp_url'] ?? null);
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
            $this->persistYoutubeKey($livestream, $created['broadcast_id'], $created['stream_key'], $created['rtmp_url'] ?? null);
            return $created['stream_key'];
        }

        $manual = $livestream->getDecryptedStreamKey();
        return $manual !== '' ? $manual : null;
    }

    /**
     * Enqueue AWS streaming worker (SQS): pull meeting video from the host VDO URL and push RTMP to YouTube.
     * Legacy route name "go-live-obs-auto" is kept as an alias — this path does not use OBS.
     */
    public function queueStreamRelayJob(Request $request, int $id, StreamingQueueService $streamingQueue, StreamingPreflight $preflight, YouTubeService $youtubeService): RedirectResponse
    {
        $livestream = UserLivestream::where('user_id', $request->user()->id)->findOrFail($id);

        if (! $livestream->wantsYoutubeLiveAtCreate()) {
            return redirect()->back()->withErrors([
                'go_live' => 'YouTube live was not enabled for this meeting. Start a new meeting with “Go live (optional)” turned on.',
            ]);
        }

        if (! $livestream->canQueueYoutubeStream()) {
            return redirect()->back()->withErrors([
                'go_live' => 'Cannot start YouTube live in this state (status: '.$livestream->status.'). End any active stream or open a new meeting.',
            ]);
        }

        // Atomic guard against rapid double-clicks / parallel tabs (see Org
        // queueStreamRelayJob for full rationale). Serialises Go Live for this
        // livestream across PHP-FPM workers and re-checks for an in-flight
        // streaming_jobs row inside the critical section.
        $lock = Cache::lock("livestream-golive-user-{$livestream->id}", 30);
        $acquired = false;
        try {
            $acquired = $lock->block(2);
        } catch (\Throwable $e) {
            Log::warning('Go Live lock acquisition failed; proceeding without lock', [
                'livestream_id' => $livestream->id, 'err' => $e->getMessage(),
            ]);
        }
        try {
            $blocked = $streamingQueue->goLiveBlockedReason((string) $request->user()->id, 'user', $livestream->id);
            if ($blocked !== null) {
                return redirect()->back()->withErrors(['go_live' => $blocked]);
            }
            return $this->queueStreamRelayJobImpl($request, $livestream, $streamingQueue, $preflight, $youtubeService);
        } finally {
            if ($acquired) {
                try { $lock->release(); } catch (\Throwable $e) { /* harmless */ }
            }
        }
    }

    /**
     * Body of queueStreamRelayJob — wrapped by the atomic Cache::lock dupe
     * guard above. Kept separate so the lock + dupe check stays compact.
     */
    private function queueStreamRelayJobImpl(Request $request, UserLivestream $livestream, StreamingQueueService $streamingQueue, StreamingPreflight $preflight, YouTubeService $youtubeService): RedirectResponse
    {
        $gate = $preflight->check($livestream, $request->user());
        if (! $gate->allowed) {
            return redirect()->back()->withErrors(['go_live' => $gate->reason]);
        }

        $blocked = $streamingQueue->goLiveBlockedReason((string) $request->user()->id, 'user', $livestream->id);
        if ($blocked !== null) {
            return redirect()->back()->withErrors(['go_live' => $blocked]);
        }

        // Auto-resolve a current YouTube stream key. Reuses the existing broadcast (by id, then by title)
        // before creating a new one so multiple meetings under the same name share one YouTube live.
        $streamKey = $this->ensureFreshYouTubeStreamKey($request, $livestream, $youtubeService);
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

        $organizationId = (string) $request->user()->id;
        $meetingId = (string) $livestream->id;
        $sourceUrl = StreamingWorkerSourceUrl::resolve($livestream);
        $destinationUrl = 'rtmp://a.rtmp.youtube.com/live2/'.$streamKey;
        $callbackUrl = streaming_status_callback_url();
        $maxDurationMinutes = (int) config('streaming.max_duration_minutes', 120);

        $job = StreamingJob::create([
            'livestream_kind' => 'user',
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

        $livestream->update([
            'status' => 'meeting_live',
        ]);
        $livestream->refresh();
        \App\Support\UnityLiveBroadcast::notifyHostDashboard($livestream, 'streaming_queued');

        return redirect()->back()->with(
            'success',
            'Cloud stream queued (AWS). Keep this meeting open as host; status will move to live when the worker connects.'
        );
    }

    /**
     * Unity Meet — recordings tied to this user’s meetings only (personal Dropbox, or org Dropbox filtered by your room names).
     */
    public function recordings(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organization');

        $ctx = $this->unityMeetRecordingDropboxContext($user);
        $listParams = $this->parseRecordingsListParams($request);

        $meetingTitleHints = UserLivestream::where('user_id', $user->id)
            ->select(['room_name', 'title'])
            ->get()
            ->map(static fn ($ls) => [
                'roomName' => (string) $ls->room_name,
                'title' => $ls->title,
            ])
            ->values()
            ->all();

        $allFiles = [];
        if (($ctx['linked'] ?? false) && ($ctx['token'] ?? '') !== '') {
            try {
                $allFiles = $this->fetchUnityMeetRecordingsListFiles(
                    $ctx,
                    $user,
                    $listParams['q'],
                    $meetingTitleHints,
                );
            } catch (\Throwable $e) {
                Log::warning('Unity Meet recordings Dropbox list failed', ['error' => $e->getMessage()]);
            }
        }

        $allFiles = $this->filterRecordingsByType($allFiles, $listParams['filter']);
        $paginated = $this->paginateRecordingFiles($allFiles, $listParams['page'], $listParams['perPage']);

        $dropboxFolderName = trim(str_replace('/', '', (string) ($ctx['folderPath'] ?? ''))) ?: 'BIU Meeting Recordings';

        $paths = array_map(static fn (array $f) => (string) ($f['path_display'] ?? ''), $allFiles);
        $publishService = app(RecordingYoutubePublishService::class);

        return Inertia::render('frontend/livestreams/Dropbox', [
            'dropboxLinked' => (bool) ($ctx['linked'] ?? false),
            'dropboxRedirectUri' => config('services.dropbox.redirect_uri'),
            'dropboxFolderName' => $dropboxFolderName,
            'dropboxFolderPath' => (string) ($ctx['folderPath'] ?? ''),
            'dropboxFiles' => $paginated['items'],
            'recordingsList' => [
                'q' => $listParams['q'],
                'filter' => $listParams['filter'],
                'page' => $paginated['page'],
                'perPage' => $paginated['perPage'],
                'total' => $paginated['total'],
                'lastPage' => $paginated['lastPage'],
                'from' => $paginated['from'],
                'to' => $paginated['to'],
            ],
            'backUrl' => route('livestreams.supporter.index'),
            'unityMeetRecordings' => true,
            'recordingsDisconnectAvailable' => ($ctx['source'] ?? null) === 'user',
            'recordingsBackedByOrganization' => ($ctx['source'] ?? null) === 'organization',
            'recordingsRestrictedToUserMeetings' => (bool) ($ctx['restrictToUserRooms'] ?? false),
            'meetingTitleHints' => $meetingTitleHints,
            'youtubeConnected' => $publishService->userHasYoutubeConnected($user),
            'youtubeCanUpload' => $publishService->userCanUploadToYoutube($user),
            'youtubeIntegrationsUrl' => $user->hasNonprofitDashboardRole()
                ? route('integrations.youtube')
                : route('livestreams.supporter.settings'),
            'youtubeReconnectUrl' => route('integrations.youtube.redirect'),
            'youtubeUploads' => $publishService->uploadsForPaths($user->id, $paths),
        ]);
    }

    public function recordingsSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organization');

        $ctx = $this->unityMeetRecordingDropboxContext($user);
        if (! ($ctx['linked'] ?? false) || ($ctx['token'] ?? '') === '') {
            return response()->json(['error' => 'Dropbox not connected'], 400);
        }

        $query = $request->input('q', '');
        $query = is_string($query) ? trim($query) : '';
        if ($query === '') {
            return response()->json(['files' => []]);
        }

        try {
            $api = new DropboxOrgApi((string) $ctx['token']);
            $folderPath = (string) $ctx['folderPath'];
            $files = $api->search($folderPath, $query);
            $filtered = $this->mapUnityFilteredSearchFiles(
                $files,
                (bool) ($ctx['restrictToUserRooms'] ?? false),
                $ctx['roomNames'] ?? [],
                $user,
            );

            return response()->json(['files' => DropboxOrgApi::sortByModifiedDesc($filtered)]);
        } catch (\Throwable $e) {
            Log::warning('Unity Meet recordings Dropbox search failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Search failed', 'files' => []], 500);
        }
    }

    public function recordingDownload(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organization');

        $ctx = $this->unityMeetRecordingDropboxContext($user);
        $path = $request->query('path');
        if (! is_string($path) || $path === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file.');
        }
        $path = trim($path);
        if (str_contains($path, '..')) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file path.');
        }
        if (! ($ctx['linked'] ?? false) || ($ctx['token'] ?? '') === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Dropbox not connected.');
        }
        $folderPath = (string) $ctx['folderPath'];
        if ($folderPath === '' || ! str_starts_with($path, $folderPath)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'You can only download files from your recording folder.');
        }
        if (! $this->unityMeetRecordingFileMatchesContext($path, $ctx, $user)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Recording not available for your account.');
        }

        $api = new DropboxOrgApi((string) $ctx['token']);
        $link = $api->getTemporaryLink($path);
        if (! $link) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Could not generate download link.');
        }

        return redirect()->away($link);
    }

    public function recordingBrandedDownload(Request $request): RedirectResponse|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organization');

        $ctx = $this->unityMeetRecordingDropboxContext($user);
        $path = $request->query('path');
        if (! is_string($path) || $path === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file.');
        }
        $path = trim($path);
        if (str_contains($path, '..')) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file path.');
        }
        if (! ($ctx['linked'] ?? false) || ($ctx['token'] ?? '') === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Dropbox not connected.');
        }
        $folderPath = (string) $ctx['folderPath'];
        if ($folderPath === '' || ! str_starts_with($path, $folderPath)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'You can only download files from your recording folder.');
        }
        if (! $this->unityMeetRecordingFileMatchesContext($path, $ctx, $user)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Recording not available for your account.');
        }

        $overlayService = app(LivestreamVideoOverlayService::class);
        if (! $overlayService->canApply()) {
            return redirect()->route('livestreams.supporter.recordings')->with(
                'error',
                'FFmpeg is not available on the server. Use the regular download or install FFmpeg.',
            );
        }

        $org = Organization::forAuthUser($user);
        $overlayConfig = $org !== null
            ? LivestreamOverlayConfig::forOrganization($org)
            : LivestreamOverlayConfig::forUser($user);

        if (LivestreamOverlayConfig::toVideoPayload($overlayConfig) === null) {
            return redirect()->route('livestreams.supporter.recordings')->with(
                'error',
                'Configure a logo, speaker name, sponsor, or bottom banner in Overlay Studio first.',
            );
        }

        $api = new DropboxOrgApi((string) $ctx['token']);
        $link = $api->getTemporaryLink($path);
        if (! $link) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Could not generate download link.');
        }

        $inputTmp = tempnam(sys_get_temp_dir(), 'unity_rec_');
        if ($inputTmp === false) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Could not prepare download.');
        }

        try {
            $download = Http::timeout(7200)->connectTimeout(60)->sink($inputTmp)->get($link);
            if (! $download->successful() || ! is_file($inputTmp) || filesize($inputTmp) < 500) {
                return redirect()->route('livestreams.supporter.recordings')->with('error', 'Could not download recording from Dropbox.');
            }

            $brandedPath = $overlayService->brandLocalFile($inputTmp, $overlayConfig);
            if ($brandedPath === null) {
                return redirect()->route('livestreams.supporter.recordings')->with('error', 'Could not apply overlay branding.');
            }

            $basename = basename($path);
            $filename = pathinfo($basename, PATHINFO_FILENAME).'-branded.mp4';

            return response()->streamDownload(function () use ($brandedPath): void {
                $stream = fopen($brandedPath, 'rb');
                if ($stream !== false) {
                    fpassthru($stream);
                    fclose($stream);
                }
                @unlink($brandedPath);
            }, $filename, [
                'Content-Type' => 'video/mp4',
            ]);
        } finally {
            if (is_file($inputTmp)) {
                @unlink($inputTmp);
            }
        }
    }

    public function recordingDelete(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organization');

        $ctx = $this->unityMeetRecordingDropboxContext($user);
        $path = $request->input('path');
        if (! is_string($path) || $path === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file.');
        }
        $path = trim($path);
        if (str_contains($path, '..')) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file path.');
        }
        if (! ($ctx['linked'] ?? false) || ($ctx['token'] ?? '') === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Dropbox not connected.');
        }
        $folderPath = (string) $ctx['folderPath'];
        if ($folderPath === '' || ! str_starts_with($path, $folderPath)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'You can only delete files from your recording folder.');
        }
        if (! $this->unityMeetRecordingFileMatchesContext($path, $ctx, $user)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Recording not available for your account.');
        }

        $api = new DropboxOrgApi((string) $ctx['token']);
        if (! $api->deleteFile($path)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Could not delete file.');
        }

        return redirect()->route('livestreams.supporter.recordings', $this->recordingsListRedirectParams($request))
            ->with('success', 'File deleted.');
    }

    public function recordingRename(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organization');

        $ctx = $this->unityMeetRecordingDropboxContext($user);

        $path = $request->input('path');
        $newName = $request->input('new_name');
        if (! is_string($path) || $path === '' || ! is_string($newName) || trim($newName) === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid request.');
        }
        $path = trim($path);
        $newName = trim($newName);
        $newName = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $newName);
        $newName = trim(preg_replace('/\s+/', ' ', $newName));
        if ($newName === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file name.');
        }
        if (str_contains($path, '..')) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file path.');
        }
        if (! ($ctx['linked'] ?? false) || ($ctx['token'] ?? '') === '') {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Dropbox not connected.');
        }
        $folderPath = (string) $ctx['folderPath'];
        if ($folderPath === '' || ! str_starts_with($path, $folderPath)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'You can only rename files in your recording folder.');
        }
        if (! $this->unityMeetRecordingFileMatchesContext($path, $ctx, $user)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Recording not available for your account.');
        }

        $toPath = $folderPath.'/'.$newName;
        $api = new DropboxOrgApi((string) $ctx['token']);
        if (! $api->moveFile($path, $toPath)) {
            return redirect()->route('livestreams.supporter.recordings')->with('error', 'Could not rename file.');
        }

        return redirect()->route('livestreams.supporter.recordings', $this->recordingsListRedirectParams($request))
            ->with('success', 'File renamed.');
    }

    public function recordingPublishToYoutube(
        Request $request,
        RecordingYoutubePublishService $publishService,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('organization');

        try {
            $validated = $request->validate([
                'path' => 'required|string|max:2048',
                'title' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:4900',
                'privacy_status' => 'nullable|string|in:public,unlisted,private',
            ]);

            $path = trim((string) $validated['path']);
            if ($path === '' || str_contains($path, '..')) {
                return redirect()->route('livestreams.supporter.recordings')->with('error', 'Invalid file path.');
            }

            $ctx = $this->unityMeetRecordingDropboxContext($user);
            if (! ($ctx['linked'] ?? false) || ($ctx['token'] ?? '') === '') {
                return redirect()->route('livestreams.supporter.recordings')->with('error', 'Dropbox not connected.');
            }

            $folderPath = rtrim((string) ($ctx['folderPath'] ?? ''), '/');
            if ($folderPath === '' || ! $this->dropboxPathIsInsideFolder($path, $folderPath)) {
                return redirect()->route('livestreams.supporter.recordings')->with('error', 'You can only publish files from your recording folder.');
            }

            if (! $this->unityMeetRecordingFileMatchesContext($path, $ctx, $user)) {
                return redirect()->route('livestreams.supporter.recordings')->with('error', 'Recording not available for your account.');
            }

            $result = $publishService->queuePublish(
                $user,
                $path,
                basename(str_replace('\\', '/', $path)),
                $validated['title'] ?? null,
                $validated['description'] ?? null,
                (string) ($validated['privacy_status'] ?? 'unlisted'),
            );

            if (! ($result['success'] ?? false)) {
                return redirect()->route('livestreams.supporter.recordings')->with(
                    'error',
                    (string) ($result['error'] ?? 'Could not start YouTube upload.'),
                );
            }

            $redirectParams = $this->recordingsListRedirectParams($request);

            return redirect()
                ->route('livestreams.supporter.recordings', $redirectParams)
                ->with('success', 'Upload to YouTube started. Keep this page open to watch progress.')
                ->with('youtube_upload_path', $path);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('recordingPublishToYoutube failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('livestreams.supporter.recordings')
                ->with('error', 'Could not start YouTube upload. Please try again.');
        }
    }

    private function dropboxPathIsInsideFolder(string $path, string $folderPath): bool
    {
        $path = str_replace('\\', '/', trim($path));
        $folderPath = rtrim(str_replace('\\', '/', trim($folderPath)), '/');

        if ($path === '' || $folderPath === '') {
            return false;
        }

        $pathLower = mb_strtolower($path);
        $folderLower = mb_strtolower($folderPath);

        if (str_starts_with($pathLower, $folderLower.'/') || $pathLower === $folderLower) {
            return true;
        }

        // Dropbox may return path_lower while we store a display folder name.
        $folderLeaf = mb_strtolower(basename($folderPath));

        return $folderLeaf !== '' && str_contains($pathLower, '/'.$folderLeaf.'/');
    }

    /**
     * @return array{q?: string, filter?: string, page?: int}
     */
    private function recordingsListRedirectParams(Request $request): array
    {
        $params = $this->parseRecordingsListParams($request);
        $out = [];
        if ($params['q'] !== '') {
            $out['q'] = $params['q'];
        }
        if ($params['filter'] !== 'all') {
            $out['filter'] = $params['filter'];
        }
        if ($params['page'] > 1) {
            $out['page'] = $params['page'];
        }

        return $out;
    }

    /**
     * @return array{q: string, filter: string, page: int, perPage: int}
     */
    private function parseRecordingsListParams(Request $request): array
    {
        $q = $request->input('q', '');
        $q = is_string($q) ? trim($q) : '';

        $filter = $request->input('filter', 'all');
        $filter = is_string($filter) ? strtolower(trim($filter)) : 'all';
        if (! in_array($filter, ['all', 'video', 'other'], true)) {
            $filter = 'all';
        }

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(50, (int) $request->input('per_page', 10)));

        return [
            'q' => $q,
            'filter' => $filter,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<int, array{roomName: string, title: string|null}>  $meetingTitleHints
     * @return array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>
     */
    private function fetchUnityMeetRecordingsListFiles(array $ctx, User $user, string $q, array $meetingTitleHints): array
    {
        $api = new DropboxOrgApi((string) $ctx['token']);
        $folderPath = (string) $ctx['folderPath'];
        $restrict = (bool) ($ctx['restrictToUserRooms'] ?? false);
        /** @var array<int, string> $roomNames */
        $roomNames = $ctx['roomNames'] ?? [];

        $api->createFolder($folderPath);
        $this->relocateRecentDropboxRecordingsToFolder($api, $folderPath);

        if ($q !== '') {
            $files = $this->mapUnityFilteredSearchFiles(
                $api->search($folderPath, $q),
                $restrict,
                $roomNames,
                $user,
            );

            if ($files === []) {
                $entries = $api->listFolder($folderPath);
                $listed = $this->mapUnityFilteredDropboxFiles($entries, $restrict, $roomNames, $user);

                return $this->filterRecordingsByTextQuery($listed, $q, $meetingTitleHints);
            }

            return $files;
        }

        $entries = $api->listFolder($folderPath);

        return $this->mapUnityFilteredDropboxFiles($entries, $restrict, $roomNames, $user);
    }

    /**
     * @param  array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>  $files
     * @param  array<int, array{roomName: string, title: string|null}>  $meetingTitleHints
     * @return array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>
     */
    private function filterRecordingsByTextQuery(array $files, string $q, array $meetingTitleHints): array
    {
        $needle = strtolower(trim($q));
        if ($needle === '') {
            return $files;
        }

        $filtered = array_values(array_filter($files, function (array $file) use ($needle, $meetingTitleHints): bool {
            $name = strtolower((string) ($file['name'] ?? ''));
            $path = strtolower((string) ($file['path_display'] ?? ''));

            if (str_contains($name, $needle) || str_contains($path, $needle)) {
                return true;
            }

            foreach ($meetingTitleHints as $hint) {
                $room = strtolower(trim((string) ($hint['roomName'] ?? '')));
                $title = strtolower(trim((string) ($hint['title'] ?? '')));

                if ($room !== '' && str_contains($name, $room)) {
                    return true;
                }

                if ($title !== '' && str_contains($title, $needle)) {
                    return true;
                }
            }

            $modified = $file['client_modified'] ?? null;
            if (is_string($modified) && $modified !== '') {
                try {
                    $formatted = strtolower(Carbon::parse($modified)->format('M j, Y g:i A'));
                    if (str_contains($formatted, $needle)) {
                        return true;
                    }
                } catch (\Throwable) {
                    // ignore unparseable dates
                }
            }

            return false;
        }));

        return DropboxOrgApi::sortByModifiedDesc($filtered);
    }

    /**
     * @param  array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>  $files
     * @return array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>
     */
    private function filterRecordingsByType(array $files, string $filter): array
    {
        if ($filter === 'all') {
            return $files;
        }

        return array_values(array_filter($files, function (array $file) use ($filter): bool {
            $isVideo = $this->isRecordingVideoFile((string) ($file['name'] ?? ''));

            return $filter === 'video' ? $isVideo : ! $isVideo;
        }));
    }

    private function isRecordingVideoFile(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($ext, ['webm', 'mp4', 'mkv', 'mov', 'avi', 'm4v'], true);
    }

    /**
     * @param  array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>  $files
     * @return array{
     *   items: array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>,
     *   total: int,
     *   page: int,
     *   perPage: int,
     *   lastPage: int,
     *   from: int,
     *   to: int
     * }
     */
    private function paginateRecordingFiles(array $files, int $page, int $perPage): array
    {
        $total = count($files);
        $lastPage = max(1, (int) ceil($total / $perPage) ?: 1);
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($files, $offset, $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => $lastPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /**
     * @return array{linked: bool, token: ?string, folderPath: string, restrictToUserRooms: bool, roomNames: array<int, string>, source: ?string}
     */
    private function unityMeetRecordingDropboxContext(User $user): array
    {
        $roomNames = UserLivestream::where('user_id', $user->id)->pluck('room_name')->map(fn ($r) => trim((string) $r))->filter()->values()->all();

        $tokens = app(DropboxOAuthService::class);
        $org = Organization::forAuthUser($user);

        // Nonprofit dashboard accounts: use the org Dropbox folder and list all files
        // (same source of truth as Integrations → Dropbox).
        if ($user->hasNonprofitDashboardRole() && $org && ! empty($org->dropbox_refresh_token)) {
            $token = $tokens->getAccessTokenForOrganization($org);

            return [
                'linked' => ! empty($token),
                'token' => $token ?: null,
                'folderPath' => $this->recordingFolderPathForOrganization($org),
                'restrictToUserRooms' => false,
                'roomNames' => $roomNames,
                'source' => 'organization',
            ];
        }

        if (! empty($user->dropbox_refresh_token)) {
            $token = $tokens->getAccessTokenForUser($user);

            return [
                'linked' => ! empty($token),
                'token' => $token ?: null,
                'folderPath' => $this->recordingFolderPathForUser($user),
                'restrictToUserRooms' => false,
                'roomNames' => $roomNames,
                'source' => 'user',
            ];
        }

        if ($org && ! empty($org->dropbox_refresh_token)) {
            $token = $tokens->getAccessTokenForOrganization($org);

            return [
                'linked' => ! empty($token),
                'token' => $token ?: null,
                'folderPath' => $this->recordingFolderPathForOrganization($org),
                'restrictToUserRooms' => true,
                'roomNames' => $roomNames,
                'source' => 'organization',
            ];
        }

        return [
            'linked' => false,
            'token' => null,
            'folderPath' => '',
            'restrictToUserRooms' => false,
            'roomNames' => $roomNames,
            'source' => null,
        ];
    }

    /**
     * VDO.Ninja Dropbox filenames often omit the room slug — also match stream ids and meeting titles.
     */
    private function recordingMatchesUserMeetings(string $filename, User $user, array $roomNames): bool
    {
        $haystack = strtolower($filename);

        foreach ($roomNames as $room) {
            $r = strtolower(trim((string) $room));
            if ($r !== '' && str_contains($haystack, $r)) {
                return true;
            }
        }

        $livestreams = UserLivestream::query()
            ->where('user_id', $user->id)
            ->get(['id', 'room_name', 'title']);

        foreach ($livestreams as $livestream) {
            $streamPath = 'ls_'.$livestream->id;
            if (str_contains($haystack, strtolower($streamPath))) {
                return true;
            }

            $room = strtolower(trim((string) $livestream->room_name));
            if ($room !== '' && str_contains($haystack, $room)) {
                return true;
            }

            $title = strtolower(trim((string) ($livestream->title ?? '')));
            if ($title !== '' && strlen($title) >= 4 && str_contains($haystack, $title)) {
                return true;
            }
        }

        return false;
    }

    private function relocateRecentDropboxRecordingsToFolder(DropboxOrgApi $api, string $folderPath): void
    {
        foreach ($api->listRecentRecordingsAtRoot() as $file) {
            $from = $file['path_display'] ?? '';
            $name = $file['name'] ?? '';
            if ($from === '' || $name === '') {
                continue;
            }
            $api->moveFile($from, rtrim($folderPath, '/').'/'.$name);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function unityMeetRecordingFileMatchesContext(string $path, array $ctx, User $user): bool
    {
        if (! ($ctx['restrictToUserRooms'] ?? false)) {
            return true;
        }
        /** @var array<int, string> $rooms */
        $rooms = $ctx['roomNames'] ?? [];

        return $this->recordingMatchesUserMeetings(basename($path), $user, $rooms);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>
     */
    private function mapUnityFilteredDropboxFiles(array $entries, bool $restrictToUserRooms, array $roomNames, User $user): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (($entry['tag'] ?? '') !== 'file') {
                continue;
            }
            $row = [
                'name' => $entry['name'] ?? '',
                'path_display' => $entry['path_display'] ?? '',
                'size' => (int) ($entry['size'] ?? 0),
                'client_modified' => $entry['client_modified'] ?? null,
            ];
            if ($restrictToUserRooms && ! $this->recordingMatchesUserMeetings((string) $row['name'], $user, $roomNames)) {
                continue;
            }
            $out[] = $row;
        }

        // Same folder as /integrations/dropbox: if filename filter hid everything, show all videos in the folder.
        if ($out === [] && $restrictToUserRooms) {
            foreach ($entries as $entry) {
                if (($entry['tag'] ?? '') !== 'file') {
                    continue;
                }
                $out[] = [
                    'name' => $entry['name'] ?? '',
                    'path_display' => $entry['path_display'] ?? '',
                    'size' => (int) ($entry['size'] ?? 0),
                    'client_modified' => $entry['client_modified'] ?? null,
                ];
            }
        }

        return DropboxOrgApi::sortByModifiedDesc($out);
    }

    /**
     * @param  array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>  $files
     * @return array<int, array{name: string, path_display: string, size: int, client_modified: ?string}>
     */
    private function mapUnityFilteredSearchFiles(array $files, bool $restrictToUserRooms, array $roomNames, User $user): array
    {
        if (! $restrictToUserRooms) {
            return $files;
        }

        $out = [];
        foreach ($files as $file) {
            if ($this->recordingMatchesUserMeetings((string) ($file['name'] ?? ''), $user, $roomNames)) {
                $out[] = $file;
            }
        }

        $result = $out !== [] ? $out : $files;

        return DropboxOrgApi::sortByModifiedDesc($result);
    }

    private function recordingFolderPathForUser(User $user): string
    {
        $folderName = $user->dropbox_folder_name ? trim((string) $user->dropbox_folder_name) : 'BIU Meeting Recordings';
        $folderName = trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $folderName));
        $folderName = trim($folderName, " \t\n\r\0\x0B.") ?: 'BIU Meeting Recordings';

        return '/' . $folderName;
    }

    private function recordingFolderPathForOrganization(Organization $organization): string
    {
        $folderName = $organization->dropbox_folder_name ? trim((string) $organization->dropbox_folder_name) : 'BIU Meeting Recordings';
        $folderName = trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $folderName));
        $folderName = trim($folderName, " \t\n\r\0\x0B.") ?: 'BIU Meeting Recordings';

        return '/' . $folderName;
    }

    /**
     * Dispatch invitation email jobs for each participant on a scheduled meeting.
     */
    private function sendScheduledMeetingInvitations(UserLivestream $livestream, string $notifyVia = UnityMeetInviteNotifyVia::BOTH): void
    {
        $settings = is_array($livestream->settings) ? $livestream->settings : [];
        $emails = LivestreamParticipantEmails::fromSettings($settings);
        $channel = UnityMeetInviteNotifyVia::normalize($notifyVia);

        foreach ($emails as $email) {
            $this->dispatchParticipantInvitation($livestream, $email, $channel);
        }
    }

    private function dispatchParticipantInvitation(
        UserLivestream $livestream,
        string $email,
        string $notifyVia = UnityMeetInviteNotifyVia::EMAIL,
    ): void {
        $channel = UnityMeetInviteNotifyVia::normalize($notifyVia);
        $billingUser = User::query()->find($livestream->user_id);

        if (UnityMeetInviteNotifyVia::usesEmailCredits($channel) && $billingUser) {
            UserEmailCredits::consume($billingUser);
            SendUnityMeetInvitationEmail::dispatch($livestream->id, $email, (int) $livestream->user_id);
        }

        if (UnityMeetInviteNotifyVia::usesBiuNotification($channel)) {
            // Synchronous: inbox + push must not depend on a queue worker for host invites.
            app(UnityMeetBiuNotifier::class)->notifyByEmail($livestream, $email);
        }
    }

    private function userForInviteEmail(string $email): ?User
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return null;
        }

        return User::query()->whereRaw('LOWER(email) = ?', [$normalized])->first();
    }

    private function inviteSentSuccessMessage(string $notifyVia): string
    {
        return match (UnityMeetInviteNotifyVia::normalize($notifyVia)) {
            UnityMeetInviteNotifyVia::BIU => 'BIU notification sent.',
            UnityMeetInviteNotifyVia::BOTH => 'Email and BIU notification sent.',
            default => 'Invitation email sent.',
        };
    }

    private function scheduledInviteSuccessMessage(string $notifyVia, int $count): string
    {
        $suffix = $count === 1 ? '' : 's';

        return match (UnityMeetInviteNotifyVia::normalize($notifyVia)) {
            UnityMeetInviteNotifyVia::BIU => "Meeting scheduled! {$count} BIU notification{$suffix} sent.",
            UnityMeetInviteNotifyVia::BOTH => "Meeting scheduled! {$count} email and BIU notification{$suffix} sent.",
            default => "Meeting scheduled! {$count} invitation email{$suffix} on the way.",
        };
    }

    /**
     * @return array{emailPackages: array<int, array<string, mixed>>, stripeMinCheckoutUsd: float}
     */
    private function emailPurchaseProps(): array
    {
        return [
            'emailPackages' => EmailPackageCatalog::activeForCheckout(),
            'stripeMinCheckoutUsd' => EmailPackageCatalog::stripeMinCheckoutUsd(),
        ];
    }
}

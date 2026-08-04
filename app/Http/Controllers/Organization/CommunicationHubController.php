<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\CommunicationHub\UpdateCommunicationSettingsRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPageService;
use App\Services\CommunicationHub\CommunicationHubPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CommunicationHubController extends Controller
{
    public function __construct(
        private readonly CommunicationHubPageService $pageService,
        private readonly CommunicationHubPermissionService $permissions,
    ) {}

    /**
     * Resolve the authenticated user's organisation (or abort 403).
     */
    protected function resolveOrg(): Organization
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            abort(403, 'Unauthenticated.');
        }

        $org = Organization::forAuthUser($user);

        if (! $org) {
            abort(403, 'No organization linked to your account.');
        }

        return $org;
    }

    /**
     * Communication Hub dashboard overview for org admins: promotes any due scheduled
     * announcements, seeds default discussion categories, and renders preview lists
     * for both the announcements and discussions tabs.
     */
    public function index(Request $request)
    {
        $org = $this->resolveOrg();

        /** @var User $user */
        $user = Auth::user();

        $tab = (string) $request->query('tab', 'announcements');
        if (! in_array($tab, ['announcements', 'discussions'], true)) {
            $tab = 'announcements';
        }

        // hubProps promotes scheduled announcements and seeds default categories once.
        $props = $this->pageService->hubProps($org, $user, [
            'activeTab' => $tab,
            'announcementsLimit' => 5,
            'discussionsLimit' => 5,
        ]);

        return Inertia::render('Organization/CommunicationHub/Index', $props);
    }

    /**
     * Persist communication hub settings (posting/comment/reaction/reporting toggles) for the org.
     */
    public function updateSettings(UpdateCommunicationSettingsRequest $request)
    {
        $org = $this->resolveOrg();

        /** @var User $user */
        $user = Auth::user();

        if (! $this->permissions->canManageAnnouncements($user, $org)) {
            abort(403, 'You are not allowed to manage this organization\'s communication settings.');
        }

        $settings = array_merge($this->permissions->getSettings($org), $request->validated());

        $org->update(['communication_settings' => $settings]);

        return redirect()->back()->with('success', 'Communication settings updated.');
    }

    /**
     * Organization Communication Hub settings page.
     */
    public function settings()
    {
        $org = $this->resolveOrg();

        /** @var User $user */
        $user = Auth::user();

        if (! $this->permissions->canManageAnnouncements($user, $org)) {
            abort(403);
        }

        $settings = $this->permissions->getSettings($org);

        return Inertia::render('Organization/CommunicationHub/Settings', [
            'organization' => ['id' => $org->id, 'name' => $org->name],
            'settings' => $settings,
        ]);
    }

    /**
     * Optional dedicated public page for deep-linking into an org's Communication Hub
     * (the same data also powers a tab on the public organization profile page).
     */
    public function publicShow(Request $request, string $slug)
    {
        $organization = $this->resolvePublicOrganization($slug);

        $tab = (string) $request->query('tab', 'announcements');
        if (! in_array($tab, ['announcements', 'discussions'], true)) {
            $tab = 'announcements';
        }

        $props = $this->pageService->hubProps($organization, Auth::user(), [
            'activeTab' => $tab,
            'announcementsLimit' => 10,
            'discussionsLimit' => 10,
        ]);

        return Inertia::render('Organization/CommunicationHub/PublicIndex', $props);
    }

    /**
     * Resolve an approved, registered organization by its public (user) slug.
     */
    private function resolvePublicOrganization(string $slug): Organization
    {
        $user = User::where('slug', $slug)->first();

        $organization = $user
            ? Organization::where('user_id', $user->id)
                ->where('registration_status', 'approved')
                ->first()
            : null;

        if (! $organization) {
            abort(404, 'Organization not found.');
        }

        return $organization;
    }
}

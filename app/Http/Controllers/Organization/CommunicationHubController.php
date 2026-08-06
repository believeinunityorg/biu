<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Organization\Concerns\ResolvesCommunicationHubOrganization;
use App\Http\Requests\CommunicationHub\UpdateCommunicationSettingsRequest;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPageService;
use App\Services\CommunicationHub\CommunicationHubPermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CommunicationHubController extends Controller
{
    use ResolvesCommunicationHubOrganization;

    public function __construct(
        private readonly CommunicationHubPageService $pageService,
        private readonly CommunicationHubPermissionService $permissions,
    ) {}

    /**
     * Communication Hub dashboard overview for org admins: promotes any due scheduled
     * announcements, seeds default discussion categories, and renders preview lists
     * for both the announcements and discussions tabs.
     */
    public function index(Request $request)
    {
        $org = $this->resolveOwnedOrg();

        /** @var User $user */
        $user = Auth::user();

        $tab = (string) $request->query('tab', 'announcements');
        if (! in_array($tab, ['announcements', 'discussions', 'settings'], true)) {
            $tab = 'announcements';
        }

        // Access settings live on the hub itself (not the global Settings area).
        if ($tab === 'settings' && ! $this->permissions->canManageAnnouncements($user, $org)) {
            abort(403);
        }

        $props = $this->pageService->hubProps($org, $user, [
            'activeTab' => $tab,
            'announcementsLimit' => 5,
            'discussionsLimit' => 5,
            'community' => false,
        ]);

        return Inertia::render('Organization/CommunicationHub/Index', $props);
    }

    /**
     * Persist communication hub settings (view/post/comment/reaction/reporting toggles) for the org.
     */
    public function updateSettings(UpdateCommunicationSettingsRequest $request)
    {
        $org = $this->resolveOwnedOrg();

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
     * Legacy settings URL — settings now live as a tab on the Communication Hub.
     */
    public function settings()
    {
        $org = $this->resolveOwnedOrg();

        /** @var User $user */
        $user = Auth::user();

        if (! $this->permissions->canManageAnnouncements($user, $org)) {
            abort(403);
        }

        return redirect()->route('org.communication-hub.index', ['tab' => 'settings']);
    }
}

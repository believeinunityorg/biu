<?php

namespace App\Http\Controllers\Supporter;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationDiscussion;
use App\Models\OrganizationDiscussionCategory;
use App\Models\User;
use App\Models\UserFavoriteOrganization;
use App\Services\CommunicationHub\CommunicationHubPermissionService;
use App\Services\CommunicationHub\CommunicationHubPresenter;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Supporter dashboard entry for Announcements & Discussion boards of followed organizations.
 */
class SupporterCommunicationHubController extends Controller
{
    public function __construct(
        private readonly CommunicationHubPermissionService $permissions,
        private readonly CommunicationHubPresenter $presenter,
    ) {}

    public function index()
    {
        /** @var User $user */
        $user = Auth::user();

        $favoriteOrgIds = UserFavoriteOrganization::query()
            ->where('user_id', $user->id)
            ->whereNotNull('organization_id')
            ->pluck('organization_id')
            ->unique()
            ->values()
            ->all();

        $organizations = Organization::query()
            ->whereIn('id', $favoriteOrgIds)
            ->where('registration_status', 'approved')
            ->with('user:id,slug,name,image')
            ->orderBy('name')
            ->get();

        $boards = $organizations->map(function (Organization $org) use ($user) {
            $canViewAnnouncements = $this->permissions->canViewAnnouncements($user, $org);
            $canViewDiscussions = $this->permissions->canViewDiscussions($user, $org);
            $canCreateDiscussion = $this->permissions->canCreateDiscussion($user, $org);
            $settings = $this->permissions->getSettings($org);

            $recentAnnouncements = $canViewAnnouncements
                ? OrganizationAnnouncement::query()
                    ->forOrganization($org->id)
                    ->visibleToPublic()
                    ->with(['creator:id,name,image'])
                    ->pinnedFirst()
                    ->limit(3)
                    ->get()
                    ->map(fn (OrganizationAnnouncement $a) => $this->presenter->announcementToArray($a))
                    ->values()
                    ->all()
                : [];

            $recentDiscussions = $canViewDiscussions
                ? OrganizationDiscussion::query()
                    ->where('organization_id', $org->id)
                    ->visibleToPublic()
                    ->with(['author:id,name,image', 'category:id,name,slug,color'])
                    ->orderByDesc('is_pinned')
                    ->orderByDesc('last_activity_at')
                    ->limit(3)
                    ->get()
                    ->map(fn (OrganizationDiscussion $d) => $this->presenter->discussionToArray($d))
                    ->values()
                    ->all()
                : [];

            $categories = $canCreateDiscussion
                ? OrganizationDiscussionCategory::query()
                    ->where('organization_id', $org->id)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (OrganizationDiscussionCategory $c) => $this->presenter->categoryToArray($c))
                    ->values()
                    ->all()
                : [];

            return [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->user?->slug,
                'image' => $org->user?->image,
                'hub_url' => $org->user?->slug
                    ? route('organizations.communication-hub', $org->user->slug)
                    : null,
                'can_view_announcements' => $canViewAnnouncements,
                'can_view_discussions' => $canViewDiscussions,
                'can_create_discussion' => $canCreateDiscussion,
                'announcement_visibility' => $settings['announcement_visibility'],
                'discussion_visibility' => $settings['discussion_visibility'],
                'allow_followers_to_post' => (bool) ($settings['allow_followers_to_post'] ?? true),
                'allow_members_to_post' => (bool) ($settings['allow_members_to_post'] ?? true),
                'categories' => $categories,
                'recent_announcements' => $recentAnnouncements,
                'recent_discussions' => $recentDiscussions,
            ];
        })->filter(fn (array $board) => $board['slug'] !== null)->values()->all();

        return Inertia::render('Supporter/CommunicationHub/Index', [
            'boards' => $boards,
            'visibilityAudiences' => config('communication_hub.visibility_audiences', []),
        ]);
    }
}

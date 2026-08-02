<?php

namespace App\Http\Controllers\Community;

use App\Enums\CommunityContentType;
use App\Enums\MembershipAccountType;
use App\Http\Controllers\Controller;
use App\Models\CareAlliance;
use App\Models\CommunityContent;
use App\Models\Organization;
use App\Services\CommunityContentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommunityParentController extends Controller
{
    public function __construct(
        private readonly CommunityContentService $contentService,
    ) {}

    public function show(Request $request, string $parentType, int $parentId): Response
    {
        $parent = $this->contentService->resolveParent($parentType, $parentId);
        abort_unless($parent instanceof Organization || $parent instanceof CareAlliance, 404);

        $user = $request->user();
        $tab = $request->string('tab')->toString() ?: 'discussion';
        if (! in_array($tab, ['discussion', 'announcements'], true)) {
            $tab = 'discussion';
        }

        $morphType = $this->contentService->parentMorphType($parent);

        $discussions = $this->listContent($morphType, $parentId, CommunityContentType::Discussion);
        $announcements = $this->listContent($morphType, $parentId, CommunityContentType::Announcement);

        if ($parent instanceof Organization) {
            $parent->loadMissing('user:id,slug');
            $backUrl = route('organizations.show', $parent->user?->slug ?? $parent->id);
        } else {
            $backUrl = route('alliances.show', $parent->slug);
        }

        return Inertia::render('Community/ParentShow', [
            'parent' => [
                'type' => $morphType,
                'id' => $parent->id,
                'name' => $parent->name ?? 'Community',
                'kind' => $parent instanceof Organization ? 'organization' : 'alliance',
            ],
            'tab' => $tab,
            'discussions' => $discussions,
            'announcements' => $announcements,
            'canCreateDiscussion' => $user ? $this->contentService->canCreateDiscussion($user, $parent) : false,
            'canCreateAnnouncement' => $user ? $this->contentService->canCreateAnnouncement($user, $parent) : false,
            'canModerate' => $user ? $this->contentService->canModerate($user, $parent) : false,
            'backUrl' => $backUrl,
            'reportReasons' => config('community.report_reasons'),
            'categories' => [
                'General',
                'Prayer',
                'Bible Study',
                'Events',
                'Volunteering',
                'Support',
                'Other',
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listContent(string $parentType, int $parentId, CommunityContentType $type): array
    {
        return CommunityContent::query()
            ->where('parent_type', $parentType)
            ->where('parent_id', $parentId)
            ->where('type', $type->value)
            ->visiblePublic()
            ->with(['author:id,name'])
            ->withCount(['replies' => fn ($q) => $q->where('visibility_status', 'visible')])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(50)
            ->get()
            ->map(fn (CommunityContent $c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'type' => $c->type?->value ?? $c->type,
                'title' => $c->title,
                'body' => $c->body,
                'category' => $c->category,
                'is_pinned' => (bool) $c->is_pinned,
                'is_locked' => (bool) $c->is_locked,
                'replies_count' => $c->replies_count ?? 0,
                'author' => $c->author ? ['id' => $c->author->id, 'name' => $c->author->name] : null,
                'cover_image' => $c->cover_image ? asset('storage/'.$c->cover_image) : null,
            ])
            ->values()
            ->all();
    }
}

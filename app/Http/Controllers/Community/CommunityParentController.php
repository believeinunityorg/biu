<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\CareAlliance;
use App\Models\Organization;
use App\Services\CommunityContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Legacy standalone community feed URLs redirect into org / alliance public pages
 * so Discussion & Announcements stay inside that layout.
 */
class CommunityParentController extends Controller
{
    public function __construct(
        private readonly CommunityContentService $contentService,
    ) {}

    public function show(Request $request, string $parentType, int $parentId): RedirectResponse
    {
        $parent = $this->contentService->resolveParent($parentType, $parentId);
        abort_unless($parent instanceof Organization || $parent instanceof CareAlliance, 404);

        $tab = $request->string('tab')->toString() ?: 'discussion';
        if (! in_array($tab, ['discussion', 'announcements'], true)) {
            $tab = 'discussion';
        }

        if ($parent instanceof Organization) {
            $parent->loadMissing('user:id,slug');
            $slug = $parent->user?->slug ?? $parent->id;

            return redirect()->to(route('organizations.show', $slug).'?tab='.$tab);
        }

        return redirect()->to(route('alliances.show', $parent->slug).'?tab='.$tab);
    }
}

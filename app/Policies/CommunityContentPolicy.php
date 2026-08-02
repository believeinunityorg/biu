<?php

namespace App\Policies;

use App\Enums\CommunityContentType;
use App\Models\CommunityContent;
use App\Models\User;
use App\Services\CommunityContentService;

class CommunityContentPolicy
{
    public function __construct(
        private readonly CommunityContentService $contentService,
    ) {}

    public function view(?User $user, CommunityContent $content): bool
    {
        if ($content->isPubliclyVisible()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        $parent = $content->parent;

        return $parent && $this->contentService->canModerate($user, $parent);
    }

    public function update(User $user, CommunityContent $content): bool
    {
        if ((int) $content->author_id === (int) $user->id) {
            return true;
        }

        $parent = $content->parent;

        return $parent && $this->contentService->canModerate($user, $parent);
    }

    public function moderate(User $user, CommunityContent $content): bool
    {
        $parent = $content->parent;

        return $parent && $this->contentService->canModerate($user, $parent);
    }

    public function createAnnouncement(User $user): bool
    {
        return true; // parent checked in controller
    }

    public function createDiscussion(User $user): bool
    {
        return true;
    }
}

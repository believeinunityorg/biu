<?php

namespace App\Support;

use App\Models\CommunityOrganizationType;

/**
 * @deprecated Prefer CommunityOrganizationType model. Kept as a thin DB-backed helper.
 */
final class CommunityOrganizationTypes
{
    /**
     * @return list<array{id: int, slug: string, name: string, label: string}>
     */
    public static function forSelect(): array
    {
        return CommunityOrganizationType::forSelect();
    }

    /**
     * @return list<int>
     */
    public static function ids(): array
    {
        return CommunityOrganizationType::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}

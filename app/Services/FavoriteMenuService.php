<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\User;
use App\Models\UserFavoriteMenu;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class FavoriteMenuService
{
    public const MAX_QUICK_FAVORITES = 8;

    public const QUICK_GRID_LIMIT = 6;

    public const MAX_BOTTOM_NAV_CUSTOM_SLOTS = 2;

    /** @var list<string> */
    public const DEFAULT_QUICK_KEYS = [
        'donate',
        'gift_cards',
        'organizations',
        'events',
        'marketplace',
        'merchant_deals',
        'challenge_hub',
        'chat',
    ];

    /** @var array<int, string> */
    public const DEFAULT_BOTTOM_NAV_SLOTS = [
        1 => 'home',
        2 => 'organizations',
        4 => 'chat',
    ];

    /** @var array<int, string> */
    public const DEFAULT_BOTTOM_NAV_SLOTS_ORG = [
        1 => 'home',
        2 => 'dashboard',
        4 => 'chat',
    ];

    /** @var list<string> */
    public const DEFAULT_QUICK_KEYS_ORG = [
        'donate',
        'events',
        'chat',
        'unity_meet',
        'marketplace',
        'groups',
        'gift_cards',
        'social_feed',
    ];

    /** @var list<string> */
    public const INTEREST_TAG_OPTIONS = [
        'supporting_organizations',
        'volunteering',
        'shopping_deals',
        'gift_cards',
        'faith_ministry',
        'education',
        'community_events',
        'business_networking',
        'companion_hub',
        'content_creation',
    ];

    public function payloadForUser(User $user): array
    {
        $this->ensureDefaults($user);

        $catalog = $this->visibleCatalogForUser($user);
        $favoriteKeys = $this->favoriteKeysForUser($user);
        $quickFavorites = $this->quickFavoritesForUser($user, $catalog);
        $bottomNav = $this->bottomNavForUser($user, $catalog);

        return [
            'favoriteMenuKeys' => $favoriteKeys,
            'quickFavorites' => $quickFavorites,
            'bottomNavSlots' => $bottomNav,
            'menuCatalog' => $this->catalogGrouped($catalog, $user),
            'needsOnboarding' => $this->isSupporterUser($user) && $user->favorites_onboarding_completed_at === null,
            'canCustomize' => $this->mobileNavRoleKey($user) !== null,
            'canCustomizeQuick' => $this->isSupporterUser($user),
            'interestOptions' => $this->interestOptionsForFrontend(),
            'limits' => [
                'quickMax' => self::MAX_QUICK_FAVORITES,
                'quickGrid' => self::QUICK_GRID_LIMIT,
            ],
        ];
    }

    public function ensureDefaults(User $user): void
    {
        $this->ensureMenuCatalog();

        if ($this->mobileNavRoleKey($user) === null) {
            return;
        }

        $hasAny = UserFavoriteMenu::query()->where('user_id', $user->id)->exists();
        if ($hasAny) {
            return;
        }

        $this->seedDefaults($user);
    }

    /**
     * Ensure menu catalog rows exist (migrations create the table; seeding fills it).
     */
    public function ensureMenuCatalog(): void
    {
        if (MenuItem::query()->exists()) {
            return;
        }

        (new \Database\Seeders\MenuItemSeeder)->run();
    }

    public function seedDefaults(User $user): void
    {
        $this->ensureMenuCatalog();

        $quickKeys = $this->defaultQuickKeysForUser($user);
        $bottomSlots = $this->defaultBottomNavSlotsForUser($user);

        DB::transaction(function () use ($user, $quickKeys, $bottomSlots) {
            foreach ($quickKeys as $index => $menuKey) {
                $this->createFavorite($user, $menuKey, [
                    'sort_order' => $index + 1,
                    'placement' => UserFavoriteMenu::PLACEMENT_QUICK,
                ]);
            }

            foreach ($bottomSlots as $slot => $menuKey) {
                $this->createFavorite($user, $menuKey, [
                    'sort_order' => $slot,
                    'placement' => UserFavoriteMenu::PLACEMENT_BOTTOM_NAV,
                    'bottom_nav_slot' => $slot,
                ]);
            }
        });
    }

    /**
     * @param  list<string>  $interests
     */
    public function seedFromInterests(User $user, array $interests): void
    {
        $this->ensureMenuCatalog();

        $interests = array_values(array_intersect($interests, self::INTEREST_TAG_OPTIONS));
        if ($interests === []) {
            $this->seedDefaults($user);
            $user->forceFill(['favorites_onboarding_completed_at' => now()])->save();

            return;
        }

        $matchedKeys = MenuItem::query()
            ->where('is_active', true)
            ->where('supporter_visible', true)
            ->get()
            ->filter(function (MenuItem $item) use ($interests) {
                $tags = $item->interest_tags ?? [];

                return count(array_intersect($interests, $tags)) > 0;
            })
            ->sortBy('sort_order')
            ->pluck('menu_key')
            ->unique()
            ->values()
            ->filter(fn (string $menuKey) => $this->menuKeyExists($menuKey));

        $quickKeys = $matchedKeys->take(self::MAX_QUICK_FAVORITES);
        if ($quickKeys->isEmpty()) {
            $quickKeys = collect(self::DEFAULT_QUICK_KEYS)->filter(fn (string $key) => $this->menuKeyExists($key));
        }

        DB::transaction(function () use ($user, $quickKeys) {
            UserFavoriteMenu::query()->where('user_id', $user->id)->delete();

            foreach ($quickKeys->values() as $index => $menuKey) {
                $this->createFavorite($user, $menuKey, [
                    'sort_order' => $index + 1,
                    'placement' => UserFavoriteMenu::PLACEMENT_QUICK,
                ]);
            }

            $bottomDefaults = $this->defaultBottomNavSlotsForUser($user);
            if (! $this->walletVisibleForUser($user)) {
                if ($quickKeys->contains('marketplace')) {
                    $bottomDefaults[2] = 'marketplace';
                } elseif ($quickKeys->contains('unity_meet')) {
                    $bottomDefaults[2] = 'unity_meet';
                }
            }

            foreach ($bottomDefaults as $slot => $menuKey) {
                $this->createFavorite($user, $menuKey, [
                    'sort_order' => $slot,
                    'placement' => UserFavoriteMenu::PLACEMENT_BOTTOM_NAV,
                    'bottom_nav_slot' => $slot,
                ]);
            }

            $user->forceFill(['favorites_onboarding_completed_at' => now()])->save();
        });
    }

    /**
     * @param  list<string>  $menuKeys
     */
    public function syncQuickFavorites(User $user, array $menuKeys): void
    {
        $menuKeys = array_values(array_unique(array_slice($menuKeys, 0, self::MAX_QUICK_FAVORITES)));
        $catalogKeys = $this->visibleCatalogForUser($user)->pluck('menu_key')->all();
        $menuKeys = array_values(array_intersect($menuKeys, $catalogKeys));

        DB::transaction(function () use ($user, $menuKeys) {
            UserFavoriteMenu::query()
                ->where('user_id', $user->id)
                ->where('placement', UserFavoriteMenu::PLACEMENT_QUICK)
                ->delete();

            foreach ($menuKeys as $index => $menuKey) {
                $this->createFavorite($user, $menuKey, [
                    'sort_order' => $index + 1,
                    'placement' => UserFavoriteMenu::PLACEMENT_QUICK,
                ]);
            }
        });
    }

    /**
     * @param  array<int, string>  $slots  e.g. [1 => 'home', 2 => 'marketplace', 4 => 'chat']
     */
    public function syncBottomNavSlots(User $user, array $slots): void
    {
        $allowedSlots = [1, 2, 4];
        $catalog = $this->visibleCatalogForUser($user)->keyBy('menu_key');
        $defaultSlots = $this->defaultBottomNavSlotsForUser($user);

        DB::transaction(function () use ($user, $slots, $allowedSlots, $catalog, $defaultSlots) {
            UserFavoriteMenu::query()
                ->where('user_id', $user->id)
                ->where('placement', UserFavoriteMenu::PLACEMENT_BOTTOM_NAV)
                ->delete();

            foreach ($allowedSlots as $slot) {
                $menuKey = $this->resolveBottomNavMenuKey($user, $slot, $slots, $catalog, $defaultSlots);
                if ($menuKey === null) {
                    continue;
                }

                $this->createFavorite($user, $menuKey, [
                    'sort_order' => $slot,
                    'placement' => UserFavoriteMenu::PLACEMENT_BOTTOM_NAV,
                    'bottom_nav_slot' => $slot,
                ]);
            }
        });
    }

    public function toggleFavorite(User $user, string $menuKey): bool
    {
        $existing = UserFavoriteMenu::query()
            ->where('user_id', $user->id)
            ->where('menu_key', $menuKey)
            ->where('placement', UserFavoriteMenu::PLACEMENT_QUICK)
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        $count = UserFavoriteMenu::query()
            ->where('user_id', $user->id)
            ->where('placement', UserFavoriteMenu::PLACEMENT_QUICK)
            ->count();

        if ($count >= self::MAX_QUICK_FAVORITES) {
            return false;
        }

        if (! $this->visibleCatalogForUser($user)->contains('menu_key', $menuKey)) {
            return false;
        }

        UserFavoriteMenu::query()->create([
            'user_id' => $user->id,
            'menu_key' => $menuKey,
            'sort_order' => $count + 1,
            'placement' => UserFavoriteMenu::PLACEMENT_QUICK,
            'is_active' => true,
        ]);

        return true;
    }

    private function menuKeyExists(string $menuKey): bool
    {
        return MenuItem::query()->where('menu_key', $menuKey)->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createFavorite(User $user, string $menuKey, array $attributes): bool
    {
        if (! $this->menuKeyExists($menuKey)) {
            return false;
        }

        UserFavoriteMenu::query()->create(array_merge([
            'user_id' => $user->id,
            'menu_key' => $menuKey,
            'is_active' => true,
        ], $attributes));

        return true;
    }

    /**
     * @param  array<int, string>  $slots
     * @param  Collection<string, MenuItem>  $catalog
     * @param  array<int, string>  $defaultSlots
     */
    private function resolveBottomNavMenuKey(
        User $user,
        int $slot,
        array $slots,
        Collection $catalog,
        array $defaultSlots,
    ): ?string {
        $candidates = array_values(array_unique(array_filter([
            $slots[$slot] ?? null,
            $defaultSlots[$slot] ?? null,
            'home',
            'chat',
            'organizations',
        ])));

        foreach ($candidates as $menuKey) {
            if ($menuKey === 'wallet' && ! $this->walletVisibleForUser($user)) {
                continue;
            }

            $item = $catalog->get($menuKey);
            if ($item && $item->bottom_nav_eligible && $this->menuKeyExists($menuKey)) {
                return $menuKey;
            }
        }

        return $catalog
            ->filter(fn (MenuItem $item) => $item->bottom_nav_eligible && $this->menuKeyExists($item->menu_key))
            ->first()
            ?->menu_key;
    }

    /**
     * @return list<string>
     */
    public function favoriteKeysForUser(User $user): array
    {
        return UserFavoriteMenu::query()
            ->where('user_id', $user->id)
            ->where('placement', UserFavoriteMenu::PLACEMENT_QUICK)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('menu_key')
            ->all();
    }

    /**
     * @return Collection<int, MenuItem>
     */
    public function visibleCatalogForUser(User $user): Collection
    {
        $query = MenuItem::query()->where('is_active', true);

        if ($user->role === 'user') {
            $query->where('supporter_visible', true);
        } elseif ($user->hasNonprofitDashboardRole()) {
            $query->where('org_visible', true);
        } elseif ($user->role === 'admin') {
            $query->where('admin_visible', true);
        } else {
            return collect();
        }

        return $query->orderBy('sort_order')->get()->filter(function (MenuItem $item) use ($user) {
            if ($item->menu_key === 'wallet') {
                return $this->walletVisibleForUser($user);
            }

            return true;
        })->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quickFavoritesForUser(User $user, Collection $catalog): array
    {
        $keys = $this->favoriteKeysForUser($user);

        return collect($keys)
            ->map(fn (string $key) => $catalog->firstWhere('menu_key', $key))
            ->filter()
            ->take(self::QUICK_GRID_LIMIT)
            ->map(fn (MenuItem $item) => $this->serializeMenuItem($item, $user))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bottomNavForUser(User $user, Collection $catalog): array
    {
        $slots = UserFavoriteMenu::query()
            ->where('user_id', $user->id)
            ->where('placement', UserFavoriteMenu::PLACEMENT_BOTTOM_NAV)
            ->orderBy('bottom_nav_slot')
            ->get()
            ->keyBy('bottom_nav_slot');

        $result = [];
        foreach ([1, 2, 3, 4, 5] as $slot) {
            if ($slot === 3) {
                $result[] = [
                    'slot' => 3,
                    'menuKey' => 'my_favorites',
                    'title' => 'Favorites',
                    'href' => null,
                    'icon' => 'Star',
                    'isHub' => true,
                ];
                continue;
            }

            if ($slot === 5) {
                $profile = $this->resolveProfileItem($user, $catalog);
                $result[] = array_merge($profile, ['slot' => 5]);

                continue;
            }

            $favorite = $slots->get($slot);
            $defaultSlots = $this->defaultBottomNavSlotsForUser($user);
            $menuKey = $favorite?->menu_key ?? ($defaultSlots[$slot] ?? 'home');
            $item = $catalog->firstWhere('menu_key', $menuKey);
            if (! $item) {
                $item = $catalog->firstWhere('menu_key', $defaultSlots[$slot] ?? 'home');
            }

            if ($item) {
                $result[] = array_merge($this->serializeMenuItem($item, $user), ['slot' => $slot]);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveProfileItem(User $user, Collection $catalog): array
    {
        return [
            'menuKey' => 'profile',
            'title' => 'Profile',
            'href' => $this->profileHrefForUser($user),
            'icon' => 'User',
            'activePathPrefix' => $this->profileActivePathForUser($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMenuItem(MenuItem $item, User $user): array
    {
        $serialized = [
            'menuKey' => $item->menu_key,
            'title' => $item->menu_key === 'gift_cards' ? 'My Gift Cards' : $item->title,
            'href' => $this->resolveHref($item, $user),
            'icon' => $item->icon,
            'category' => $item->category,
            'activePathPrefix' => $this->activePathForItem($item, $user),
            'requiresAuth' => $item->requires_auth,
            'bottomNavEligible' => $item->bottom_nav_eligible,
        ];

        if ($item->menu_key === 'wallet' && $this->walletVisibleForUser($user)) {
            $serialized['opensWallet'] = true;
            $serialized['href'] = null;
            $serialized['activePathPrefix'] = null;
        }

        return $serialized;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function catalogGrouped(Collection $catalog, User $user): array
    {
        $labels = [
            'home' => 'Home',
            'community' => 'Community',
            'give' => 'Give',
            'earn_save' => 'Earn & Save',
            'media' => 'Media',
            'learning' => 'Learning',
            'tools' => 'Tools',
            'account' => 'Account',
        ];

        $grouped = [];
        foreach ($catalog as $item) {
            $key = $item->category;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'key' => $key,
                    'label' => $labels[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                    'items' => [],
                ];
            }
            $grouped[$key]['items'][] = $this->serializeMenuItem($item, $user);
        }

        return array_values($grouped);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function interestOptionsForFrontend(): array
    {
        return [
            ['key' => 'supporting_organizations', 'label' => 'Supporting Organizations'],
            ['key' => 'volunteering', 'label' => 'Volunteering'],
            ['key' => 'shopping_deals', 'label' => 'Shopping & Deals'],
            ['key' => 'gift_cards', 'label' => 'Gift Cards'],
            ['key' => 'faith_ministry', 'label' => 'Faith & Ministry'],
            ['key' => 'education', 'label' => 'Education'],
            ['key' => 'community_events', 'label' => 'Community Events'],
            ['key' => 'business_networking', 'label' => 'Business Networking'],
            ['key' => 'companion_hub', 'label' => 'Companion Hub'],
            ['key' => 'content_creation', 'label' => 'Content Creation'],
        ];
    }

    public function resolveHref(MenuItem $item, ?User $user = null): string
    {
        if ($user) {
            if ($item->menu_key === 'wallet') {
                return '#';
            }

            if ($item->menu_key === 'profile') {
                return $this->profileHrefForUser($user);
            }

            if ($item->menu_key === 'dashboard') {
                return $this->dashboardHrefForUser($user);
            }

            // Supporters manage/redeem owned cards; orgs see purchased-for-org list.
            if ($item->menu_key === 'gift_cards') {
                if ($this->isSupporterUser($user)) {
                    return Route::has('gift-cards.my-cards')
                        ? route('gift-cards.my-cards')
                        : '/gift-cards/my-cards';
                }

                if ($user->hasNonprofitDashboardRole() || $user->role === 'admin') {
                    return Route::has('gift-cards.created')
                        ? route('gift-cards.created')
                        : (Route::has('gift-cards.index') ? route('gift-cards.index') : '/gift-cards');
                }
            }

            if ($item->menu_key === 'unity_meet' && ! $this->canAccessUnityMeet($user)) {
                return $this->dashboardHrefForUser($user);
            }

            if ($item->requires_auth && ! auth()->check()) {
                return Route::has('login') ? route('login') : '/login';
            }
        }

        if ($item->href) {
            return $item->href;
        }

        if ($item->route_name && Route::has($item->route_name)) {
            return route($item->route_name);
        }

        return '#';
    }

    public function isSupporterUser(User $user): bool
    {
        return $user->hasRole('user') || (string) $user->role === 'user';
    }

    /**
     * @return list<string>
     */
    public function defaultQuickKeysForUser(User $user): array
    {
        if ($this->isSupporterUser($user)) {
            return self::DEFAULT_QUICK_KEYS;
        }

        if ($user->hasNonprofitDashboardRole() || $user->role === 'admin') {
            return self::DEFAULT_QUICK_KEYS_ORG;
        }

        return self::DEFAULT_QUICK_KEYS;
    }

    /**
     * @return array<int, string>
     */
    public function defaultBottomNavSlotsForUser(User $user): array
    {
        $slots = match (true) {
            $this->isSupporterUser($user) => self::DEFAULT_BOTTOM_NAV_SLOTS,
            $user->hasNonprofitDashboardRole(), $user->role === 'admin' => self::DEFAULT_BOTTOM_NAV_SLOTS_ORG,
            default => self::DEFAULT_BOTTOM_NAV_SLOTS,
        };

        if ($this->walletVisibleForUser($user)) {
            $slots[2] = 'wallet';
        }

        return $slots;
    }

    public function walletVisibleForUser(User $user): bool
    {
        if ($user->role === 'admin' || $user->hasRole('admin')) {
            return false;
        }

        return $user->walletHeaderVisible();
    }

    public function profileHrefForUser(User $user): string
    {
        if ($user->role === 'admin' || $user->hasRole('admin')) {
            return Route::has('dashboard') ? route('dashboard') : '/';
        }

        if ($user->hasNonprofitDashboardRole()) {
            return $this->dashboardHrefForUser($user);
        }

        return Route::has('user.profile.index') ? route('user.profile.index') : '/profile';
    }

    public function dashboardHrefForUser(User $user): string
    {
        if ($user->hasRole('care_alliance') || (string) $user->role === 'care_alliance') {
            return Route::has('care-alliance.dashboard') ? route('care-alliance.dashboard') : '/care-alliance/dashboard';
        }

        return Route::has('dashboard') ? route('dashboard') : '/dashboard';
    }

    public function profileActivePathForUser(User $user): string
    {
        if ($this->isSupporterUser($user)) {
            return '/profile';
        }

        if ($user->hasRole('care_alliance') || (string) $user->role === 'care_alliance') {
            return '/care-alliance/dashboard';
        }

        return '/dashboard';
    }

    public function activePathForItem(MenuItem $item, User $user): ?string
    {
        if ($item->menu_key === 'dashboard') {
            if ($user->hasRole('care_alliance') || (string) $user->role === 'care_alliance') {
                return '/care-alliance/dashboard';
            }

            return '/dashboard';
        }

        if ($item->menu_key === 'profile') {
            return $this->profileActivePathForUser($user);
        }

        if ($item->menu_key === 'gift_cards' && $this->isSupporterUser($user)) {
            return '/gift-cards/my-cards';
        }

        return $item->active_path_prefix;
    }

    public function canAccessUnityMeet(User $user): bool
    {
        if ($user->hasAnyRole(['user', 'organization', 'organization_pending', 'care_alliance'])) {
            return true;
        }

        return in_array((string) $user->role, ['user', 'organization', 'organization_pending', 'care_alliance'], true);
    }

    public function mobileNavRoleKey(User $user): ?string
    {
        if ($this->isSupporterUser($user)) {
            return 'user';
        }

        if ($user->hasRole('care_alliance') || (string) $user->role === 'care_alliance') {
            return 'care_alliance';
        }

        if ($user->hasRole('organization') || (string) $user->role === 'organization') {
            return 'organization';
        }

        if ($user->hasRole('organization_pending') || (string) $user->role === 'organization_pending') {
            return 'organization_pending';
        }

        if ($user->hasRole('admin') || (string) $user->role === 'admin') {
            return 'admin';
        }

        return null;
    }
}

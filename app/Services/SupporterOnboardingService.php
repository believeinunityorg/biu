<?php

namespace App\Services;

use App\Enums\SupporterOnboardingPurpose;
use App\Jobs\IngestKioskProvidersForGeoJob;
use App\Models\Organization;
use App\Models\PrimaryActionCategory;
use App\Models\SupporterPosition;
use App\Models\User;
use App\Support\BrpParticipationModule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

final class SupporterOnboardingService
{
    public function __construct(
        private readonly SupporterPrimaryOrganizationService $primaryOrgService,
    ) {}

    public function needsOnboarding(User $user): bool
    {
        return ($user->role ?? null) === 'user' && $user->onboarding_completed_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForUser(User $user, Request $request): array
    {
        $user->load([
            'supporterPositions',
            'supporterInterestCategories',
            'primaryOrganization:id,name,registered_user_image',
        ]);

        $onboardingRoleSlugs = collect(config('supporter_onboarding.roles', []))
            ->pluck('slug')
            ->filter()
            ->values()
            ->all();

        $onboardingPositions = SupporterPosition::query()
            ->where('is_active', true)
            ->whereIn('slug', $onboardingRoleSlugs)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        $userPositionIds = $user->supporterPositions->pluck('id')->all();
        $selectedOnboardingRoleIds = $onboardingPositions
            ->whereIn('id', $userPositionIds)
            ->pluck('id')
            ->values()
            ->all();

        $causes = PrimaryActionCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        $userCauseIds = $user->supporterInterestCategories->pluck('id')->all();

        $secondaryOrgIds = $this->primaryOrgService->resolveSecondaryOrganizationIds($user);

        $seedIds = array_values(array_unique(array_filter(array_merge(
            [$user->primary_organization_id],
            $secondaryOrgIds,
        ))));

        $organizationOptions = [];
        if (count($seedIds) > 0) {
            $organizationOptions = Organization::query()
                ->whereIn('id', $seedIds)
                ->orderBy('name')
                ->get(['id', 'name', 'registered_user_image'])
                ->map(static function (Organization $o) {
                    return [
                        'id' => $o->id,
                        'name' => $o->name,
                        'image' => $o->registered_user_image ? Storage::url($o->registered_user_image) : null,
                    ];
                })
                ->values()
                ->all();
        }

        $organizationPicker = null;
        if ($request->filled('org_picker_page')) {
            $organizationPicker = $this->organizationPickerPayload($request);
        }

        $notificationPreferences = array_merge(
            config('supporter_onboarding.notification_defaults', []),
            is_array($user->notification_preferences) ? $user->notification_preferences : [],
        );

        $resolvedStep = $this->resolveCurrentStep($user);

        return [
            'step' => $resolvedStep,
            'canFinish' => $this->canFinish($user),
            'firstRequiredStep' => $this->firstIncompleteRequiredStep($user),
            'totalSteps' => (int) config('supporter_onboarding.total_steps', 12),
            'user' => [
                'name' => $user->name,
                'image' => $user->image ? Storage::url($user->image) : null,
                'onboarding_purpose' => $user->onboarding_purpose,
                'dob' => $user->dob ? Carbon::parse($user->dob)->format('m/d') : null,
                'city' => $user->city,
                'state' => $user->state,
                'zipcode' => $user->zipcode,
                'supporter_interests' => $userCauseIds,
                'positions' => $selectedOnboardingRoleIds,
                'primary_organization_id' => $user->primary_organization_id ? (int) $user->primary_organization_id : null,
                'primary_organization' => $this->organizationRow($user->primaryOrganization),
                'primary_organization_locked' => (bool) $user->primary_organization_locked,
                'secondary_organization_ids' => $secondaryOrgIds,
                'account_visibility' => in_array((string) ($user->account_visibility ?? 'public'), ['public', 'private'], true)
                    ? (string) $user->account_visibility
                    : 'public',
                'messaging_policy' => in_array((string) ($user->messaging_policy ?? $user->message_audience ?? 'everyone'), [
                    'everyone', 'followers_only', 'organizations_i_follow', 'no_one',
                ], true)
                    ? (string) ($user->messaging_policy ?? $user->message_audience ?? 'everyone')
                    : 'everyone',
                'preferred_theme' => in_array((string) ($user->preferred_theme ?? $user->appearance_preference ?? 'system'), ['system', 'light', 'dark'], true)
                    ? (string) ($user->preferred_theme ?? $user->appearance_preference ?? 'system')
                    : 'system',
                'proximity_notifications_enabled' => $user->proximity_notifications_enabled !== false,
                'notification_preferences' => $notificationPreferences,
            ],
            'purposes' => collect(SupporterOnboardingPurpose::cases())->map(fn (SupporterOnboardingPurpose $p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ])->values()->all(),
            'onboardingRoles' => $onboardingPositions->map(fn (SupporterPosition $p) => [
                'id' => $p->id,
                'name' => $p->name,
            ])->values()->all(),
            'causes' => $causes->map(fn (PrimaryActionCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
            ])->values()->all(),
            'organizations' => $organizationOptions,
            'organizationPicker' => $organizationPicker,
        ];
    }

    /**
     * @return array{success: bool, next_step?: int, errors?: array<string, string>}
     */
    public function saveStep(User $user, Request $request): array
    {
        $step = (int) $request->input('step', 1);
        $totalSteps = (int) config('supporter_onboarding.total_steps', 12);
        $nextStep = min($step + 1, $totalSteps);

        $user->refresh();
        $blockedStep = $this->firstIncompleteRequiredStep($user);
        if ($blockedStep !== null && $step > $blockedStep) {
            return [
                'success' => false,
                'errors' => ['step' => [$this->requiredStepMessage($blockedStep)]],
            ];
        }

        try {
            match ($step) {
                1 => $user->update(['onboarding_step' => 2]),
                2 => $this->savePurposeStep($user, $request),
                3 => $this->savePhotoStep($user, $request),
                4 => $this->saveBirthdayStep($user, $request),
                5 => $this->saveLocationStep($user, $request),
                6 => $this->saveCausesStep($user, $request),
                7 => $this->saveRolesStep($user, $request),
                8 => $this->saveOrganizationStep($user, $request),
                9 => $this->savePrivacyStep($user, $request),
                10 => $this->saveNotificationsStep($user, $request),
                11 => $this->saveAppearanceStep($user, $request),
                default => throw new \InvalidArgumentException('Invalid onboarding step.'),
            };
        } catch (\Illuminate\Validation\ValidationException $e) {
            return [
                'success' => false,
                'errors' => $e->errors(),
            ];
        }

        $user->refresh();

        if ($step >= 2) {
            $storedStep = max((int) ($user->onboarding_step ?? 1), $nextStep);
            $stillBlocked = $this->firstIncompleteRequiredStep($user);

            if ($stillBlocked !== null) {
                $storedStep = min($storedStep, $stillBlocked);
            } else {
                $storedStep = min($storedStep, $totalSteps);
            }

            $user->update(['onboarding_step' => $storedStep]);
        }

        return [
            'success' => true,
            'next_step' => $this->resolveCurrentStep($user->fresh()),
            'can_finish' => $this->canFinish($user->fresh()),
        ];
    }

    public function canFinish(User $user): bool
    {
        if ($this->firstIncompleteRequiredStep($user) !== null) {
            return false;
        }

        $totalSteps = (int) config('supporter_onboarding.total_steps', 12);

        return (int) ($user->onboarding_step ?? 1) >= $totalSteps;
    }

    public function resolveCurrentStep(User $user): int
    {
        $totalSteps = (int) config('supporter_onboarding.total_steps', 12);
        $storedStep = max(1, min($totalSteps, (int) ($user->onboarding_step ?? 1)));

        $firstRequired = $this->firstIncompleteRequiredStep($user);
        if ($firstRequired !== null) {
            if ($storedStep > $firstRequired) {
                return $firstRequired;
            }

            return min($storedStep, $firstRequired);
        }

        if ($storedStep >= $totalSteps) {
            return $this->canFinish($user) ? $totalSteps : ($totalSteps - 1);
        }

        return max(6, min($storedStep, $totalSteps - 1));
    }

    public function firstIncompleteRequiredStep(User $user): ?int
    {
        if (! filled($user->onboarding_purpose)) {
            return 2;
        }

        if (! filled($user->dob)) {
            return 4;
        }

        if (! $this->hasLocation($user)) {
            return 5;
        }

        return null;
    }

    private function hasLocation(User $user): bool
    {
        $city = trim((string) ($user->city ?? ''));
        $state = strtoupper(trim((string) ($user->state ?? '')));

        return $city !== '' && strlen($state) === 2;
    }

    private function requiredStepMessage(int $step): string
    {
        return match ($step) {
            2 => 'Please tell us what brings you here today (Step 2).',
            4 => 'Please add your birthday before continuing (Step 4).',
            5 => 'Please add your city and state before continuing (Step 5).',
            default => "Please complete Step {$step} before continuing.",
        };
    }

    public function finish(User $user, string $destination = 'dashboard'): string
    {
        $user->refresh();

        if (! $this->canFinish($user)) {
            $blocked = $this->firstIncompleteRequiredStep($user);
            throw \Illuminate\Validation\ValidationException::withMessages([
                'finish' => $blocked !== null
                    ? $this->requiredStepMessage($blocked)
                    : 'Please complete Step 11 (appearance) before finishing.',
            ]);
        }

        $user->update([
            'onboarding_completed_at' => now(),
            'onboarding_step' => (int) config('supporter_onboarding.total_steps', 12),
        ]);

        try {
            ParticipationActivityService::complete(
                $user,
                BrpParticipationModule::PROFILE_COMPLETION,
                $user->id,
                'Welcome reward for completing supporter onboarding',
                ['user_id' => $user->id, 'source' => 'onboarding_wizard'],
            );
        } catch (\Throwable $e) {
            Log::warning('Supporter onboarding finished but BRP award failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($destination === 'organizations') {
            return route('organizations');
        }

        $slug = trim((string) ($user->slug ?? ''));
        if ($slug !== '') {
            return route('users.show', $slug);
        }

        return route('users.show', $user->id);
    }

    private function savePurposeStep(User $user, Request $request): void
    {
        $validated = $request->validate([
            'onboarding_purpose' => ['required', 'string', Rule::in(SupporterOnboardingPurpose::values())],
        ]);

        $purpose = SupporterOnboardingPurpose::from($validated['onboarding_purpose']);

        $user->update([
            'onboarding_purpose' => $purpose->value,
            'volunteer_interest_statement' => $purpose->label(),
        ]);
    }

    private function savePhotoStep(User $user, Request $request): void
    {
        if ($request->boolean('skip')) {
            return;
        }

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif', 'max:10240'],
        ]);

        if ($user->image) {
            Storage::disk('public')->delete($user->image);
        }

        $user->update([
            'image' => $this->storeCompressedProfileImage($request->file('image'), (int) $user->id),
        ]);
    }

    private function saveBirthdayStep(User $user, Request $request): void
    {
        $validated = $request->validate([
            'dob' => [
                'required',
                'string',
                'regex:/^(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])$/',
                function ($attribute, $value, $fail) {
                    if (! is_string($value) || ! preg_match('/^(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])$/', $value)) {
                        return;
                    }
                    [$month, $day] = explode('/', $value);
                    if (! checkdate((int) $month, (int) $day, 2000)) {
                        $fail('The date of birth is not a valid month/day.');
                    }
                },
            ],
        ]);

        $user->update([
            'dob' => Carbon::createFromFormat('m/d/Y', $validated['dob'].'/2000')->format('Y-m-d'),
        ]);
    }

    private function saveLocationStep(User $user, Request $request): void
    {
        $validated = $request->validate([
            'city' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (! is_string($value) || trim($value) === '') {
                        $fail('City is required.');
                    }
                },
            ],
            'state' => [
                'required',
                'string',
                'size:2',
                function ($attribute, $value, $fail) {
                    if (! is_string($value) || strlen(strtoupper(trim($value))) !== 2) {
                        $fail('Enter a valid 2-letter state code (e.g. CA).');
                    }
                },
            ],
            'zipcode' => ['nullable', 'string', 'max:10'],
        ]);

        $city = trim($validated['city']);
        $state = strtoupper(trim($validated['state']));
        $zip = trim((string) ($validated['zipcode'] ?? ''));

        $geoBefore = $this->normalizeGeo($user->city, $user->state, $user->zipcode);
        $geoAfter = $this->normalizeGeo($city, $state, $zip !== '' ? $zip : null);

        $user->update([
            'city' => $geoAfter['city'],
            'state' => $geoAfter['state'],
            'zipcode' => $geoAfter['zip'] !== '' ? $geoAfter['zip'] : null,
        ]);

        if (
            config('services.kiosk_provider_ingest.enabled', true)
            && filled($user->city)
            && filled($user->state)
        ) {
            $geoChanged =
                ($geoBefore['city'] !== $geoAfter['city'])
                || ($geoBefore['state'] !== $geoAfter['state'])
                || ($geoBefore['zip'] !== $geoAfter['zip']);

            if ($geoChanged) {
                IngestKioskProvidersForGeoJob::dispatch(
                    (string) $geoAfter['state'],
                    (string) $geoAfter['city'],
                    $geoAfter['zip'] !== '' ? $geoAfter['zip'] : null
                );
            }
        }
    }

    private function saveCausesStep(User $user, Request $request): void
    {
        if ($request->boolean('skip')) {
            return;
        }

        $validated = $request->validate([
            'supporter_interests' => ['nullable', 'array'],
            'supporter_interests.*' => [
                'integer',
                Rule::exists('primary_action_categories', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
        ]);

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $validated['supporter_interests'] ?? []),
            fn (int $id) => $id > 0
        )));

        $user->supporterInterestCategories()->sync($ids);
        app(CauseGroupChatService::class)->ensureForUserAndCategoryIds($user, $ids);
    }

    private function saveRolesStep(User $user, Request $request): void
    {
        if ($request->boolean('skip')) {
            return;
        }

        $validated = $request->validate([
            'positions' => ['nullable', 'array'],
            'positions.*' => ['integer', 'exists:supporter_positions,id'],
        ]);

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $validated['positions'] ?? []),
            fn (int $id) => $id > 0
        )));

        if ($ids !== []) {
            $existing = $user->supporterPositions()->pluck('supporter_positions.id')->all();
            $user->supporterPositions()->sync(array_values(array_unique(array_merge($existing, $ids))));
        }
    }

    private function saveOrganizationStep(User $user, Request $request): void
    {
        if ($request->boolean('skip') || $request->input('organization_connection') === 'not_now') {
            return;
        }

        $validated = $request->validate([
            'primary_organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'secondary_organization_ids' => ['nullable', 'array'],
            'secondary_organization_ids.*' => ['integer', 'min:1'],
        ]);

        $primaryId = isset($validated['primary_organization_id']) && $validated['primary_organization_id'] !== null
            ? (int) $validated['primary_organization_id']
            : null;

        if ($primaryId !== null) {
            $organization = Organization::query()
                ->active()
                ->excludingCareAllianceHubs()
                ->whereKey($primaryId)
                ->first();

            if ($organization !== null) {
                $this->primaryOrgService->ensureFavoriteOrganization($user, $organization);

                if (! $user->primary_organization_locked) {
                    $user->update(['primary_organization_id' => $primaryId]);
                }
            }
        }

        $secondaryIds = $this->primaryOrgService->filterEligibleSecondaryOrganizationIds(
            array_values(array_unique(array_filter(
                array_map('intval', $validated['secondary_organization_ids'] ?? []),
                fn (int $id) => $id > 0
            ))),
            $primaryId,
        );

        foreach ($secondaryIds as $sid) {
            $organization = Organization::query()
                ->active()
                ->excludingCareAllianceHubs()
                ->whereKey($sid)
                ->first();

            if ($organization !== null) {
                $this->primaryOrgService->ensureFavoriteOrganization($user, $organization);
            }
        }

        $user->update([
            'secondary_organization_ids' => $secondaryIds,
        ]);
    }

    private function savePrivacyStep(User $user, Request $request): void
    {
        $validated = $request->validate([
            'account_visibility' => ['required', 'string', Rule::in(['public', 'private'])],
            'messaging_policy' => ['required', 'string', Rule::in(['everyone', 'followers_only', 'organizations_i_follow', 'no_one'])],
        ]);

        $user->update([
            'account_visibility' => $validated['account_visibility'],
            'messaging_policy' => $validated['messaging_policy'],
            'message_audience' => $validated['messaging_policy'],
        ]);
    }

    private function saveNotificationsStep(User $user, Request $request): void
    {
        if ($request->boolean('skip')) {
            return;
        }

        $keys = array_keys(config('supporter_onboarding.notification_defaults', []));
        $rules = ['proximity_notifications_enabled' => ['sometimes', 'boolean']];
        foreach ($keys as $key) {
            $rules["notification_preferences.{$key}"] = ['sometimes', 'boolean'];
        }

        $validated = $request->validate($rules);

        $defaults = config('supporter_onboarding.notification_defaults', []);
        $incoming = $validated['notification_preferences'] ?? [];
        $merged = [];
        foreach ($defaults as $key => $default) {
            $merged[$key] = array_key_exists($key, $incoming)
                ? (bool) $incoming[$key]
                : (bool) $default;
        }

        $update = ['notification_preferences' => $merged];
        if (array_key_exists('proximity_notifications_enabled', $validated)) {
            $update['proximity_notifications_enabled'] = (bool) $validated['proximity_notifications_enabled'];
        }

        $user->update($update);
    }

    private function saveAppearanceStep(User $user, Request $request): void
    {
        $validated = $request->validate([
            'preferred_theme' => ['required', 'string', Rule::in(['system', 'light', 'dark'])],
        ]);

        $user->update([
            'preferred_theme' => $validated['preferred_theme'],
            'appearance_preference' => $validated['preferred_theme'],
        ]);
    }

    /**
     * @return array{id: int, name: string, image: string|null}|null
     */
    private function organizationRow(?Organization $organization): ?array
    {
        if ($organization === null) {
            return null;
        }

        return [
            'id' => (int) $organization->id,
            'name' => (string) $organization->name,
            'image' => $organization->registered_user_image
                ? Storage::url($organization->registered_user_image)
                : null,
        ];
    }

    /**
     * @return array{target: string, items: array<int, array{id: int, name: string, image: string|null}>, has_more: bool, page: int, search: string}
     */
    public function organizationPickerPayload(Request $request): array
    {
        $validated = $request->validate([
            'org_picker_page' => ['required', 'integer', 'min:1'],
            'org_picker_q' => ['nullable', 'string', 'max:100'],
            'org_picker_exclude' => ['nullable', 'string', 'max:2000'],
            'org_picker_target' => ['required', 'in:primary,secondary'],
        ]);

        $page = (int) $validated['org_picker_page'];
        $perPage = 30;
        $search = trim((string) ($validated['org_picker_q'] ?? ''));
        $target = $validated['org_picker_target'];

        $excludeIds = collect(explode(',', (string) $request->input('org_picker_exclude', '')))
            ->map(fn ($v) => (int) trim((string) $v))
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $user = $request->user();
        if ($user instanceof User) {
            $primaryId = $user->primary_organization_id ? (int) $user->primary_organization_id : null;
            if ($target === 'primary' && $primaryId) {
                $excludeIds[] = $primaryId;
            }
            if ($target === 'secondary' && $primaryId) {
                $excludeIds[] = $primaryId;
            }
        }

        $excludeIds = array_values(array_unique($excludeIds));

        $query = Organization::query()
            ->active()
            ->excludingCareAllianceHubs()
            ->when($search !== '', function ($q) use ($search) {
                $escaped = addcslashes($search, '%_\\');
                $q->where('name', 'like', '%'.$escaped.'%');
            })
            ->when(count($excludeIds) > 0, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->orderBy('name');

        $paginator = $query->paginate($perPage, ['id', 'name', 'registered_user_image'], 'page', $page);

        $items = $paginator->getCollection()->map(static function (Organization $o) {
            return [
                'id' => $o->id,
                'name' => $o->name,
                'image' => $o->registered_user_image ? Storage::url($o->registered_user_image) : null,
            ];
        })->values()->all();

        return [
            'target' => $target,
            'items' => $items,
            'has_more' => $paginator->hasMorePages(),
            'page' => $paginator->currentPage(),
            'search' => $search,
        ];
    }

    private function storeCompressedProfileImage(UploadedFile $file, int $userId): string
    {
        $directory = 'profile-photos';
        Storage::disk('public')->makeDirectory($directory);

        $filename = 'profile-'.$userId.'-'.time().'.jpg';
        $path = $directory.'/'.$filename;

        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->read($file->getRealPath());
            $encoded = $image->toJpeg(quality: 82);
            Storage::disk('public')->put($path, (string) $encoded);
        } catch (\Throwable) {
            $path = $file->store($directory, 'public');
        }

        return $path;
    }

    /**
     * @return array{city: string, state: string, zip: string}
     */
    private function normalizeGeo(?string $city, ?string $state, ?string $zip): array
    {
        return [
            'city' => trim((string) $city),
            'state' => strtoupper(trim((string) $state)),
            'zip' => trim((string) $zip),
        ];
    }
}

<?php

namespace App\Http\Controllers\CareAlliance;

use App\Http\Controllers\Controller;
use App\Http\Helpers\AuthRedirectHelper;
use App\Models\BoardMember;
use App\Models\CareAlliance;
use App\Models\ChatTopic;
use App\Models\Organization;
use App\Models\PrimaryActionCategory;
use App\Models\User;
use App\Enums\MembershipJoinMethod;
use App\Services\CauseGroupChatService;
use App\Services\MembershipAccountService;
use App\Services\SeoService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class CareAllianceRegisterController extends Controller
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function create(Request $request)
    {
        return Inertia::render('frontend/register/care-alliance', [
            'seo' => SeoService::forPage('register_care_alliance'),
            'primaryActionCategories' => $this->primaryActionCategories(),
        ]);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function primaryActionCategories(): array
    {
        return PrimaryActionCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PrimaryActionCategory $c) => ['id' => $c->id, 'name' => $c->name])
            ->values()
            ->all();
    }

    public function store(Request $request)
    {
        $pacIds = $request->input('primary_action_category_ids');
        if (! is_array($pacIds)) {
            $pacIds = $pacIds !== null && $pacIds !== '' ? [$pacIds] : [];
        }
        $request->merge([
            'primary_action_category_ids' => array_values(array_unique(array_filter(array_map('intval', $pacIds)))),
        ]);

        $request->merge([
            'website' => $request->filled('website') ? $request->input('website') : null,
            'ein' => $request->filled('ein') ? $request->input('ein') : null,
            'description' => $request->filled('description') ? $request->input('description') : null,
            'city' => $request->filled('city') ? $request->input('city') : null,
            'state' => $request->filled('state') ? $request->input('state') : null,
            'management_fee_percent' => $request->filled('management_fee_percent') ? $request->input('management_fee_percent') : null,
            'memberships_enabled' => filter_var($request->input('memberships_enabled', false), FILTER_VALIDATE_BOOLEAN),
            'membership_type' => in_array($request->input('membership_type'), ['free', 'paid'], true)
                ? $request->input('membership_type')
                : 'free',
            'membership_name' => $request->filled('membership_name') ? trim((string) $request->input('membership_name')) : null,
            'join_method' => in_array($request->input('join_method'), MembershipJoinMethod::values(), true)
                ? $request->input('join_method')
                : null,
        ]);

        $membershipsEnabled = filter_var($request->input('memberships_enabled', false), FILTER_VALIDATE_BOOLEAN);

        $validator = Validator::make($request->all(), [
            'contact_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|confirmed|min:8',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'city' => 'nullable|string|max:128',
            'state' => 'nullable|string|max:64',
            'website' => 'nullable|url|max:500',
            'ein' => [
                'nullable',
                'string',
                'max:32',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $digits = preg_replace('/\D/', '', (string) $value);
                    if (strlen($digits) !== 9) {
                        $fail('The EIN must be exactly 9 digits (e.g. 12-3456789).');
                    }
                    if (Organization::query()->where('ein', $digits)->exists()) {
                        $fail('This EIN is already registered to an organization.');
                    }
                    if (CareAlliance::query()->where('ein', $digits)->exists()) {
                        $fail('This EIN is already registered to a Unity Impact Alliance.');
                    }
                },
            ],
            'management_fee_percent' => 'nullable|numeric|min:0|max:100',
            'fund_model' => ['required', Rule::in(['direct', 'campaign_split'])],
            'memberships_enabled' => ['required', 'boolean'],
            'membership_type' => ['nullable', 'string', Rule::in(['free', 'paid'])],
            'membership_name' => [
                Rule::requiredIf($membershipsEnabled),
                'nullable',
                'string',
                'max:255',
            ],
            'join_method' => [
                Rule::requiredIf($membershipsEnabled),
                'nullable',
                'string',
                Rule::in(MembershipJoinMethod::values()),
            ],
            'primary_action_category_ids' => ['required', 'array', 'min:1', 'max:8'],
            'primary_action_category_ids.*' => ['integer', 'distinct', Rule::exists('primary_action_categories', 'id')->where('is_active', true)],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();
        $einRaw = $validated['ein'] ?? null;
        /** @var string $hubOrgEin digits-only — internal hub org record only when user omits EIN */
        /** @var string|null $allianceEin null until user adds EIN on Unity Impact Alliance (wallet/header use alliance row) */
        if (is_string($einRaw) && trim($einRaw) !== '') {
            $hubOrgEin = preg_replace('/\D/', '', $einRaw);
            $allianceEin = $hubOrgEin;
        } else {
            $hubOrgEin = $this->generateUniquePlaceholderEin();
            $allianceEin = null;
        }

        Role::findOrCreate('care_alliance', 'web');

        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug ?: 'care-alliance';
        $counter = 1;
        while (CareAlliance::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $userSlug = Str::slug($validated['contact_name']).'-'.Str::random(6);
        while (User::where('slug', $userSlug)->exists()) {
            $userSlug = Str::slug($validated['contact_name']).'-'.Str::random(6);
        }

        $feeBps = null;
        if (isset($validated['management_fee_percent']) && $validated['management_fee_percent'] !== null && $validated['management_fee_percent'] !== '') {
            $feeBps = (int) round((float) $validated['management_fee_percent'] * 100);
        }

        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $validated['contact_name'],
                'slug' => $userSlug,
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'care_alliance',
                'organization_role' => null,
            ]);

            $alliance = CareAlliance::create([
                'creator_user_id' => $user->id,
                'slug' => $slug,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'website' => $validated['website'] ?? null,
                'ein' => $allianceEin,
                'management_fee_bps' => $feeBps,
                'fund_model' => $validated['fund_model'],
                'status' => 'active',
                'memberships_enabled' => (bool) ($validated['memberships_enabled'] ?? false),
                'balance_cents' => 0,
            ]);

            $alliance->primaryActionCategories()->sync($validated['primary_action_category_ids']);

            $this->membershipAccountService->bootstrapFromRegistration($alliance, [
                'memberships_enabled' => (bool) ($validated['memberships_enabled'] ?? false),
                'membership_name' => $validated['membership_name'] ?? $validated['name'],
                'membership_type' => $validated['membership_type'] ?? 'free',
                'join_method' => $validated['join_method'] ?? MembershipJoinMethod::RequestToJoin->value,
            ]);

            $organization = Organization::create([
                'user_id' => $user->id,
                'ein' => $hubOrgEin,
                'name' => $validated['name'],
                'street' => 'N/A',
                'city' => ! empty($validated['city']) ? $validated['city'] : 'N/A',
                'state' => ! empty($validated['state']) ? $validated['state'] : 'N/A',
                'zip' => '00000',
                'email' => $validated['email'],
                'phone' => '0000000000',
                'contact_name' => $validated['contact_name'],
                'contact_title' => 'Unity Impact Alliance',
                'website' => $validated['website'] ?? null,
                'description' => $validated['description'] ?? '',
                'mission' => $validated['description'] ?? 'Unity Impact Alliance',
                'registration_status' => 'approved',
                'status' => 'Active',
                'tax_period' => Carbon::now()->format('Ym'),
                'is_compliance_locked' => false,
                'has_edited_irs_data' => false,
            ]);

            $organization->primaryActionCategories()->sync($validated['primary_action_category_ids']);

            BoardMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'position' => 'Unity Impact Alliance Administrator',
                'appointed_on' => now(),
            ]);

            if (Schema::hasColumn('care_alliances', 'hub_organization_id')) {
                $alliance->update(['hub_organization_id' => $organization->id]);
            }

            $topicIds = ChatTopic::query()->active()->orderBy('id')->limit(12)->pluck('id');
            if ($topicIds->isNotEmpty()) {
                $user->interestedTopics()->sync($topicIds);
            }

            $user->syncRoles(['care_alliance']);

            app(CauseGroupChatService::class)->ensureForUser($user->fresh());

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors(['general' => 'Registration failed. Please try again.'])
                ->withInput();
        }

        event(new Registered($user));

        $scheme = $request->getScheme();
        $host = $request->getHost();
        $port = $request->getPort();
        $currentDomain = $scheme.'://'.$host.($port && $port != 80 && $port != 443 ? ':'.$port : '');
        $user->sendEmailVerificationNotification($currentDomain);

        Auth::login($user);

        return redirect(AuthRedirectHelper::defaultRedirectForUser($user))
            ->with('success', 'Unity Impact Alliance registered successfully!');
    }

    /**
     * Unique 9-digit value for organizations/care_alliances when the registrant does not provide an EIN.
     */
    private function generateUniquePlaceholderEin(): string
    {
        for ($attempt = 0; $attempt < 150; $attempt++) {
            $digits = '';
            for ($i = 0; $i < 9; $i++) {
                $digits .= (string) random_int(0, 9);
            }
            if ($digits === '000000000') {
                continue;
            }
            if (Organization::query()->where('ein', $digits)->exists()) {
                continue;
            }
            if (CareAlliance::query()->where('ein', $digits)->exists()) {
                continue;
            }

            return $digits;
        }

        throw new \RuntimeException('Could not allocate a unique placeholder EIN.');
    }
}

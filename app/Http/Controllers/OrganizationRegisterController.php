<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\EINLookupRequest;
use App\Http\Requests\Auth\OrganizationRegistrationRequest;
use App\Models\BoardMember;
use App\Models\BridgeIntegration;
use App\Models\IrsBoardMember;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\PrimaryActionCategory;
use App\Models\User;
use App\Services\BridgeService;
use App\Services\CauseGroupChatService;
use App\Enums\MembershipJoinMethod;
use App\Services\EINLookupService;
use App\Services\FamilyReunion\FamilyReunionService;
use App\Services\MembershipAccountService;
use App\Services\OrgClaim990Service;
use App\Services\TaxComplianceService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use App\Services\SeoService;
use App\Models\CommunityOrganizationType;
use App\Support\CommunityOrganizationTypes;

class OrganizationRegisterController extends Controller
{
    protected EINLookupService $einLookupService;
    protected TaxComplianceService $taxComplianceService;
    protected BridgeService $bridgeService;
    protected OrgClaim990Service $orgClaim990Service;

    protected MembershipAccountService $membershipAccountService;

    public function __construct(
        EINLookupService $einLookupService,
        TaxComplianceService $taxComplianceService,
        BridgeService $bridgeService,
        OrgClaim990Service $orgClaim990Service,
        MembershipAccountService $membershipAccountService,
    ) {
        $this->einLookupService = $einLookupService;
        $this->taxComplianceService = $taxComplianceService;
        $this->bridgeService = $bridgeService;
        $this->orgClaim990Service = $orgClaim990Service;
        $this->membershipAccountService = $membershipAccountService;
    }

    public function create(Request $request)
    {
        $seo = SeoService::forPage('register_organization');
        $primaryActionCategories = $this->primaryActionCategoriesForRegister();

        // Handle invite token
        if ($request->has('invite')) {
            $invite = OrganizationInvite::where('token', $request->invite)
                ->where('status', '!=', 'accepted')
                ->first();

            if (!$invite) {
                return redirect()->route('register.organization')
                    ->with('error', 'Invalid or expired invitation link');
            }

            return Inertia::render('frontend/register/organization', [
                'seo' => $seo,
                'ein' => $invite->ein,
                'inviteToken' => $invite->token,
                'organizationName' => $invite->organization_name,
                'officers_for_ein_url' => route('register.organization.officers-for-ein'),
                'primaryActionCategories' => $primaryActionCategories,
                'communityOrganizationTypes' => CommunityOrganizationTypes::forSelect(),
            ]);
        }

        // Handle legacy referral code (for backward compatibility)
        if ($request->has('ref') || $request->has('ein')) {
            $user = User::where('referral_code', $request->ref)->first();

            if (!$user) {
                return redirect()->route('register.organization')->with('error', 'Invalid referral code');
            }

            return Inertia::render('frontend/register/organization', [
                'seo' => $seo,
                'ein' => $request->query('ein'),
                'referralCode' => $user->referral_code,
                'officers_for_ein_url' => route('register.organization.officers-for-ein'),
                'primaryActionCategories' => $primaryActionCategories,
                'communityOrganizationTypes' => CommunityOrganizationTypes::forSelect(),
            ]);
        }

        return Inertia::render('frontend/register/organization', [
            'seo' => $seo,
            'officers_for_ein_url' => route('register.organization.officers-for-ein'),
            'primaryActionCategories' => $primaryActionCategories,
            'communityOrganizationTypes' => CommunityOrganizationTypes::forSelect(),
        ]);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function primaryActionCategoriesForRegister(): array
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

    /**
     * Return officers for an EIN (real-time, no submit). Used by frontend to show list without full form submit.
     */
    public function officersForEin(Request $request)
    {
        $ein = preg_replace('/[^0-9]/', '', (string) $request->query('ein', ''));
        if (strlen($ein) !== 9) {
            return response()->json(['officers' => []]);
        }
        $officers = $this->orgClaim990Service->getOfficersForEIN($ein);
        return response()->json(['officers' => $officers]);
    }

    public function lookupEIN(Request $request)
    {
        try {
            Log::info('EIN Lookup Request', [
                'request_data' => $request->all(),
                'headers' => $request->headers->all(),
            ]);

            // Manual validation for AJAX requests
            $validator = Validator::make($request->all(), [
                'ein' => 'required|string|min:9|max:9|unique:organizations,ein'
            ], [
                'ein.required' => 'EIN is required',
                'ein.min' => 'EIN must be exactly 9 digits',
                'ein.max' => 'EIN must be exactly 9 digits',
                'ein.unique' => 'This organization is already registered',
            ]);

            if ($validator->fails()) {
                Log::error('EIN Lookup Validation Error', ['errors' => $validator->errors()->toArray()]);

                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first('ein'),
                    'errors' => $validator->errors()
                ], 422); // 422 Unprocessable Entity
            }

            $ein = $request->ein;

            // Additional check (redundant but safe)
            $existingOrg = Organization::where('ein', $ein)->first();
            if ($existingOrg) {
                return response()->json([
                    'success' => false,
                    'message' => 'This organization is already registered.'
                ], 422);
            }

            // Lookup in Excel data
            $orgData = $this->einLookupService->lookupEIN($ein);

            if (!$orgData) {
                return response()->json([
                    'success' => false,
                    'message' => 'EIN not found in IRS database. Please verify the number and try again.'
                ], 404);
            }

            Log::info('EIN Lookup Success', ['data' => $orgData]);

            return response()->json([
                'success' => true,
                'data' => $orgData
            ]);
        } catch (\Exception $e) {
            Log::error('EIN Lookup Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error looking up EIN. Please try again.'
            ], 500);
        }
    }

    public function register(Request $request)
    {
        try {

            // Normalize selected_irs_board_member_id so FormData string is accepted as integer (avoids "must be an integer" and ensures list shows)
            $request->merge([
                'selected_irs_board_member_id' => $request->filled('selected_irs_board_member_id') && is_numeric($request->selected_irs_board_member_id)
                    ? (int) $request->selected_irs_board_member_id
                    : null,
            ]);

            $pacIds = $request->input('primary_action_category_ids');
            if (! is_array($pacIds)) {
                $pacIds = $pacIds !== null && $pacIds !== '' ? [$pacIds] : [];
            }

            $hasEin = filter_var($request->input('has_ein', true), FILTER_VALIDATE_BOOLEAN);
            $ein = preg_replace('/\D/', '', (string) $request->input('ein', ''));
            if (! $hasEin || strlen($ein) !== 9) {
                do {
                    $ein = '9'.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
                } while (Organization::where('ein', $ein)->exists());
                $hasEin = false;
            }

            $hasMembers = filter_var($request->input('has_members', false), FILTER_VALIDATE_BOOLEAN);
            $membershipsEnabled = $hasMembers && filter_var($request->input('memberships_enabled', false), FILTER_VALIDATE_BOOLEAN);
            $membershipType = $membershipsEnabled
                ? (in_array($request->input('membership_type'), ['free', 'paid'], true) ? $request->input('membership_type') : 'free')
                : null;
            $joinMethod = $membershipsEnabled && in_array($request->input('join_method'), MembershipJoinMethod::values(), true)
                ? $request->input('join_method')
                : null;
            $membershipName = $membershipsEnabled ? trim((string) $request->input('membership_name', '')) : null;

            $request->merge([
                'ein' => $ein,
                'has_ein' => $hasEin,
                'has_members' => $hasMembers,
                'memberships_enabled' => $membershipsEnabled,
                'membership_type' => $membershipType,
                'membership_name' => $membershipName !== '' ? $membershipName : null,
                'join_method' => $joinMethod,
                'primary_action_category_ids' => array_values(array_unique(array_filter(array_map('intval', $pacIds)))),
                'contact_title' => $request->filled('contact_title') ? $request->input('contact_title') : 'Administrator',
                'phone' => $request->filled('phone') ? $request->input('phone') : '0000000000',
                'description' => $request->filled('description') ? $request->input('description') : 'Organization profile to be completed.',
                'mission' => $request->filled('mission') ? $request->input('mission') : 'Mission to be completed.',
                'legal_name_confirmation' => $request->filled('legal_name_confirmation')
                    ? $request->input('legal_name_confirmation')
                    : $request->input('name'),
                'agree_to_terms' => $request->boolean('agree_to_terms') ? '1' : $request->input('agree_to_terms'),
                'attestation_officer_on_990' => $request->boolean('attestation_officer_on_990') ? '1' : '1',
            ]);

            // Manual validation — simplified 3-step registration (docs / officer ID optional)
            $validator = Validator::make($request->all(), [
                'ein' => 'required|string|size:9|unique:organizations,ein',
                'has_ein' => 'required|boolean',
                'has_members' => 'required|boolean',
                'memberships_enabled' => 'required|boolean',
                'membership_type' => 'nullable|string|in:free,paid',
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
                'name' => 'required|string|max:255',
                'ico' => 'nullable|string|max:255',
                'street' => 'required|string|max:255',
                'city' => 'required|string|max:255',
                'state' => 'required|string|max:255',
                'zip' => 'required|string|max:255',
                'classification' => 'nullable|string|max:255',
                'ruling' => 'nullable|string|max:255',
                'deductibility' => 'nullable|string|max:255',
                'organization' => 'nullable|string|max:255',
                'status' => 'nullable|string|max:255',
                'tax_period' => 'nullable|string|max:255',
                'filing_req' => 'nullable|string|max:255',
                'ntee_code' => 'nullable|string|max:255',
                'community_organization_type_id' => [
                    'required',
                    'integer',
                    Rule::exists('community_organization_types', 'id')->where('is_active', true),
                ],
                'community_organization_type_other' => [
                    Rule::requiredIf(fn () => (int) $request->input('community_organization_type_id') === (int) CommunityOrganizationType::otherId()),
                    'nullable',
                    'string',
                    'max:255',
                ],
                'grandfather_name' => [
                    Rule::requiredIf(fn () => (int) $request->input('community_organization_type_id') === (int) CommunityOrganizationType::familyReunionId()),
                    'nullable',
                    'string',
                    'max:255',
                ],
                'grandmother_name' => [
                    Rule::requiredIf(fn () => (int) $request->input('community_organization_type_id') === (int) CommunityOrganizationType::familyReunionId()),
                    'nullable',
                    'string',
                    'max:255',
                ],
                'grandfather_birth_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
                'grandfather_death_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
                'grandmother_birth_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
                'grandmother_death_year' => ['nullable', 'integer', 'min:1800', 'max:2100'],
                'grandfather_photo' => ['nullable', 'image', 'max:5120'],
                'grandmother_photo' => ['nullable', 'image', 'max:5120'],
                'legal_entity_status' => 'nullable|string|max:64',
                'legal_entity_status_other' => 'nullable|string|max:255',
                'email' => 'required|email|max:255|unique:users,email',
                'phone' => 'required|string|max:255',
                'contact_name' => 'required|string|max:255',
                'contact_title' => 'required|string|max:255',
                'password' => 'required|string|confirmed|min:8',
                'password_confirmation' => 'required|string',
                'website' => 'nullable|url|max:255',
                'description' => 'required|string|max:2000',
                'mission' => 'required|string|max:2000',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
                'officer_id' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'legal_name_confirmation' => 'nullable|string|max:255',
                'doc_501c3' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'doc_articles' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'doc_bylaws' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'doc_state_registration' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'doc_board_list' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'doc_signer_resolution' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'doc_bank_account' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'agree_to_terms' => 'required|accepted',
                'attestation_officer_on_990' => 'nullable|accepted',
                'selected_irs_board_member_id' => 'nullable|integer|exists:irs_board_members,id',
                'primary_action_category_ids' => ['nullable', 'array'],
                'primary_action_category_ids.*' => ['integer', 'distinct', Rule::exists('primary_action_categories', 'id')->where('is_active', true)],
                'preferred_payout_method' => 'nullable|string|in:stripe,paypal',
                'referralCode' => 'nullable|string|max:64',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();
            $docKeys = ['doc_501c3', 'doc_articles', 'doc_bylaws', 'doc_state_registration', 'doc_board_list', 'doc_signer_resolution', 'doc_bank_account'];

            // When a real EIN matches IRS records, force org/address fields from IRS (not client-editable)
            if ($validated['has_ein'] ?? false) {
                $irsRecord = $this->einLookupService->lookupEIN($validated['ein']);
                if (is_array($irsRecord) && ! empty($irsRecord['name'])) {
                    foreach (['name', 'street', 'city', 'state', 'zip', 'ico', 'classification', 'ruling', 'deductibility', 'organization', 'status', 'tax_period', 'filing_req', 'ntee_code'] as $irsField) {
                        if (array_key_exists($irsField, $irsRecord) && $irsRecord[$irsField] !== null && $irsRecord[$irsField] !== '') {
                            $validated[$irsField] = $irsRecord[$irsField];
                        }
                    }
                    $request->merge(['has_edited_irs_data' => false]);
                }
            }

            // 990 officer match + attestation (policy: allow when name matches 990 officer and user self-attests)
            $ein = $validated['ein'];
            $contactName = $validated['contact_name'];
            $contactTitle = $validated['contact_title'] ?? null;
            $selectedIrsBoardMemberId = $validated['selected_irs_board_member_id'] ?? null;

            $simplifiedRegistration = ! $request->hasFile('officer_id');
            $matchResult = ($validated['has_ein'] ?? true)
                ? $this->orgClaim990Service->matchRegistrantToOfficers($contactName, $contactTitle, $ein)
                : ['status' => 'no_match', 'possible_matches' => []];

            $verificationSource = OrgClaim990Service::VERIFICATION_SOURCE;
            $claimVerificationMetadata = null;

            if (! $simplifiedRegistration && $matchResult['status'] === 'single_match') {
                $claimVerificationMetadata = $this->orgClaim990Service->buildVerificationMetadata(
                    (int) $matchResult['matched_id'],
                    $matchResult['tax_year'] ?? null,
                    isset($matchResult['matched_position']) ? 'name_and_title' : 'name'
                );
            } elseif (! $simplifiedRegistration && $matchResult['status'] === 'multiple_matches') {
                if (! $selectedIrsBoardMemberId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'We found multiple possible matches on your organization\'s IRS filing. Please select your name.',
                        'errors' => ['selected_irs_board_member_id' => ['Please select your name from the list.']],
                        'possible_matches' => $matchResult['possible_matches'] ?? [],
                    ], 422);
                }
                $irsMember = IrsBoardMember::where('id', $selectedIrsBoardMemberId)->where('ein', $ein)->first();
                if (! $irsMember) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid selection. Please select your name from the list.',
                        'errors' => ['selected_irs_board_member_id' => ['Invalid selection.']],
                    ], 422);
                }
                $claimVerificationMetadata = $this->orgClaim990Service->buildVerificationMetadata(
                    $irsMember->id,
                    $irsMember->tax_year,
                    'user_selected'
                );
            } else {
                // Simplified 3-step registration or no 990 match: allow with self-attestation
                $claimVerificationMetadata = [
                    'verification_source' => $verificationSource,
                    'match_type' => $simplifiedRegistration
                        ? 'simplified_registration'
                        : (empty($matchResult['possible_matches'] ?? []) ? 'no_officers_in_990' : 'no_match_self_attestation'),
                    'verified_at' => now()->toIso8601String(),
                ];
            }

            if ($request->hasFile('officer_id')) {
                $officerIdPath = $request->file('officer_id')->store('organizations/officer_id', 'public');
                $claimVerificationMetadata['officer_id_upload_path'] = $officerIdPath;
            }
            $claimVerificationMetadata['legal_name_confirmed_at'] = now()->toIso8601String();

            // Store verification documents (6 of 7 required; 990 not needed)
            $verificationDocPaths = [];
            foreach ($docKeys as $key) {
                if ($request->hasFile($key)) {
                    $verificationDocPaths[$key] = $request->file($key)->store('organizations/verification_docs', 'public');
                }
            }
            $claimVerificationMetadata['verification_document_paths'] = $verificationDocPaths;

            $taxEvaluation = $this->taxComplianceService->evaluate($validated['tax_period'] ?? null, $validated['ein']);

            $referrerUserId = null;
            if (! empty($validated['referralCode'])) {
                $referrer = User::where('referral_code', $validated['referralCode'])->first();
                if ($referrer !== null) {
                    $referrerUserId = $referrer->id;
                }
            }

            DB::beginTransaction();

            // Check if IRS data was edited / no real EIN — keep pending until verified
            $hasEditedIRS = $request->boolean('has_edited_irs_data', false) || ! ($validated['has_ein'] ?? true);
            $initialRole = ($hasEditedIRS || $taxEvaluation['should_lock'] || $simplifiedRegistration) ? 'organization_pending' : 'organization';

            // Store original IRS data if edited
            $originalIRSData = null;
            if ($hasEditedIRS) {
                $originalIRSData = $this->einLookupService->lookupEIN($validated['ein']);
            }

            // Generate unique slug from organization name with incremental numbers
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (User::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }

            // Platform email: orgname@believeinunity.org (unique per organization)
            $platformEmailDomain = config('app.platform_email_domain', 'believeinunity.org');
            $platformBase = Str::slug($validated['name']);
            $platformEmail = $platformBase . '@' . $platformEmailDomain;
            $platformCounter = 1;
            while (Organization::where('platform_email', $platformEmail)->exists()) {
                $platformEmail = $platformBase . '-' . $platformCounter . '@' . $platformEmailDomain;
                $platformCounter++;
            }

            $user = User::create([
                "name" => $validated['contact_name'],
                "slug" => $slug,
                "email" => $validated['email'],
                "contact_number" => $validated['phone'],
                "password" => Hash::make($validated['password']),
                "role" => $initialRole,
                "organization_role" => 'admin',
                "referred_by" => null,
            ]);

            // Handle image upload
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('organizations', 'public');

                $user->registered_user_image = $imagePath;
                $user->save();
            }

            $organization = Organization::create([
                'user_id' => $user->id,
                'referrer_user_id' => $referrerUserId,
                'ein' => $validated['ein'],
                'name' => $validated['name'],
                'ico' => $validated['ico'] ?? null,
                'street' => $validated['street'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'zip' => $validated['zip'],
                'classification' => $validated['classification'] ?? null,
                'ruling' => $validated['ruling'] ?? null,
                'deductibility' => $validated['deductibility'] ?? null,
                'organization' => $validated['organization'] ?? null,
                'status' => $taxEvaluation['should_lock'] ? 'Inactive' : ($validated['status'] ?? 'Active'),
                'tax_period' => $validated['tax_period'] ?? null,
                'filing_req' => $validated['filing_req'] ?? null,
                'ntee_code' => $validated['ntee_code'] ?? null,
                'community_organization_type_id' => $validated['community_organization_type_id'] ?? null,
                'community_organization_type_other' => (int) ($validated['community_organization_type_id'] ?? 0) === (int) CommunityOrganizationType::otherId()
                    ? ($validated['community_organization_type_other'] ?? null)
                    : null,
                'legal_entity_status' => $validated['legal_entity_status'] ?? null,
                'legal_entity_status_other' => ($validated['legal_entity_status'] ?? null) === 'other'
                    ? ($validated['legal_entity_status_other'] ?? null)
                    : null,
                'has_ein' => (bool) ($validated['has_ein'] ?? true),
                'has_members' => (bool) ($validated['has_members'] ?? false),
                'memberships_enabled' => (bool) ($validated['memberships_enabled'] ?? false),
                'membership_type' => $validated['membership_type'] ?? null,
                'email' => $validated['email'],
                'platform_email' => $platformEmail,
                'phone' => $validated['phone'],
                'contact_name' => $validated['contact_name'],
                'contact_title' => $validated['contact_title'],
                'website' => $validated['website'] ?? null,
                'description' => $validated['description'],
                'mission' => $validated['mission'],
                'registered_user_image' => $imagePath,
                'registration_status' => ($hasEditedIRS || $simplifiedRegistration || $taxEvaluation['should_lock'])
                    ? 'pending'
                    : 'approved',
                'verification_source' => $verificationSource,
                'claim_verification_metadata' => $claimVerificationMetadata,
                'has_edited_irs_data' => $hasEditedIRS,
                'original_irs_data' => $originalIRSData,
                'tax_compliance_status' => $taxEvaluation['status'],
                'tax_compliance_checked_at' => $taxEvaluation['checked_at'],
                'tax_compliance_meta' => $taxEvaluation['meta'],
                'is_compliance_locked' => $taxEvaluation['should_lock'],
                'preferred_payout_method' => $validated['preferred_payout_method'] ?? null,
            ]);

            $organization->primaryActionCategories()->sync(
                array_values(array_unique(array_map('intval', $validated['primary_action_category_ids'] ?? [])))
            );

            $this->membershipAccountService->bootstrapFromRegistration($organization, [
                'memberships_enabled' => (bool) ($validated['memberships_enabled'] ?? false),
                'membership_name' => $validated['membership_name'] ?? $validated['name'],
                'membership_type' => $validated['membership_type'] ?? 'free',
                'join_method' => $validated['join_method'] ?? MembershipJoinMethod::RequestToJoin->value,
            ]);

            if ((int) ($validated['community_organization_type_id'] ?? 0) === (int) CommunityOrganizationType::familyReunionId()) {
                app(FamilyReunionService::class)->saveFoundingCouple(
                    $organization,
                    [
                        'grandfather_name' => $validated['grandfather_name'],
                        'grandmother_name' => $validated['grandmother_name'],
                        'grandfather_birth_year' => $validated['grandfather_birth_year'] ?? null,
                        'grandfather_death_year' => $validated['grandfather_death_year'] ?? null,
                        'grandmother_birth_year' => $validated['grandmother_birth_year'] ?? null,
                        'grandmother_death_year' => $validated['grandmother_death_year'] ?? null,
                    ],
                    $request->file('grandfather_photo'),
                    $request->file('grandmother_photo'),
                    $user,
                );
            }

            $this->syncOrganizationUserRole($user, $organization);
            app(CauseGroupChatService::class)->ensureForUser($user->fresh());


            // Create board member record
            $boardMember = BoardMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'position' => $validated['contact_title'],
                'appointed_on' => now(),
            ]);

            // Record history
            $boardMember->histories()->create([
                'action' => 'appointed',
                'details' => 'Registered as ' . $validated['contact_title'],
                'changed_by' => $user->id,
            ]);

            // Handle organization invite if token was provided
            if ($request->has('invite_token')) {
                $invite = OrganizationInvite::where('token', $request->invite_token)
                    ->where('ein', $validated['ein'])
                    ->where('status', '!=', 'accepted')
                    ->first();

                if ($invite) {
                    $invite->update([
                        'status' => 'accepted',
                        'accepted_at' => now(),
                    ]);

                    Log::info('Organization invite accepted', [
                        'invite_id' => $invite->id,
                        'organization_id' => $organization->id,
                        'inviter_id' => $invite->inviter_id,
                    ]);

                    // Believe Points schedule for the inviter starts when the org verifies email
                    // (installment 1); further months run via organizations:process-invite-believe-point-schedule.
                    // See: App\Listeners\AwardInviteRewardPoints
                }
            }

            DB::commit();

            Log::info('Organization Registration Success', ['organization_id' => $organization->id]);

            // Create Bridge customer for the organization (non-blocking)
            try {
                $this->createBridgeCustomer($organization, $user);
            } catch (\Exception $e) {
                // Log error but don't fail registration
                Log::error('Failed to create Bridge customer during organization registration', [
                    'organization_id' => $organization->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            event(new Registered($user));

            // Send verification email with current domain (where user is registering from)
            // Use actual request host, not config value
            $scheme = $request->getScheme();
            $host = $request->getHost();
            $port = $request->getPort();
            $currentDomain = $scheme . '://' . $host . ($port && $port != 80 && $port != 443 ? ':' . $port : '');
            $user->sendEmailVerificationNotification($currentDomain);

            Auth::login($user);

            // Return success response
            return response()->json([
                'success' => true,
                'message' => $hasEditedIRS
                    ? 'Application submitted successfully! We will review it within 3-5 business days.'
                    : 'Organization registered successfully!',
                'organization' => $organization
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Organization Registration Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function syncOrganizationUserRole(User $user, Organization $organization): void
    {
        if (!$user) {
            return;
        }

        $targetRole = ($organization->registration_status === 'approved' && !$organization->is_compliance_locked)
            ? 'organization'
            : 'organization_pending';

        Role::findOrCreate($targetRole);

        if ($user->hasRole($targetRole) && $user->roles()->count() === 1 && $user->role === $targetRole) {
            return;
        }

        $user->syncRoles([$targetRole]);

        if ($user->role !== $targetRole) {
            $user->role = $targetRole;
            $user->save();
        }
    }

    /**
     * Create Bridge customer for organization
     */
    private function createBridgeCustomer(Organization $organization, User $user): void
    {
        // Check if Bridge customer already exists
        $existingIntegration = BridgeIntegration::where('integratable_id', $organization->id)
            ->where('integratable_type', Organization::class)
            ->first();

        if ($existingIntegration && $existingIntegration->bridge_customer_id) {
            Log::info('Bridge customer already exists for organization', [
                'organization_id' => $organization->id,
                'bridge_customer_id' => $existingIntegration->bridge_customer_id,
            ]);
            return;
        }

        // Prepare customer data
        $email = trim($organization->email ?? $user->email ?? '');
        $name = trim($organization->name ?? $user->name ?? '');

        if (empty($email)) {
            throw new \Exception('Email is required to create Bridge customer');
        }

        if (empty($name)) {
            throw new \Exception('Organization name is required to create Bridge customer');
        }

        // Build registered address from organization data
        $registeredAddress = [
            'street_line_1' => $organization->street ?? '',
            'city' => $organization->city ?? '',
            'subdivision' => $organization->state ?? '',
            'postal_code' => $organization->zip ?? '',
            'country' => 'USA', // Default to USA, can be made configurable
        ];

        // Build identifying information (EIN)
        $identifyingInformation = [];
        if (!empty($organization->ein)) {
            $identifyingInformation[] = [
                'type' => 'ein',
                'issuing_country' => 'usa',
                'number' => $organization->ein,
            ];
        }

        // Build customer data with all required fields for Bridge business customer
        $customerData = [
            'type' => 'business',
            'business_legal_name' => $name, // Bridge expects business_legal_name, not business_name
            'email' => $email,
            'registered_address' => $registeredAddress,
            'physical_address' => $registeredAddress, // Use same as registered if physical not provided
            'identifying_information' => $identifyingInformation,
            'business_description' => $organization->description ?? $organization->mission ?? 'Business operations',
        ];

        // Add optional fields if available
        if (!empty($organization->phone)) {
            $customerData['phone'] = $organization->phone;
        }
        if (!empty($organization->website)) {
            $customerData['primary_website'] = $organization->website;
        }

        // Add business type if available (default to corporation)
        $customerData['business_type'] = $this->mapEntityTypeToBridgeType($organization->classification ?? 'corporation');

        // Add DAO status if available
        $customerData['is_dao'] = false; // Default to false, can be updated later during KYB

        // Add attested ownership structure timestamp
        $customerData['attested_ownership_structure_at'] = now()->toIso8601String();

        Log::info('Creating Bridge customer for organization', [
            'organization_id' => $organization->id,
            'customer_data' => $customerData,
        ]);

        // Create business customer in Bridge using createBusinessCustomer method
        $customerResult = $this->bridgeService->createBusinessCustomer($customerData);

        if (!$customerResult['success']) {
            Log::error('Failed to create Bridge customer', [
                'organization_id' => $organization->id,
                'error' => $customerResult['error'] ?? 'Unknown error',
                'response' => $customerResult['response'] ?? null,
            ]);
            throw new \Exception($customerResult['error'] ?? 'Failed to create Bridge customer');
        }

        $bridgeCustomerId = $customerResult['data']['id'] ?? $customerResult['data']['customer_id'] ?? null;

        if (!$bridgeCustomerId) {
            Log::error('Bridge customer ID not returned', [
                'organization_id' => $organization->id,
                'response' => $customerResult['data'] ?? null,
            ]);
            throw new \Exception('Bridge customer ID not returned');
        }

        // Save Bridge integration
        if (!$existingIntegration) {
            $existingIntegration = new BridgeIntegration();
            $existingIntegration->integratable_id = $organization->id;
            $existingIntegration->integratable_type = Organization::class;
        }

        $existingIntegration->bridge_customer_id = $bridgeCustomerId;
        $existingIntegration->bridge_metadata = [
            'customer_data' => $customerResult['data'],
            'created_at_registration' => true,
            'registration_data' => [
                'name' => $name,
                'email' => $email,
                'ein' => $organization->ein,
                'address' => $registeredAddress,
            ],
        ];
        $existingIntegration->save();

        Log::info('Bridge customer created successfully for organization', [
            'organization_id' => $organization->id,
            'bridge_customer_id' => $bridgeCustomerId,
        ]);
    }

    /**
     * Map organization classification/entity type to Bridge business type
     * 
     * @param string|null $entityType
     * @return string
     */
    private function mapEntityTypeToBridgeType(?string $entityType): string
    {
        if (empty($entityType)) {
            return 'corporation';
        }

        $entityTypeLower = strtolower(trim($entityType));

        // Map common entity types to Bridge business types
        $mapping = [
            'llc' => 'llc',
            'limited liability company' => 'llc',
            'corporation' => 'corporation',
            'corp' => 'corporation',
            'inc' => 'corporation',
            'partnership' => 'partnership',
            'cooperative' => 'cooperative',
            'trust' => 'trust',
            'sole proprietorship' => 'sole_prop',
            'sole_prop' => 'sole_prop',
        ];

        return $mapping[$entityTypeLower] ?? 'corporation';
    }
}

<?php

namespace App\Services;

use App\Enums\MembershipAccountType;
use App\Enums\MembershipInvitationStatus;
use App\Enums\MembershipJoinMethod;
use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipPlanStatus;
use App\Enums\MembershipType;
use App\Enums\SupporterMembershipStatus;
use App\Models\CareAlliance;
use App\Models\Group;
use App\Models\MembershipInvitation;
use App\Models\MembershipPlan;
use App\Models\Organization;
use App\Models\SupporterMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MembershipAccountService
{
    /**
     * @return array{account: Model, account_type: MembershipAccountType}
     */
    public function resolveForUser(User $user, ?string $accountType = null, ?int $accountId = null): array
    {
        if ($accountType !== null && $accountId !== null) {
            $type = MembershipAccountType::from($accountType);
            $account = $type->modelClass()::query()->findOrFail($accountId);
            $this->authorizeManagement($user, $account);

            return ['account' => $account, 'account_type' => $type];
        }

        if ($user->hasRole('care_alliance')) {
            $alliance = CareAlliance::query()->where('creator_user_id', $user->id)->first();
            if ($alliance) {
                return ['account' => $alliance, 'account_type' => MembershipAccountType::UnityImpactAlliance];
            }
        }

        $organization = Organization::forAuthUser($user);
        if ($organization) {
            return ['account' => $organization, 'account_type' => MembershipAccountType::Organization];
        }

        throw new InvalidArgumentException('No membership account found for this user.');
    }

    public function authorizeManagement(User $user, Model $account): void
    {
        if (! $this->canManage($user, $account)) {
            abort(403);
        }
    }

    public function canManage(User $user, Model $account): bool
    {
        if ($account instanceof Organization) {
            $org = Organization::forAuthUser($user);

            return $org !== null && (int) $org->id === (int) $account->id;
        }

        if ($account instanceof CareAlliance) {
            return (int) $account->creator_user_id === (int) $user->id;
        }

        if ($account instanceof Group) {
            return $account->isManagedBy($user);
        }

        return false;
    }

    /**
     * Create or skip membership plan during org/alliance registration.
     *
     * @param  array{
     *     memberships_enabled?: bool,
     *     membership_name?: string|null,
     *     membership_type?: string|null,
     *     join_method?: string|null,
     *     membership_fee?: mixed,
     *     billing_frequency?: mixed
     * }  $data
     */
    public function bootstrapFromRegistration(Model $account, array $data): ?MembershipPlan
    {
        $enabled = (bool) ($data['memberships_enabled'] ?? false);

        if (! $enabled) {
            $account->memberships_enabled = false;
            $account->save();

            return null;
        }

        $membershipType = in_array($data['membership_type'] ?? null, MembershipType::values(), true)
            ? $data['membership_type']
            : MembershipType::Free->value;

        $joinMethod = in_array($data['join_method'] ?? null, MembershipJoinMethod::values(), true)
            ? $data['join_method']
            : MembershipJoinMethod::RequestToJoin->value;

        $membershipName = trim((string) ($data['membership_name'] ?? ''));
        if ($membershipName === '') {
            $membershipName = match (true) {
                $account instanceof Organization => (string) ($account->name ?? 'Member'),
                $account instanceof CareAlliance => (string) ($account->name ?? 'Member'),
                $account instanceof Group => (string) ($account->name ?? 'Member'),
                default => 'Member',
            };
        }

        return $this->updateSettings($account, [
            'memberships_enabled' => true,
            'membership_name' => $membershipName,
            'membership_type' => $membershipType,
            'membership_fee' => $data['membership_fee'] ?? null,
            'billing_frequency' => $data['billing_frequency'] ?? null,
            'join_method' => $joinMethod,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateSettings(Model $account, array $validated): MembershipPlan
    {
        $accountType = MembershipAccountType::fromModel($account);

        return DB::transaction(function () use ($account, $accountType, $validated) {
            $account->memberships_enabled = (bool) ($validated['memberships_enabled'] ?? false);
            $account->save();

            if (! $account->memberships_enabled) {
                return $account->membershipPlan()->first() ?? new MembershipPlan([
                    'account_id' => $account->id,
                    'account_type' => $accountType->value,
                ]);
            }

            $membershipName = trim((string) ($validated['membership_name'] ?? ''));
            if ($membershipName === '') {
                $membershipName = match (true) {
                    $account instanceof Organization => (string) ($account->name ?? 'Member'),
                    $account instanceof CareAlliance => (string) ($account->name ?? 'Member'),
                    $account instanceof Group => (string) ($account->name ?? 'Member'),
                    default => 'Member',
                };
            }

            $plan = MembershipPlan::query()->firstOrNew([
                'account_type' => $accountType->value,
                'account_id' => $account->id,
            ]);

            $plan->fill([
                'membership_name' => $membershipName,
                'membership_type' => $validated['membership_type'],
                'membership_fee' => ($validated['membership_type'] ?? null) === MembershipType::Paid->value
                    ? $validated['membership_fee']
                    : null,
                'billing_frequency' => $validated['billing_frequency'] ?? null,
                'join_method' => $validated['join_method'],
                'status' => MembershipPlanStatus::Active->value,
            ]);
            $plan->save();

            return $plan;
        });
    }

    /**
     * @return array{membership: SupporterMembership, checkout_url: string|null, requires_payment: bool}
     */
    public function initiateSupporterMembership(User $supporter, Model $account): array
    {
        $membership = $this->createSupporterRequest($supporter, $account);
        $checkoutUrl = null;
        $requiresPayment = $this->membershipRequiresPayment($membership);

        if ($requiresPayment) {
            $checkoutUrl = $this->createMembershipCheckoutSession($supporter, $membership);
        } elseif ($membership->membershipPlan?->join_method === MembershipJoinMethod::OpenEnrollment) {
            $membership = $this->markMembershipVerified($membership);
        }

        return [
            'membership' => $membership->fresh(['membershipPlan']),
            'checkout_url' => $checkoutUrl,
            'requires_payment' => $requiresPayment,
        ];
    }

    public function createSupporterRequest(User $supporter, Model $account, ?MembershipInvitation $invitation = null): SupporterMembership
    {
        if (! $supporter->canFollowOrganizations()) {
            abort(403, 'Only supporter accounts can request membership.');
        }

        if (! (bool) $account->memberships_enabled) {
            abort(422, 'Memberships are not enabled for this account.');
        }

        $plan = $account->membershipPlan;
        if ($plan === null || $plan->status !== MembershipPlanStatus::Active) {
            abort(422, 'No active membership plan is configured.');
        }

        $joinMethod = $plan->join_method ?? MembershipJoinMethod::RequestToJoin;

        if ($joinMethod === MembershipJoinMethod::InvitationOnly && $invitation === null) {
            abort(422, 'Membership for this account is invitation only.');
        }

        if ($invitation !== null) {
            $this->assertInvitationMatches($invitation, $supporter, $account, $plan);
        }

        if ($this->canManage($supporter, $account)) {
            abort(422, 'You cannot join membership for an account you manage.');
        }

        $accountType = MembershipAccountType::fromModel($account);

        $existing = SupporterMembership::query()
            ->where('supporter_id', $supporter->id)
            ->where('account_type', $accountType->value)
            ->where('account_id', $account->id)
            ->whereIn('status', [
                SupporterMembershipStatus::Pending->value,
                SupporterMembershipStatus::Verified->value,
            ])
            ->first();

        if ($existing) {
            abort(422, 'You already have an active or pending membership with this account.');
        }

        $isPaid = $plan->membership_type === MembershipType::Paid;
        $paymentStatus = $isPaid ? MembershipPaymentStatus::Unpaid : MembershipPaymentStatus::Waived;

        if ($isPaid && ! $this->accountCanAcceptPaidMemberships($account)) {
            abort(422, 'Paid memberships are not available until Stripe payout is configured for the receiving organization.');
        }

        $initialStatus = SupporterMembershipStatus::Pending;
        if ($invitation !== null && ! $isPaid && $joinMethod === MembershipJoinMethod::InvitationOnly) {
            $initialStatus = SupporterMembershipStatus::Verified;
        }

        return DB::transaction(function () use ($supporter, $plan, $account, $accountType, $invitation, $isPaid, $paymentStatus, $initialStatus) {
            $membership = SupporterMembership::query()->create([
                'supporter_id' => $supporter->id,
                'membership_plan_id' => $plan->id,
                'membership_invitation_id' => $invitation?->id,
                'account_id' => $account->id,
                'account_type' => $accountType->value,
                'status' => $initialStatus->value,
                'payment_status' => $paymentStatus->value,
                'payment_amount' => $isPaid ? $plan->membership_fee : null,
                'requested_at' => now(),
                'approved_at' => $initialStatus === SupporterMembershipStatus::Verified ? now() : null,
            ]);

            if ($invitation !== null) {
                $invitation->update([
                    'status' => MembershipInvitationStatus::Accepted,
                    'supporter_id' => $supporter->id,
                    'accepted_at' => now(),
                ]);
            }

            return $membership;
        });
    }

    /**
     * @return array{membership: SupporterMembership, checkout_url: string|null, requires_payment: bool}
     */
    public function acceptInvitation(User $supporter, MembershipInvitation $invitation): array
    {
        $invitation->loadMissing('membershipPlan', 'account');
        $account = $invitation->account;
        if ($account === null) {
            abort(404, 'Membership account not found.');
        }

        if ($invitation->status !== MembershipInvitationStatus::Pending) {
            abort(422, 'This invitation is no longer available.');
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            $invitation->update(['status' => MembershipInvitationStatus::Expired]);
            abort(422, 'This invitation has expired.');
        }

        $membership = $this->createSupporterRequest($supporter, $account, $invitation);
        $checkoutUrl = null;
        $requiresPayment = $this->membershipRequiresPayment($membership);

        if ($requiresPayment) {
            $checkoutUrl = $this->createMembershipCheckoutSession($supporter, $membership);
        } elseif ($membership->status === SupporterMembershipStatus::Pending
            && ($membership->membershipPlan?->join_method === MembershipJoinMethod::OpenEnrollment
                || $membership->membershipPlan?->join_method === MembershipJoinMethod::InvitationOnly)) {
            $membership = $this->markMembershipVerified($membership);
        }

        return [
            'membership' => $membership->fresh(['membershipPlan']),
            'checkout_url' => $checkoutUrl,
            'requires_payment' => $requiresPayment,
        ];
    }

    public function sendInvitation(User $manager, Model $account, string $email): MembershipInvitation
    {
        $this->authorizeManagement($manager, $account);

        if (! (bool) $account->memberships_enabled) {
            abort(422, 'Memberships are not enabled for this account.');
        }

        $plan = $account->membershipPlan;
        if ($plan === null || $plan->status !== MembershipPlanStatus::Active) {
            abort(422, 'Configure an active membership plan before sending invitations.');
        }

        if ($plan->join_method !== MembershipJoinMethod::InvitationOnly) {
            abort(422, 'Invitations can only be sent when the join method is Invitation Only.');
        }

        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            abort(422, 'A valid email address is required.');
        }

        $accountType = MembershipAccountType::fromModel($account);

        $existingInvite = MembershipInvitation::query()
            ->where('account_type', $accountType->value)
            ->where('account_id', $account->id)
            ->where('email', $normalizedEmail)
            ->where('status', MembershipInvitationStatus::Pending->value)
            ->first();

        if ($existingInvite) {
            abort(422, 'A pending invitation already exists for this email.');
        }

        return MembershipInvitation::query()->create([
            'membership_plan_id' => $plan->id,
            'account_id' => $account->id,
            'account_type' => $accountType->value,
            'email' => $normalizedEmail,
            'invited_by_user_id' => $manager->id,
            'token' => Str::random(48),
            'status' => MembershipInvitationStatus::Pending,
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function cancelInvitation(User $manager, MembershipInvitation $invitation): MembershipInvitation
    {
        $account = $invitation->account;
        if ($account === null || ! $this->canManage($manager, $account)) {
            abort(403, 'You are not allowed to manage this invitation.');
        }

        if ($invitation->status !== MembershipInvitationStatus::Pending) {
            abort(422, 'Only pending invitations can be cancelled.');
        }

        $invitation->update(['status' => MembershipInvitationStatus::Cancelled]);

        return $invitation->fresh();
    }

    public function createMembershipCheckoutSession(User $supporter, SupporterMembership $membership): string
    {
        if ((int) $membership->supporter_id !== (int) $supporter->id) {
            abort(403);
        }

        if (! $this->membershipRequiresPayment($membership)) {
            abort(422, 'This membership does not require payment.');
        }

        $membership->loadMissing('membershipPlan', 'account');
        $account = $membership->account;
        if ($account === null) {
            abort(404, 'Membership account not found.');
        }

        $recipient = $this->resolvePaymentOrganization($account);
        if ($recipient === null || ! StripeConnectOrganizationService::organizationCanAcceptConnectPayments($recipient)) {
            abort(422, 'Paid memberships are not available until Stripe payout is configured for the receiving organization.');
        }

        $amountInCents = (int) round(((float) $membership->payment_amount) * 100);
        $planName = $membership->membershipPlan?->membership_name ?? 'Membership';
        $lineItem = sprintf('%s membership — %s', $this->accountDisplayName($account), $planName);

        $session = MembershipStripeCheckoutService::create(
            $supporter,
            $recipient,
            $membership,
            $amountInCents,
            $lineItem,
            route('membership.payment.success', ['membership' => $membership->id]).'?session_id={CHECKOUT_SESSION_ID}',
            route('profile.memberships'),
        );

        $membership->update(['stripe_checkout_session_id' => $session->id]);

        return (string) $session->url;
    }

    public function completeMembershipPayment(SupporterMembership $membership, string $sessionId): SupporterMembership
    {
        StripeConnectOrganizationService::configureStripe();
        $session = \Laravel\Cashier\Cashier::stripe()->checkout->sessions->retrieve($sessionId);

        if ($membership->stripe_checkout_session_id !== null
            && $membership->stripe_checkout_session_id !== $sessionId) {
            abort(422, 'Checkout session does not match this membership.');
        }

        if (($session->payment_status ?? null) !== 'paid') {
            abort(422, 'Payment has not been completed yet.');
        }

        $piRef = $session->payment_intent ?? null;
        $paymentIntentId = is_string($piRef) ? $piRef : ($piRef->id ?? null);
        if (! is_string($paymentIntentId) || $paymentIntentId === '') {
            abort(422, 'Payment intent was not found for this checkout session.');
        }

        return MembershipStripePaymentCompletion::completeSuccessfulPayment(
            $membership,
            $sessionId,
            $paymentIntentId,
        );
    }

    public function finalizeMembershipAfterPayment(SupporterMembership $membership): SupporterMembership
    {
        $membership->loadMissing('membershipPlan');
        $joinMethod = $membership->membershipPlan?->join_method;

        if ($joinMethod === MembershipJoinMethod::OpenEnrollment
            || $joinMethod === MembershipJoinMethod::InvitationOnly) {
            return $this->markMembershipVerified($membership);
        }

        return $membership->fresh();
    }

    public function resolvePaymentOrganizationForMembership(Model $account): ?Organization
    {
        return $this->resolvePaymentOrganization($account);
    }

    public function approveMembership(User $manager, SupporterMembership $membership): SupporterMembership
    {
        $this->authorizeMembershipManagement($manager, $membership);

        if ($membership->status !== SupporterMembershipStatus::Pending) {
            abort(422, 'Only pending membership requests can be approved.');
        }

        $membership->loadMissing('membershipPlan');
        if ($membership->membershipPlan?->membership_type === MembershipType::Paid
            && $membership->payment_status !== MembershipPaymentStatus::Paid) {
            abort(422, 'Payment must be completed before this membership can be approved.');
        }

        $membership->update([
            'status' => SupporterMembershipStatus::Verified,
            'approved_at' => now(),
        ]);

        return $membership->fresh(['supporter:id,name,email,slug,image']);
    }

    public function declineMembership(User $manager, SupporterMembership $membership): SupporterMembership
    {
        $this->authorizeMembershipManagement($manager, $membership);

        if ($membership->status !== SupporterMembershipStatus::Pending) {
            abort(422, 'Only pending membership requests can be declined.');
        }

        $membership->update([
            'status' => SupporterMembershipStatus::Declined,
            'approved_at' => null,
        ]);

        return $membership->fresh(['supporter:id,name,email,slug,image']);
    }

    public function revokeMembership(User $manager, SupporterMembership $membership): SupporterMembership
    {
        $this->authorizeMembershipManagement($manager, $membership);

        if ($membership->status !== SupporterMembershipStatus::Verified) {
            abort(422, 'Only verified members can be removed.');
        }

        $membership->update([
            'status' => SupporterMembershipStatus::Expired,
            'approved_at' => null,
        ]);

        return $membership->fresh(['supporter:id,name,email,slug,image']);
    }

    public function cancelBySupporter(User $supporter, SupporterMembership $membership): SupporterMembership
    {
        if ((int) $membership->supporter_id !== (int) $supporter->id) {
            abort(403, 'You can only manage your own membership requests.');
        }

        if (! $supporter->canFollowOrganizations()) {
            abort(403, 'Only supporter accounts can manage membership requests.');
        }

        $nextStatus = match ($membership->status) {
            SupporterMembershipStatus::Pending => SupporterMembershipStatus::Declined,
            SupporterMembershipStatus::Verified => SupporterMembershipStatus::Expired,
            default => null,
        };

        if ($nextStatus === null) {
            abort(422, 'This membership request cannot be cancelled.');
        }

        $membership->update([
            'status' => $nextStatus,
            'approved_at' => $nextStatus === SupporterMembershipStatus::Expired ? null : $membership->approved_at,
        ]);

        return $membership->fresh();
    }

    private function authorizeMembershipManagement(User $manager, SupporterMembership $membership): void
    {
        $account = $membership->account;
        if ($account === null || ! $this->canManage($manager, $account)) {
            abort(403, 'You are not allowed to manage this membership.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(Model $account): array
    {
        $plan = $account->membershipPlan;

        return [
            'memberships_enabled' => (bool) $account->memberships_enabled,
            'account_type' => MembershipAccountType::fromModel($account)->value,
            'account_id' => $account->id,
            'plan' => $plan?->toSettingsPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function managementPayload(Model $account): array
    {
        $accountType = MembershipAccountType::fromModel($account);
        $pendingQuery = $account->memberships()
            ->where('status', SupporterMembershipStatus::Pending->value);
        $verifiedQuery = $account->memberships()
            ->where('status', SupporterMembershipStatus::Verified->value);

        $pending = (clone $pendingQuery)->count();
        $verified = (clone $verifiedQuery)->count();

        $accountName = match (true) {
            $account instanceof Organization => $account->name,
            $account instanceof CareAlliance => $account->name,
            $account instanceof Group => $account->name,
            default => 'Account',
        };

        return [
            'account' => [
                'id' => $account->id,
                'name' => $accountName,
                'type' => $accountType->value,
                'memberships_enabled' => (bool) $account->memberships_enabled,
            ],
            'counts' => [
                'pending' => $pending,
                'verified' => $verified,
            ],
            'pending_members' => $this->membershipManagementRows($pendingQuery),
            'verified_members' => $this->membershipManagementRows($verifiedQuery),
            'invitations' => $this->membershipInvitationRows($account),
            ...$this->stripeReadinessPayload($account),
            'plan' => $account->membershipPlan?->toSettingsPayload(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membershipManagementRows($query): array
    {
        return $query
            ->with('supporter:id,name,email,slug,image')
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (SupporterMembership $membership) {
                $supporter = $membership->supporter;

                return [
                    'id' => $membership->id,
                    'status' => $membership->status->value,
                    'status_label' => $membership->status->label(),
                    'requested_at' => $membership->requested_at?->toISOString(),
                    'approved_at' => $membership->approved_at?->toISOString(),
                    'payment_status' => $membership->payment_status?->value,
                    'payment_status_label' => $membership->payment_status?->label(),
                    'payment_amount' => $membership->payment_amount !== null ? (string) $membership->payment_amount : null,
                    'requires_payment' => $this->membershipRequiresPayment($membership),
                    'supporter' => [
                        'id' => $supporter?->id,
                        'name' => $supporter?->name ?? 'Unknown supporter',
                        'email' => $supporter?->email,
                        'slug' => $supporter?->slug,
                        'profile_url' => $supporter?->slug ? route('users.show', $supporter->slug) : null,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Public-facing payload for org/alliance/group profile join UI.
     *
     * @return array<string, mixed>|null
     */
    public function publicJoinPayload(Model $account, ?User $viewer): ?array
    {
        if (! (bool) $account->memberships_enabled) {
            return null;
        }

        $account->loadMissing('membershipPlan');
        $plan = $account->membershipPlan;

        if ($plan === null || $plan->status !== MembershipPlanStatus::Active) {
            $plan = $this->ensureActiveMembershipPlan($account);
        }

        if ($plan === null) {
            return null;
        }

        $accountType = MembershipAccountType::fromModel($account);
        $joinMethod = $plan->join_method ?? MembershipJoinMethod::RequestToJoin;

        $existing = null;
        if ($viewer !== null) {
            $existing = SupporterMembership::query()
                ->where('supporter_id', $viewer->id)
                ->where('account_type', $accountType->value)
                ->where('account_id', $account->id)
                ->latest('id')
                ->first();
        }

        $isSupporter = $viewer !== null && $viewer->canFollowOrganizations();
        $isManager = $viewer !== null && $this->canManage($viewer, $account);

        $hasBlockingStatus = $existing !== null && in_array($existing->status, [
            SupporterMembershipStatus::Pending,
            SupporterMembershipStatus::Verified,
        ], true);

        $canRequest = $isSupporter
            && ! $isManager
            && ! $hasBlockingStatus
            && $joinMethod !== MembershipJoinMethod::InvitationOnly;

        $pendingInvitation = $viewer !== null
            ? $this->findPendingInvitationForViewer($viewer, $account, $accountType)
            : null;

        $canAcceptInvitation = $pendingInvitation !== null
            && $isSupporter
            && ! $isManager
            && ! $hasBlockingStatus;

        $isPaid = $plan->membership_type === MembershipType::Paid;
        $stripePayload = $this->stripeReadinessPayload($account, includeSetupLink: false);
        $stripeReady = ! $isPaid || $stripePayload['stripe_ready'];

        return [
            'account_type' => $accountType->value,
            'account_id' => $account->id,
            'account_name' => $this->accountDisplayName($account),
            'plan' => [
                'membership_name' => $plan->membership_name,
                'membership_type' => $plan->membership_type?->value,
                'membership_fee' => $plan->membership_fee !== null ? (string) $plan->membership_fee : null,
                'billing_frequency' => $plan->billing_frequency,
                'join_method' => $joinMethod->value,
                'join_method_label' => $joinMethod->label(),
            ],
            'invitation_only' => $joinMethod === MembershipJoinMethod::InvitationOnly,
            'requires_login' => $viewer === null,
            'is_supporter' => $isSupporter,
            'can_request' => $canRequest && $stripeReady,
            'can_accept_invitation' => $canAcceptInvitation && $stripeReady,
            'pending_invitation' => $pendingInvitation ? [
                'id' => $pendingInvitation->id,
                'email' => $pendingInvitation->email,
                'expires_at' => $pendingInvitation->expires_at?->toISOString(),
            ] : null,
            'requires_payment' => $isPaid,
            ...$stripePayload,
            'payment_amount' => $isPaid && $plan->membership_fee !== null ? (string) $plan->membership_fee : null,
            'payment_label' => $isPaid && $plan->membership_fee !== null
                ? '$'.number_format((float) $plan->membership_fee, 2).($plan->billing_frequency ? ' / '.$plan->billing_frequency : '')
                : null,
            'supporter_membership' => $existing ? [
                'id' => $existing->id,
                'status' => $existing->status->value,
                'status_label' => $existing->status->label(),
                'requested_at' => $existing->requested_at?->toISOString(),
                'payment_status' => $existing->payment_status?->value,
                'payment_status_label' => $existing->payment_status?->label(),
            ] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForSupporter(User $supporter): array
    {
        return SupporterMembership::query()
            ->where('supporter_id', $supporter->id)
            ->with([
                'membershipPlan',
                'account' => function ($morph) {
                    $morph->morphWith([
                        Organization::class => ['user:id,slug'],
                    ]);
                },
            ])
            ->latest('id')
            ->get()
            ->map(function (SupporterMembership $membership) {
                $account = $membership->account;
                $accountType = $membership->account_type;

                return [
                    'id' => $membership->id,
                    'status' => $membership->status->value,
                    'status_label' => $membership->status->label(),
                    'requested_at' => $membership->requested_at?->toISOString(),
                    'approved_at' => $membership->approved_at?->toISOString(),
                    'account_type' => $accountType?->value,
                    'account_id' => $membership->account_id,
                    'account_name' => $account ? $this->accountDisplayName($account) : 'Unknown',
                    'membership_name' => $membership->membershipPlan?->membership_name,
                    'profile_url' => $this->accountPublicUrl($account, $accountType),
                    'payment_status' => $membership->payment_status?->value,
                    'payment_status_label' => $membership->payment_status?->label(),
                    'requires_payment' => $this->membershipRequiresPayment($membership),
                    'can_cancel' => in_array($membership->status, [
                        SupporterMembershipStatus::Pending,
                        SupporterMembershipStatus::Verified,
                    ], true),
                    'cancel_label' => match ($membership->status) {
                        SupporterMembershipStatus::Pending => 'Cancel request',
                        SupporterMembershipStatus::Verified => 'Leave membership',
                        default => null,
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membershipInvitationRows(Model $account): array
    {
        $accountType = MembershipAccountType::fromModel($account);

        return MembershipInvitation::query()
            ->where('account_type', $accountType->value)
            ->where('account_id', $account->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (MembershipInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'status' => $invitation->status->value,
                'status_label' => $invitation->status->label(),
                'expires_at' => $invitation->expires_at?->toISOString(),
                'accepted_at' => $invitation->accepted_at?->toISOString(),
                'created_at' => $invitation->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    private function membershipRequiresPayment(SupporterMembership $membership): bool
    {
        $membership->loadMissing('membershipPlan');

        return $membership->membershipPlan?->membership_type === MembershipType::Paid
            && $membership->payment_status === MembershipPaymentStatus::Unpaid;
    }

    private function markMembershipVerified(SupporterMembership $membership): SupporterMembership
    {
        $membership->update([
            'status' => SupporterMembershipStatus::Verified,
            'approved_at' => now(),
        ]);

        return $membership->fresh();
    }

    private function assertInvitationMatches(
        MembershipInvitation $invitation,
        User $supporter,
        Model $account,
        MembershipPlan $plan,
    ): void {
        $accountType = MembershipAccountType::fromModel($account);

        if ($invitation->account_type !== $accountType
            || (int) $invitation->account_id !== (int) $account->id
            || (int) $invitation->membership_plan_id !== (int) $plan->id) {
            abort(422, 'This invitation does not match the selected membership.');
        }

        if ($invitation->status !== MembershipInvitationStatus::Pending) {
            abort(422, 'This invitation is no longer available.');
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            $invitation->update(['status' => MembershipInvitationStatus::Expired]);
            abort(422, 'This invitation has expired.');
        }

        if (strtolower(trim((string) $supporter->email)) !== strtolower(trim((string) $invitation->email))) {
            abort(422, 'This invitation was sent to a different email address.');
        }
    }

    private function findPendingInvitationForViewer(User $viewer, Model $account, MembershipAccountType $accountType): ?MembershipInvitation
    {
        if ($viewer->email === null || trim($viewer->email) === '') {
            return null;
        }

        return MembershipInvitation::query()
            ->where('account_type', $accountType->value)
            ->where('account_id', $account->id)
            ->where('email', strtolower(trim($viewer->email)))
            ->where('status', MembershipInvitationStatus::Pending->value)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();
    }

    public function accountCanAcceptPaidMemberships(Model $account): bool
    {
        $organization = $this->resolvePaymentOrganization($account);

        return $organization !== null
            && StripeConnectOrganizationService::organizationCanAcceptConnectPayments($organization);
    }

    /**
     * @return array{stripe_ready: bool, stripe_setup_url: string|null, stripe_setup_label: string|null}
     */
    private function stripeReadinessPayload(Model $account, bool $includeSetupLink = true): array
    {
        $organization = $this->resolvePaymentOrganization($account);
        $stripeReady = $organization !== null
            && StripeConnectOrganizationService::organizationCanAcceptConnectPayments($organization);

        return [
            'stripe_ready' => $stripeReady,
            'stripe_setup_url' => ! $stripeReady && $includeSetupLink ? route('integrations.payout-settings') : null,
            'stripe_setup_label' => ! $stripeReady && $includeSetupLink ? 'Stripe payout settings' : null,
        ];
    }

    private function resolvePaymentOrganization(Model $account): ?Organization
    {
        if ($account instanceof Organization) {
            return $account;
        }

        if ($account instanceof CareAlliance) {
            $account->loadMissing('hubOrganization');

            return $account->hubOrganization;
        }

        if ($account instanceof Group) {
            $account->loadMissing('parent');
            $parent = $account->parent;

            if ($parent instanceof Organization) {
                return $parent;
            }

            if ($parent instanceof CareAlliance) {
                $parent->loadMissing('hubOrganization');

                return $parent->hubOrganization;
            }
        }

        return null;
    }

    private function accountDisplayName(Model $account): string
    {
        return match (true) {
            $account instanceof Organization => (string) ($account->name ?? 'Organization'),
            $account instanceof CareAlliance => (string) ($account->name ?? 'Unity Impact Alliance'),
            $account instanceof Group => (string) ($account->name ?? 'Group'),
            default => 'Account',
        };
    }

    private function ensureActiveMembershipPlan(Model $account): ?MembershipPlan
    {
        if (! (bool) $account->memberships_enabled) {
            return null;
        }

        $existing = $account->membershipPlan;
        if ($existing !== null && $existing->status === MembershipPlanStatus::Active) {
            return $existing;
        }

        $membershipType = MembershipType::Free->value;
        if ($account instanceof Organization && in_array($account->membership_type, MembershipType::values(), true)) {
            $membershipType = $account->membership_type;
        }

        return $this->updateSettings($account, [
            'memberships_enabled' => true,
            'membership_name' => $this->accountDisplayName($account),
            'membership_type' => $membershipType,
            'join_method' => MembershipJoinMethod::RequestToJoin->value,
        ]);
    }

    private function accountPublicUrl(?Model $account, ?MembershipAccountType $accountType): ?string
    {
        if ($account === null || $accountType === null) {
            return null;
        }

        return match ($accountType) {
            MembershipAccountType::Organization => $account instanceof Organization
                ? (function () use ($account) {
                    if (! $account->relationLoaded('user')) {
                        $account->load('user:id,slug');
                    }

                    return $account->user?->slug
                        ? route('organizations.show', $account->user->slug)
                        : null;
                })()
                : null,
            MembershipAccountType::UnityImpactAlliance => $account instanceof CareAlliance
                ? route('alliances.show', $account->slug)
                : null,
            MembershipAccountType::Group => $account instanceof Group
                ? route('groups.show', $account->slug)
                : null,
        };
    }
}

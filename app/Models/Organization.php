<?php

namespace App\Models;

use App\Concerns\ManagesPreferredPayoutMethod;
use App\Contracts\HasPreferredPayoutMethod;
use App\Models\Concerns\HasMembershipAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Organization extends Model implements HasPreferredPayoutMethod
{
    use HasFactory, HasMembershipAccount, ManagesPreferredPayoutMethod;

    /**
     * Resolve the organization for an authenticated user: primary owner (organizations.user_id)
     * or the org linked through board membership (same as User::organization()).
     */
    public static function forAuthUser(User $user): ?self
    {
        $owned = static::query()->where('user_id', $user->id)->first();
        if ($owned) {
            return $owned;
        }

        return $user->organization;
    }

    /**
     * Display name + label for BIU tax intake (Connection Hub / admin course forms).
     * Nonprofit org profile name when the user has an org (owner or board-linked); otherwise the account name.
     *
     * @return array{name: string, label: 'Organization name'|'Your name'}
     */
    public static function biuSellerDisplayForUser(User $user): array
    {
        $org = static::forAuthUser($user);
        if ($org !== null) {
            $n = trim((string) ($org->name ?? ''));
            if ($n !== '') {
                return ['name' => $n, 'label' => 'Organization name'];
            }
        }

        return ['name' => (string) ($user->name ?? ''), 'label' => 'Your name'];
    }

    protected $fillable = [
        'user_id',
        'referrer_user_id',
        'balance',
        'ein',
        'name',
        'stripe_product_id',
        'ico',
        'street',
        'city',
        'state',
        'zip',
        'classification',
        'ruling',
        'deductibility',
        'organization',
        'status',
        'tax_period',
        'filing_req',
        'ntee_code',
        'email',
        'platform_email',
        'phone',
        'contact_name',
        'contact_title',
        'website',
        'wefunder_project_url',
        'youtube_channel_url',
        'youtube_access_token',
        'youtube_refresh_token',
        'youtube_token_expires_at',
        'description',
        'mission',
        'registered_user_image',
        'livestream_overlay_settings',
        'registration_status',
        'verification_source',
        'claim_verification_metadata',
        'has_edited_irs_data',
        'original_irs_data',
        'social_accounts',
        'tax_compliance_status',
        'tax_compliance_checked_at',
        'tax_compliance_meta',
        'is_compliance_locked',
        'gift_card_terms_approved',
        'gift_card_terms_approved_at',
        'stripe_connect_account_id',
        'stripe_connect_charges_enabled',
        'stripe_connect_payouts_enabled',
        'stripe_connect_account_type',
        'preferred_payout_method',
        'paypal_payout_email',
        'paypal_payouts_enabled',
        'paypal_payout_connected_at',
        'dropbox_folder_name',
        'dropbox_governance_provisioned_at',
        'authorized_signer_info',
        'onboarding_completed_at',
        'memberships_enabled',
    ];

    protected $hidden = [
        'youtube_access_token',
        'youtube_refresh_token',
        'dropbox_access_token',
        'dropbox_refresh_token',
    ];

    protected $casts = [
        'original_irs_data' => 'array',
        'has_edited_irs_data' => 'boolean',
        'social_accounts' => 'array',
        'livestream_overlay_settings' => 'array',
        'tax_compliance_checked_at' => 'datetime',
        'tax_compliance_meta' => 'array',
        'claim_verification_metadata' => 'array',
        'is_compliance_locked' => 'boolean',
        'gift_card_terms_approved' => 'boolean',
        'gift_card_terms_approved_at' => 'datetime',
        'stripe_connect_charges_enabled' => 'boolean',
        'stripe_connect_payouts_enabled' => 'boolean',
        'paypal_payouts_enabled' => 'boolean',
        'paypal_payout_connected_at' => 'datetime',
        'youtube_token_expires_at' => 'datetime',
        'dropbox_token_expires_at' => 'datetime',
        'dropbox_governance_provisioned_at' => 'datetime',
        'authorized_signer_info' => 'array',
        'onboarding_completed_at' => 'datetime',
        'memberships_enabled' => 'boolean',
    ];

    public function onboardingDocuments()
    {
        return $this->hasMany(OrganizationOnboardingDocument::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function entityPayouts(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(EntityPayout::class, 'payable');
    }

    public function payoutDisplayName(): string
    {
        return trim((string) ($this->name ?: 'Organization'));
    }

    protected function payoutContactEmailForStripe(): ?string
    {
        return $this->email ?: $this->platform_email ?: $this->user?->email;
    }

    public function primaryActionCategories(): BelongsToMany
    {
        return $this->belongsToMany(PrimaryActionCategory::class, 'org_primary_action_category')
            ->withTimestamps();
    }

    public function groups(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Group::class, 'parent_id')
            ->where('parent_type', \App\Enums\MembershipAccountType::Organization->value);
    }

    public function careAllianceMemberships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CareAllianceMembership::class);
    }

    public function careAllianceInvitations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CareAllianceInvitation::class, 'organization_id');
    }

    public function careAllianceJoinRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CareAllianceJoinRequest::class);
    }

    /**
     * Care Alliance that uses this row as its hub organization (internal record; not a standalone public nonprofit).
     */
    public function careAllianceAsHub(): HasOne
    {
        return $this->hasOne(CareAlliance::class, 'hub_organization_id');
    }

    public function users()
    {
        return $this->hasManyThrough(User::class, BoardMember::class, 'organization_id', 'id', 'id', 'user_id');
    }

    public function contentItems()
    {
        return $this->hasMany(ContentItem::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function activeMembers()
    {
        return $this->users()->where('login_status', true);
    }

    public function nonprofitCompliance(): HasOne
    {
        return $this->hasOne(NonprofitCompliance::class);
    }

    public function form990Filings()
    {
        return $this->hasMany(Form990Filing::class);
    }

    public function getLatestForm990Filing()
    {
        return $this->form990Filings()->latest('tax_year')->first();
    }

    public function getOverdueForm990Filings()
    {
        return $this->form990Filings()
            ->where('is_filed', false)
            ->where(function ($query) {
                $query->where('due_date', '<', now())
                    ->orWhere('extended_due_date', '<', now());
            })
            ->get();
    }

    // Helper methods
    public function getDefaultSettings()
    {
        return [
            'send_time' => '07:00',
            'channels' => ['push'],
            'quiet_hours_start' => '21:00',
            'quiet_hours_end' => '07:00',
        ];
    }

    public function boardMembers()
    {
        return $this->hasMany(BoardMember::class);
    }

    public function projectBoards()
    {
        return $this->hasMany(ProjectBoard::class);
    }

    /**
     * User IDs that should receive wallet push / Reverb updates (owner + board).
     *
     * @return array<int, int>
     */
    public function walletNotificationUserIds(): array
    {
        $ids = collect();

        if ($this->user_id) {
            $ids->push((int) $this->user_id);
        }

        $this->boardMembers()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->each(fn ($uid) => $ids->push((int) $uid));

        return $ids->unique()->filter()->values()->all();
    }

    /**
     * Platform users who should receive database notifications for Care Alliance invitations (owner + board).
     *
     * @return Collection<int, User>
     */
    public function careAllianceInvitationNotifyUsers(): Collection
    {
        $ids = collect();

        if ($this->user_id) {
            $ids->push((int) $this->user_id);
        }

        $this->boardMembers()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->each(fn ($uid) => $ids->push((int) $uid));

        $ids = $ids->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get();
    }

    /**
     * User IDs to notify when a supporter who favorites this nonprofit has a birthday (primary owner + board).
     *
     * @return Collection<int, int>
     */
    public function supporterBirthdayNotifyUserIds(): Collection
    {
        $ids = collect();

        if ($this->user_id) {
            $ids->push((int) $this->user_id);
        }

        $this->boardMembers()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->each(fn ($uid) => $ids->push((int) $uid));

        return $ids->unique()->filter()->values();
    }

    /**
     * Get IRS board members for this organization (by EIN)
     */
    public function irsBoardMembers()
    {
        return $this->hasMany(IrsBoardMember::class, 'ein', 'ein');
    }

    public function newsletterRecipients()
    {
        return $this->hasMany(NewsletterRecipient::class, 'organization_id');
    }

    public function getFormattedEinAttribute()
    {
        return substr($this->ein, 0, 2).'-'.substr($this->ein, 2);
    }

    /**
     * Get the Bridge integration for this organization
     */
    public function bridgeIntegration(): HasOne
    {
        return $this->hasOne(BridgeIntegration::class, 'integratable_id')
            ->where('integratable_type', self::class);
    }

    public function nteeCode()
    {
        return $this->belongsTo(NteeCode::class, 'ntee_code', 'ntee_codes');
    }

    // Scope for active organizations
    public function scopeActive($query)
    {
        return $query->where('registration_status', 'approved');
    }

    /**
     * Exclude rows that only exist as a Care Alliance hub (still used internally; hidden from public org discovery).
     */
    public function scopeExcludingCareAllianceHubs(Builder $query): Builder
    {
        if (! Schema::hasTable('care_alliances') || ! Schema::hasColumn('care_alliances', 'hub_organization_id')) {
            return $query;
        }

        return $query->whereNotExists(function ($sub) {
            $sub->from('care_alliances')
                ->whereColumn('care_alliances.hub_organization_id', 'organizations.id')
                ->whereNotNull('care_alliances.hub_organization_id');
        });
    }

    /** Approved registration and operational status (for Care Alliance invite search, etc.). */
    public function scopeActiveRegistered($query)
    {
        return $query->where('registration_status', 'approved')
            ->where('status', 'Active');
    }

    /**
     * Approved registration + linked platform user (owner or board). Does not filter organizations.status.
     * Excludes Care Alliance hub orgs and any org owned by a Care Alliance creator (internal hub row; covers legacy rows without hub_organization_id).
     */
    public function scopeCareAllianceInviteEligible(Builder $query): Builder
    {
        $query->active()
            ->where(function ($q) {
                $q->whereNotNull('user_id')
                    ->orWhereHas('boardMembers', function ($sub) {
                        $sub->whereNotNull('user_id');
                    });
            })
            ->excludingCareAllianceHubs();

        if (Schema::hasTable('care_alliances') && Schema::hasColumn('care_alliances', 'creator_user_id')) {
            $query->whereNotExists(function ($sub) {
                $sub->from('care_alliances')
                    ->whereColumn('care_alliances.creator_user_id', 'organizations.user_id');
            });
        }

        return $query;
    }

    /**
     * Approved-registration orgs matching Care Alliance invite search (Inertia partial reload).
     * Does not filter on organizations.status (e.g. "Active").
     *
     * When {@see $careAllianceId} is set, excludes organizations that already have a pending invitation
     * or a pending/active membership with that alliance (so you cannot double-invite or re-pick current members).
     *
     * @return array<int, array{id: int, name: string, ein: string|null, email: string|null, city: string|null, state: string|null, primary_action_categories: array<int, array{id: int, name: string}>}>
     */
    public static function careAllianceSearchResults(string $q, ?int $careAllianceId = null, int $limit = 20): array
    {
        $term = trim($q);
        if (strlen($term) < 2) {
            return [];
        }

        $einDigits = preg_replace('/\D/', '', $term);

        $query = static::query()
            ->careAllianceInviteEligible()
            ->where(function ($w) use ($term, $einDigits) {
                $w->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('city', 'like', '%'.$term.'%')
                    ->orWhere('state', 'like', '%'.$term.'%');

                if ($einDigits !== '') {
                    $w->orWhere('ein', 'like', '%'.$einDigits.'%');
                }

                $w->orWhereHas('primaryActionCategories', function ($pac) use ($term) {
                    $pac->where(function ($p) use ($term) {
                        $p->where('name', 'like', '%'.$term.'%')
                            ->orWhere('slug', 'like', '%'.$term.'%');
                    });
                });
            });

        if ($careAllianceId !== null) {
            $query
                ->whereDoesntHave('careAllianceMemberships', function ($sub) use ($careAllianceId) {
                    $sub->where('care_alliance_id', $careAllianceId)
                        ->whereIn('status', ['pending', 'active']);
                })
                ->whereDoesntHave('careAllianceInvitations', function ($sub) use ($careAllianceId) {
                    $sub->where('care_alliance_id', $careAllianceId)
                        ->where('status', 'pending');
                });
        }

        return $query
            ->with(['primaryActionCategories' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')])
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'ein', 'email', 'city', 'state'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'ein' => $o->ein,
                'email' => $o->email,
                'city' => $o->city,
                'state' => $o->state,
                'primary_action_categories' => $o->primaryActionCategories->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    // Scope for search
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%")
                ->orWhere('description', 'LIKE', "%{$search}%")
                ->orWhere('mission', 'LIKE', "%{$search}%");
        });
    }

    // public function favoritedBy()
    // {
    //     return $this->belongsToMany(User::class, 'user_favorite_organizations');

    /**
     * Add funds to the organization's balance.
     */
    public function addFund(float $amount, string $method = 'raffle_sales', array $meta = []): void
    {
        $this->increment('balance', $amount);

        // Record transaction for the organization's user if it exists
        if ($this->user) {
            $this->user->recordTransaction([
                'type' => 'raffle_sale',
                'amount' => $amount,
                'payment_method' => $method,
                'meta' => array_merge($meta, ['organization_id' => $this->id]),
            ]);
        }
    }

    /**
     * Get the current balance of the organization.
     */
    public function currentBalance(): float
    {
        return (float) $this->balance;
    }

    public function logoUrl(): ?string
    {
        $candidates = [
            $this->registered_user_image,
        ];

        $user = $this->relationLoaded('user')
            ? $this->user
            : ($this->user_id ? $this->user()->first(['id', 'image', 'registered_user_image']) : null);

        if ($user) {
            $candidates[] = $user->image;
            $candidates[] = $user->registered_user_image;
        }

        foreach ($candidates as $path) {
            if (! filled($path)) {
                continue;
            }

            return url(Storage::url($path));
        }

        return null;
    }
    // }

    public function isFavoritedByUser(): HasOne
    {
        return $this->hasOne(UserFavoriteOrganization::class, 'organization_id');
    }

    public function donations()
    {
        return $this->hasMany(Donation::class, 'organization_id');
    }

    public function paymentSettings(): HasOne
    {
        return $this->hasOne(OrganizationPaymentSetting::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'organization_id');
    }

    public function organizationProducts()
    {
        return $this->hasMany(OrganizationProduct::class);
    }

    public function jobPosts()
    {
        return $this->hasMany(JobPost::class, 'organization_id', 'id');
    }

    public function complianceApplications()
    {
        return $this->hasMany(ComplianceApplication::class);
    }

    public function form1023Applications()
    {
        return $this->hasMany(Form1023Application::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'organization_id');
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'user_favorite_organizations', 'organization_id', 'user_id')
            ->withPivot('notifications') // notifications column include
            ->withTimestamps();
    }

    public function kioskProviders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(KioskProvider::class, 'organization_id');
    }

    /**
     * Verified users with the Supporters role ({@see User} `user` role) — newsletter “supporters” audience (not donation/follow-based).
     *
     * @return list<int>
     */
    public function newsletterRelatedAudienceUserIds(): array
    {
        return User::query()
            ->role('user')
            ->whereNotNull('email_verified_at')
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Verified supporters for picker UIs (e.g. advanced newsletter create).
     */
    public function newsletterRelatedAudienceUsers(): Collection
    {
        return User::query()
            ->role('user')
            ->whereNotNull('email_verified_at')
            ->with(['roles'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Newsletter create: counts only (cheap initial Inertia payload).
     *
     * @return array{followers: int, donors: int, volunteers: int, newsletter_contacts: int}
     */
    public function newsletterAudienceCounts(): array
    {
        $orgId = $this->id;

        $followersQ = User::query()
            ->whereHas('favoriteOrganizations', function ($q) use ($orgId) {
                $q->where('user_favorite_organizations.organization_id', $orgId);
            })
            ->whereNotNull('email_verified_at');

        $donorUserIds = Donation::query()
            ->where('organization_id', $orgId)
            ->whereIn('status', ['completed', 'active'])
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->merge(
                FundMeDonation::query()
                    ->where('organization_id', $orgId)
                    ->where('status', FundMeDonation::STATUS_SUCCEEDED)
                    ->whereNotNull('user_id')
                    ->pluck('user_id')
            )
            ->unique()
            ->values()
            ->all();

        $donorsQ = User::query()
            ->whereIn('id', $donorUserIds)
            ->whereNotNull('email_verified_at');

        $volunteersQ = User::query()
            ->whereHas('jobApplications', function ($q) use ($orgId) {
                $q->where('status', 'accepted')
                    ->whereHas('jobPost', function ($q2) use ($orgId) {
                        $q2->where('organization_id', $orgId)
                            ->where('type', 'volunteer');
                    });
            })
            ->whereNotNull('email_verified_at');

        $contactsQ = NewsletterRecipient::query()
            ->active()
            ->where(function ($q) use ($orgId) {
                $q->where('organization_id', $orgId)
                    ->orWhereNull('organization_id');
            });

        return [
            'followers' => (int) (clone $followersQ)->count(),
            'donors' => $donorUserIds === [] ? 0 : (int) (clone $donorsQ)->count(),
            'volunteers' => (int) (clone $volunteersQ)->count(),
            'newsletter_contacts' => (int) (clone $contactsQ)->count(),
        ];
    }

    /**
     * Full rows for one segment (loaded via Inertia partial visit when user selects a card).
     *
     * @return list<array<string, mixed>>
     */
    public function newsletterAudienceDetailForSegment(string $segment, int $limit = 500): array
    {
        $orgId = $this->id;
        $mapUser = static function (User $u): array {
            return [
                'id' => (int) $u->id,
                'kind' => 'user',
                'name' => (string) ($u->name ?? ''),
                'email' => (string) ($u->email ?? ''),
                'email_verified_at' => $u->email_verified_at?->toIso8601String(),
                'roles' => $u->relationLoaded('roles')
                    ? $u->roles->map(fn ($r) => ['name' => (string) $r->name])->values()->all()
                    : [],
            ];
        };

        return match ($segment) {
            'followers' => User::query()
                ->with('roles')
                ->whereHas('favoriteOrganizations', function ($q) use ($orgId) {
                    $q->where('user_favorite_organizations.organization_id', $orgId);
                })
                ->whereNotNull('email_verified_at')
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map($mapUser)
                ->values()
                ->all(),
            'donors' => $this->donorUsersQueryForOrg($orgId)
                ->with('roles')
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map($mapUser)
                ->values()
                ->all(),
            'volunteers' => User::query()
                ->with('roles')
                ->whereHas('jobApplications', function ($q) use ($orgId) {
                    $q->where('status', 'accepted')
                        ->whereHas('jobPost', function ($q2) use ($orgId) {
                            $q2->where('organization_id', $orgId)
                                ->where('type', 'volunteer');
                        });
                })
                ->whereNotNull('email_verified_at')
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map($mapUser)
                ->values()
                ->all(),
            'newsletter_contacts' => NewsletterRecipient::query()
                ->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)
                        ->orWhereNull('organization_id');
                })
                ->whereIn('status', ['active', 'unsubscribed'])
                ->orderByRaw('COALESCE(name, email)')
                ->limit($limit)
                ->get()
                ->map(static fn (NewsletterRecipient $r) => [
                    'id' => (int) $r->id,
                    'kind' => 'contact',
                    'name' => (string) ($r->name ?? ''),
                    'email' => (string) ($r->email ?? ''),
                    'status' => (string) $r->status,
                    'badge' => $r->status === 'active' ? 'Manual recipient' : 'Unsubscribed',
                ])
                ->values()
                ->all(),
            default => [],
        };
    }

    /**
     * Verified users who have donated to this org (wallet + FundMe).
     */
    protected function donorUsersQueryForOrg(int $organizationId): \Illuminate\Database\Eloquent\Builder
    {
        $donorUserIds = Donation::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['completed', 'active'])
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->merge(
                FundMeDonation::query()
                    ->where('organization_id', $organizationId)
                    ->where('status', FundMeDonation::STATUS_SUCCEEDED)
                    ->whereNotNull('user_id')
                    ->pluck('user_id')
            )
            ->unique()
            ->values()
            ->all();

        if ($donorUserIds === []) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query()
            ->whereIn('id', $donorUserIds)
            ->whereNotNull('email_verified_at');
    }

    public function emailConnections()
    {
        return $this->hasMany(EmailConnection::class);
    }

    public function emailContacts()
    {
        return $this->hasMany(EmailContact::class);
    }

    public function giftCards()
    {
        return $this->hasMany(GiftCard::class);
    }

    public function fundmeCampaigns()
    {
        return $this->hasMany(FundMeCampaign::class, 'organization_id');
    }

    public function fundmeDonations()
    {
        return $this->hasMany(FundMeDonation::class, 'organization_id');
    }

    /**
     * Barter network: listings this nonprofit offers.
     */
    public function barterListings()
    {
        return $this->hasMany(NonprofitBarterListing::class, 'nonprofit_id');
    }

    /**
     * Barter: transactions where this org is the requester (A).
     */
    public function barterTransactionsRequested()
    {
        return $this->hasMany(NonprofitBarterTransaction::class, 'requesting_nonprofit_id');
    }

    /**
     * Barter: transactions where this org is the responder (B).
     */
    public function barterTransactionsResponding()
    {
        return $this->hasMany(NonprofitBarterTransaction::class, 'responding_nonprofit_id');
    }

    /**
     * Get all livestreams for this organization.
     */
    public function livestreams()
    {
        return $this->hasMany(OrganizationLivestream::class);
    }

    /**
     * Get active/live livestreams for this organization.
     */
    public function activeLivestreams()
    {
        return $this->livestreams()->where('status', 'live');
    }

    public function aiVideos()
    {
        return $this->hasMany(AiVideo::class);
    }
}

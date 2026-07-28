<?php

namespace App\Models;

use App\Jobs\ProcessBelievePointsAutoReplenishJob;
use App\Jobs\SendPasswordResetEmailJob;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The guard name for Spatie Permission
     */
    protected $guard_name = 'web';

    /**
     * Get the guard name for Spatie Permission
     */
    public function getGuardName(): string
    {
        return $this->guard_name ?? 'web';
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'balance',
        'reward_points',
        'believe_points',
        'processing_believe_points',
        'gifted_believe_points',
        'holding_believe_points',
        'believe_points_auto_replenish_enabled',
        'believe_points_auto_replenish_threshold',
        'believe_points_auto_replenish_amount',
        'believe_points_auto_replenish_pm_id',
        'believe_points_auto_replenish_card_brand',
        'believe_points_auto_replenish_card_last4',
        'believe_points_auto_replenish_agreed_at',
        'believe_points_last_auto_replenish_at',
        'user_id',
        'slug',
        'email',
        'password',
        'registered_user_image',
        'livestream_overlay_settings',
        'image',
        'cover_img',
        'dob',
        'contact_number',
        'role',
        'organization_role',
        'whatsapp_opt_in',
        'push_token',
        'login_status',
        'referral_code',
        'referred_by',
        'is_verified',
        'email_verified_at',
        'two_fa_enabled',
        'biometric_enabled',
        'ownership_verified_at',
        'verification_status',
        'primary_bank_account_id',
        'plaid_access_token',
        'verification_metadata',
        'timezone',
        'favorites_onboarding_completed_at',
        'wallet_access_token',
        'wallet_encrypted_token',
        'wallet_user_id',
        'wallet_token_expires_at',
        'wallet_connected_at',
        'emails_included',
        'emails_used',
        'sms_included',
        'sms_used',
        'sms_auto_recharge_enabled',
        'sms_auto_recharge_threshold',
        'sms_auto_recharge_package_id',
        'sms_auto_recharge_pm_id',
        'sms_auto_recharge_card_brand',
        'sms_auto_recharge_card_last4',
        'sms_auto_recharge_agreed_at',
        'sms_last_auto_recharge_at',
        'newsletter_pro_targeting_purchased_at',
        'ai_tokens_included',
        'ai_tokens_used',
        'current_plan_details',
        'current_plan_id',
        'city',
        'state',
        'zipcode',
        'primary_organization_id',
        'primary_organization_locked',
        'account_visibility',
        'message_audience',
        'appearance_preference',
        'secondary_organization_ids',
        'religion',
        'volunteer_interest_statement',
        'youtube_channel_url',
        'youtube_access_token',
        'youtube_refresh_token',
        'youtube_token_expires_at',
        'dropbox_refresh_token',
        'dropbox_access_token',
        'dropbox_token_expires_at',
        'dropbox_folder_name',
        'account_visibility',
        'messaging_policy',
        'primary_organization_id',
        'secondary_organization_ids',
        'preferred_theme',
        'proximity_notifications_enabled',
        'last_latitude',
        'last_longitude',
        'last_location_reported_at',
        'auto_share_youtube_imports_to_feed',
        'enrollment_notifications_via',
        'enrollment_reminders_via',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'youtube_access_token',
        'youtube_refresh_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'favorites_onboarding_completed_at' => 'datetime',
            'ownership_verified_at' => 'datetime',
            'verification_metadata' => 'array',
            'livestream_overlay_settings' => 'array',
            'secondary_organization_ids' => 'array',
            'password' => 'hashed',
            'wallet_token_expires_at' => 'datetime',
            'wallet_connected_at' => 'datetime',
            'current_plan_details' => 'array',
            'emails_included' => 'integer',
            'emails_used' => 'integer',
            'sms_included' => 'integer',
            'sms_used' => 'integer',
            'sms_auto_recharge_enabled' => 'boolean',
            'sms_auto_recharge_threshold' => 'integer',
            'sms_auto_recharge_package_id' => 'integer',
            'sms_auto_recharge_agreed_at' => 'datetime',
            'sms_last_auto_recharge_at' => 'datetime',
            'newsletter_pro_targeting_purchased_at' => 'datetime',
            'ai_tokens_included' => 'integer',
            'ai_tokens_used' => 'integer',
            'ai_media_studio_credits' => 'decimal:2',
            'believe_points' => 'decimal:2',
            'processing_believe_points' => 'decimal:2',
            'gifted_believe_points' => 'decimal:2',
            'holding_believe_points' => 'decimal:2',
            'believe_points_auto_replenish_enabled' => 'boolean',
            'believe_points_auto_replenish_threshold' => 'decimal:2',
            'believe_points_auto_replenish_amount' => 'decimal:2',
            'believe_points_auto_replenish_agreed_at' => 'datetime',
            'believe_points_last_auto_replenish_at' => 'datetime',
            'youtube_token_expires_at' => 'datetime',
            'dropbox_token_expires_at' => 'datetime',
            'secondary_organization_ids' => 'array',
            'auto_share_youtube_imports_to_feed' => 'boolean',
            'proximity_notifications_enabled' => 'boolean',
            'primary_organization_locked' => 'boolean',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
            'last_location_reported_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        // Set default guard for Spatie Permission
        static::creating(function ($user) {
            if (empty($user->referral_code)) {
                do {
                    $code = substr(bin2hex(random_bytes(8)), 0, 12);
                } while (self::where('referral_code', $code)->exists());
                $user->referral_code = $code;
            }

            // Auto-generate slug if not provided
            if (empty($user->slug) && ! empty($user->name)) {
                $baseSlug = Str::slug($user->name);
                $slug = $baseSlug;
                $counter = 1;

                while (self::where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$counter;
                    $counter++;
                }

                $user->slug = $slug;
            }

            if ($user->proximity_notifications_enabled === null) {
                $user->proximity_notifications_enabled = true;
            }

            // Ensure guard_name is set
            if (empty($user->guard_name)) {
                $user->guard_name = 'web';
            }
        });

        // Set guard_name for existing models when retrieved
        static::retrieved(function ($user) {
            if (empty($user->guard_name)) {
                $user->guard_name = 'web';
            }
        });
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.'.$this->id;
    }

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function nodeReferrals()
    {
        return $this->hasMany(NodeReferral::class);
    }

    /**
     * Get the URL to the user's profile photo.
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (! $this->image) {
            return $this->defaultProfilePhotoUrl();
        }

        return Storage::disk('public')->url($this->image);
    }

    /**
     * Get the default profile photo URL if no profile photo has been uploaded.
     */
    protected function defaultProfilePhotoUrl(): string
    {
        $name = trim(collect(explode(' ', $this->name))->map(function ($segment) {
            return mb_substr($segment, 0, 1);
        })->join(' '));

        return 'https://ui-avatars.com/api/?name='.urlencode($name).'&color=7F9CF5&background=EBF4FF';
    }

    /**
     * Delete the user's profile photo.
     */
    public function deleteProfilePhoto(): void
    {
        if ($this->image) {
            Storage::disk('public')->delete($this->image);
            $this->forceFill([
                'image' => null,
            ])->save();
        }
    }

    /**
     * Update the user's profile photo.
     */
    public function updateProfilePhoto($photoPath, $disk = 'public'): void
    {
        // Delete old photo if exists
        $this->deleteProfilePhoto();

        // Store new photo path
        $this->forceFill([
            'image' => $photoPath,
        ])->save();
    }

    public function favoriteOrganizations()
    {
        return $this->belongsToMany(Organization::class, 'user_favorite_organizations')
            ->withTimestamps()->with(['user', 'nteeCode']);
    }

    public function aiVideos(): HasMany
    {
        return $this->hasMany(AiVideo::class);
    }

    /**
     * Primary organization affiliation selected by supporter.
     */
    public function primaryOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'primary_organization_id');
    }

    public function savedNewsArticles(): BelongsToMany
    {
        return $this->belongsToMany(NonprofitNewsArticle::class, 'user_saved_nonprofit_news', 'user_id', 'nonprofit_news_article_id')
            ->withTimestamps();
    }

    public function jobApplications()
    {
        return $this->hasMany(JobApplication::class);
    }

    public function hasAppliedToJob($jobId)
    {
        return $this->jobApplications()->where('job_post_id', $jobId)->exists();
    }

    public function getApplicationId($jobId)
    {
        $application = $this->jobApplications()
            ->where('job_post_id', $jobId)
            ->first();

        return $application ? $application->id : null;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Record a transaction for the user.
     */
    public function recordTransaction(array $data): Transaction
    {
        // Merge default values, allowing overrides
        $defaultData = [
            'user_id' => $this->id,
            'status' => 'completed', // Default status, can be overridden
            'currency' => 'USD',
            'related_id' => null,
            'related_type' => null,
            'processed_at' => now(), // Default processed_at
        ];

        return $this->transactions()->create(array_merge($defaultData, $data));
    }

    /**
     * Add funds to the user's balance and record a deposit transaction.
     */
    public function addFund(float $amount, string $method = 'wallet', array $meta = []): void
    {
        $this->increment('balance', $amount);
        $this->recordTransaction([
            'type' => 'deposit',
            'amount' => $amount,
            'payment_method' => $method,
            'meta' => $meta,
        ]);
    }

    /**
     * Withdraw funds from the user's balance and record a withdrawal transaction.
     */
    public function withdrawFund(
        float $amount,
        string $method = 'wallet',
        array $meta = [],
        ?int $relatedId = null,
        ?string $relatedType = null,
        string $status = 'pending' // Default to pending for withdrawals
    ): bool {
        if ($this->balance < $amount) {
            return false;
        }
        $this->decrement('balance', $amount);
        $this->recordTransaction([
            'type' => 'withdrawal',
            'status' => $status, // Use the provided status
            'amount' => $amount,
            'payment_method' => $method,
            'meta' => $meta,
            'related_id' => $relatedId,
            'related_type' => $relatedType,
            'processed_at' => null, // Set to null initially for pending withdrawals
        ]);

        return true;
    }

    /**
     * Alias for addFund.
     */
    public function depositFund(float $amount, string $method = 'wallet', array $meta = []): void
    {
        $this->addFund($amount, $method, $meta);
    }

    /**
     * Refund funds to the user's balance and record a refund transaction.
     */
    public function refund(float $amount, string $method = 'wallet', array $meta = []): void
    {
        $this->increment('balance', $amount);
        $this->recordTransaction([
            'type' => 'refund',
            'amount' => $amount,
            'payment_method' => $method,
            'meta' => $meta,
        ]);
    }

    /**
     * Add commission to the user's balance and record a commission transaction.
     */
    public function commissionAdd(float $amount, array $meta = []): void
    {
        $this->increment('balance', $amount);
        $this->recordTransaction([
            'type' => 'commission',
            'amount' => $amount,
            'payment_method' => 'system',
            'meta' => $meta,
        ]);
    }

    /**
     * Get the current balance of the user.
     */
    public function currentBalance(): float
    {
        return (float) $this->balance;
    }

    // Add these relationships to your existing User model
    public function chatRooms(): BelongsToMany
    {
        return $this->belongsToMany(ChatRoom::class, 'chat_room_members')
            ->withPivot(['role', 'joined_at', 'last_seen_at'])
            ->withTimestamps();
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    // public function organization(): HasOne
    // {
    //     return $this->hasOne(Organization::class, 'user_id');
    // }

    public function organization()
    {
        // Use hasOneThrough relationship but fix ambiguous column issue.
        // Only include youtube columns if they exist (migrations may not be run yet).
        $base = 'organizations.id, organizations.name, organizations.user_id, organizations.ein, organizations.description, organizations.mission, organizations.website, organizations.email, organizations.phone, organizations.contact_name, organizations.contact_title, organizations.city, organizations.state, organizations.zip, organizations.registration_status, organizations.created_at, organizations.updated_at';
        $youtube = Schema::hasColumn('organizations', 'youtube_channel_url') ? ', organizations.youtube_channel_url' : '';
        $youtubeOAuth = '';
        if (Schema::hasColumn('organizations', 'youtube_access_token')) {
            $youtubeOAuth .= ', organizations.youtube_access_token, organizations.youtube_refresh_token, organizations.youtube_token_expires_at';
        }
        $dropboxOAuth = '';
        if (Schema::hasColumn('organizations', 'dropbox_refresh_token')) {
            $dropboxOAuth .= ', organizations.dropbox_refresh_token, organizations.dropbox_access_token, organizations.dropbox_token_expires_at, organizations.dropbox_folder_name';
        }
        $select = $base.$youtube.$youtubeOAuth.$dropboxOAuth.', board_members.user_id as laravel_through_key';

        return $this->hasOneThrough(
            Organization::class,
            BoardMember::class,
            'user_id', // Foreign key on board_members table
            'id', // Foreign key on organizations table
            'id', // Local key on users table
            'organization_id' // Local key on board_members table
        )->selectRaw($select);
    }

    public function isOrganizationAdmin()
    {
        return $this->organization_role === 'admin' && $this->organization;
    }

    public function isOrganizationLeader()
    {
        return $this->organization_role === 'leader' && $this->organization;
    }

    public function boardMemberships()
    {
        return $this->hasMany(BoardMember::class);
    }

    /**
     * Organizations this user is associated with via board membership (newsletter targeting: "users in orgs").
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'board_members', 'user_id', 'organization_id')
            ->withTimestamps();
    }

    public function canManageContent()
    {
        return $this->isOrganizationAdmin() || $this->isOrganizationLeader();
    }

    public function contentItems()
    {
        return $this->hasMany(ContentItem::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function createdCareAlliances(): HasMany
    {
        return $this->hasMany(CareAlliance::class, 'creator_user_id');
    }

    /**
     * Care Alliance creators need a 9-digit EIN on their alliance record to use wallet / Bridge flows.
     * Everyone else is unrestricted by this rule.
     */
    public function careAllianceWalletEligible(): bool
    {
        if (! $this->hasRole('care_alliance')) {
            return true;
        }

        $alliance = CareAlliance::query()->where('creator_user_id', $this->id)->first();
        if (! $alliance) {
            return true;
        }

        $digits = $alliance->ein ? preg_replace('/\D/', '', $alliance->ein) : '';

        return strlen($digits) === 9;
    }

    /**
     * Whether the app header (navbar + dashboard sidebar) should show the wallet control.
     * Care Alliance hubs need a 9-digit EIN on the alliance row; organizations need a 9-digit org EIN.
     */
    public function walletHeaderVisible(): bool
    {
        if ($this->hasRole('care_alliance')) {
            $alliance = CareAlliance::query()->where('creator_user_id', $this->id)->first();
            if (! $alliance) {
                return false;
            }
            $digits = $alliance->ein ? preg_replace('/\D/', '', (string) $alliance->ein) : '';

            return strlen($digits) === 9;
        }

        if ($this->hasRole('organization') || $this->hasRole('organization_pending')) {
            $org = Organization::forAuthUser($this);
            if (! $org) {
                return false;
            }
            $digits = $org->ein ? preg_replace('/\D/', '', (string) $org->ein) : '';

            return strlen($digits) === 9;
        }

        return true;
    }

    /**
     * Nonprofit-side dashboard users: approved org, Form 1023 pending, or Care Alliance hub.
     * Uses Spatie roles and falls back to the legacy {@see $fillable} `role` column when out of sync.
     */
    public function hasNonprofitDashboardRole(): bool
    {
        if ($this->hasAnyRole(['organization', 'organization_pending', 'care_alliance'])) {
            return true;
        }

        return in_array((string) $this->role, ['organization', 'organization_pending', 'care_alliance'], true);
    }

    /**
     * Whether this account may follow nonprofits (Explore by Cause, favorites, etc.).
     * Organization / Care Alliance / admin accounts cannot follow other organizations.
     */
    public function canFollowOrganizations(): bool
    {
        if ($this->hasRole('admin') || (string) $this->role === 'admin') {
            return false;
        }

        if ($this->hasNonprofitDashboardRole()) {
            return false;
        }

        return $this->hasRole('user') || (string) $this->role === 'user';
    }

    public function sendJobs()
    {
        return $this->hasMany(SendJob::class);
    }

    /**
     * Get the Bridge integration for this user
     */
    public function bridgeIntegration(): HasOne
    {
        return $this->hasOne(BridgeIntegration::class, 'integratable_id')
            ->where('integratable_type', self::class);
    }

    // Notification preferences
    public function enrollmentNotificationsVia(): string
    {
        return \App\Support\EnrollmentNotificationVia::normalize(
            $this->enrollment_notifications_via,
            \App\Support\EnrollmentNotificationVia::PUSH_EMAIL
        );
    }

    public function enrollmentRemindersVia(): string
    {
        return \App\Support\EnrollmentNotificationVia::normalize(
            $this->enrollment_reminders_via,
            \App\Support\EnrollmentNotificationVia::PUSH
        );
    }

    public function wantsEnrollmentPush(string $context = 'enrollments'): bool
    {
        $via = $context === 'reminders'
            ? $this->enrollmentRemindersVia()
            : $this->enrollmentNotificationsVia();

        return in_array($via, [\App\Support\EnrollmentNotificationVia::PUSH, \App\Support\EnrollmentNotificationVia::PUSH_EMAIL], true);
    }

    public function wantsEnrollmentEmail(string $context = 'enrollments'): bool
    {
        $via = $context === 'reminders'
            ? $this->enrollmentRemindersVia()
            : $this->enrollmentNotificationsVia();

        return in_array($via, [\App\Support\EnrollmentNotificationVia::EMAIL, \App\Support\EnrollmentNotificationVia::PUSH_EMAIL], true);
    }

    public function hasActivePushDevice(): bool
    {
        return $this->pushTokens()
            ->where('is_active', true)
            ->where('status', UserPushToken::STATUS_ACTIVE)
            ->exists();
    }

    public function shouldReceivePush()
    {
        return $this->login_status && $this->hasActivePushDevice();
    }

    public function shouldReceiveWhatsApp()
    {
        return $this->whatsapp_opt_in && ! empty($this->contact_number);
    }

    public function getLocalTimezone()
    {
        return $this->timezone ?? 'America/Chicago';
    }

    /**
     * Route notifications for Twilio channel (WhatsApp)
     */
    public function routeNotificationForTwilio()
    {
        return $this->contact_number ? 'whatsapp:'.$this->contact_number : null;
    }

    // public function organizations()
    // {
    //     return $this->hasManyThrough(Organization::class, BoardMember::class, 'user_id', 'id', 'id', 'organization_id');
    // }

    public function getAvatarUrlAttribute(): string
    {
        return $this->image ? asset('storage/'.$this->image) : 'https://ui-avatars.com/api/?name='.urlencode($this->name).'&background=random';
    }

    public function getIsOnlineAttribute(): bool
    {
        return $this->login_status == 1;
    }

    /**
     * Get the courses created by this user (as organization).
     */
    public function createdCourses()
    {
        return $this->hasMany(Course::class, 'organization_id');
    }

    /**
     * Get the courses instructed by this user.
     */
    public function instructedCourses()
    {
        return $this->hasMany(Course::class, 'user_id');
    }

    /**
     * Get the enrollments for this user.
     */
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Get the courses this user is enrolled in.
     */
    public function enrolledCourses()
    {
        return $this->belongsToMany(Course::class, 'enrollments')
            ->withPivot(['status', 'amount_paid', 'enrolled_at'])
            ->withTimestamps();
    }

    /**
     * Check if user is enrolled in a specific course
     */
    public function isEnrolledIn(Course $course)
    {
        return $this->enrollments()
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();
    }

    // public function organizations(): HasMany
    // {
    //     return $this->hasMany(Organization::class);
    // }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * Livestreams created by this user (supporter meetings — VDO.Ninja).
     */
    public function userLivestreams(): HasMany
    {
        return $this->hasMany(UserLivestream::class);
    }

    public function bankVerifications(): HasMany
    {
        return $this->hasMany(BankVerification::class);
    }

    public function primaryBankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class, 'id', 'primary_bank_account_id');
    }

    public function nonprofitVerifications(): HasMany
    {
        return $this->hasMany(NonprofitVerification::class);
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(NonprofitVerification::class)->latestOfMany();
    }

    public function isOwnershipVerified(): bool
    {
        return $this->latestVerification && $this->latestVerification->verification_status === 'verified';
    }

    public function hasVerifiedBankAccount(): bool
    {
        return $this->bankAccounts()->where('is_verified', true)->exists();
    }

    public function getVerificationStatus(): string
    {
        $verification = $this->latestVerification;

        return $verification ? $verification->verification_status : 'not_started';
    }

    public function interestedTopics()
    {
        return $this->belongsToMany(ChatTopic::class, 'user_interested_topics', 'user_id', 'topic_id')
            ->withTimestamps();
    }

    /**
     * Get the social media accounts for this user.
     */
    public function socialMediaAccounts(): HasMany
    {
        return $this->hasMany(SocialMediaAccount::class);
    }

    /**
     * Get the social media posts for this user.
     */
    public function socialMediaPosts(): HasMany
    {
        return $this->hasMany(SocialMediaPost::class);
    }

    /**
     * Get the user's notifications with pagination
     */
    public function notifications()
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get the user's unread notifications
     */
    public function unreadNotifications()
    {
        return $this->notifications()->whereNull('read_at');
    }

    public function pushTokens()
    {
        return $this->hasMany(UserPushToken::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * Get the user's current plan
     */
    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'current_plan_id');
    }

    public function supporterPositions()
    {
        // If using pivot table
        return $this->belongsToMany(
            SupporterPosition::class,
            'supporter_user_positions', // pivot table name
            'user_id',
            'follower_position_id'
        )->withTimestamps();
    }

    /**
     * Primary action categories this supporter is interested in (profile: Supporters Interest).
     */
    public function supporterInterestCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            PrimaryActionCategory::class,
            'primary_action_category_user',
            'user_id',
            'primary_action_category_id'
        )->withTimestamps();
    }

    /**
     * Cause IDs that should materialize public group chats for this account.
     * Supporters: profile Supporters Interest. Organizations: Causes & Interest on the org profile.
     *
     * @return int[]
     */
    public function causeInterestCategoryIds(): array
    {
        $pacKey = (new PrimaryActionCategory)->getQualifiedKeyName();

        if ($this->role === 'user') {
            return $this->supporterInterestCategories()
                ->where('is_active', true)
                ->pluck($pacKey)
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if (in_array($this->role, ['organization', 'organization_pending', 'care_alliance'], true)) {
            $org = Organization::forAuthUser($this);
            if (! $org) {
                return [];
            }

            return $org->primaryActionCategories()
                ->where('is_active', true)
                ->pluck($pacKey)
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return [];
    }

    /**
     * Job positions (volunteer role types) this supporter saved on /volunteer-opportunities.
     */
    public function volunteerPreferredJobPositions(): BelongsToMany
    {
        return $this->belongsToMany(
            JobPosition::class,
            'user_volunteer_job_positions',
            'user_id',
            'job_position_id'
        )->withTimestamps();
    }

    /**
     * Get the gigs created by this user
     */
    public function gigs(): HasMany
    {
        return $this->hasMany(Gig::class);
    }

    public function interestCategories(): BelongsToMany
    {
        return $this->belongsToMany(InterestCategory::class, 'interest_category_user')
            ->withTimestamps();
    }

    /**
     * Get the favorite gigs for this user
     */
    public function favoriteGigs(): BelongsToMany
    {
        return $this->belongsToMany(Gig::class, 'gig_favorites')
            ->withTimestamps();
    }

    /**
     * Get service orders where user is buyer
     */
    public function serviceOrdersAsBuyer(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'buyer_id');
    }

    /**
     * Get service orders where user is seller
     */
    public function serviceOrdersAsSeller(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'seller_id');
    }

    /**
     * Get the service seller profile for this user.
     */
    public function serviceSellerProfile(): HasOne
    {
        return $this->hasOne(ServiceSellerProfile::class);
    }

    /**
     * Get believe point purchases for this user.
     */
    public function believePointPurchases(): HasMany
    {
        return $this->hasMany(BelievePointPurchase::class);
    }

    /**
     * Add believe points to the user's balance.
     */
    public function addBelievePoints(float $points): void
    {
        $this->increment('believe_points', $points);
    }

    public function addProcessingBelievePoints(float $points): void
    {
        $points = round(max(0, (float) $points), 2);
        if ($points <= 0) {
            return;
        }
        $this->increment('processing_believe_points', $points);
    }

    public function deductProcessingBelievePoints(float $points): bool
    {
        $points = round(max(0, (float) $points), 2);
        if ($points <= 0) {
            return true;
        }
        $this->refresh();
        if ((float) ($this->processing_believe_points ?? 0) < $points - 0.000001) {
            return false;
        }
        $this->decrement('processing_believe_points', $points);

        return true;
    }

    public function releaseProcessingBelievePoints(float $points): bool
    {
        $points = round(max(0, (float) $points), 2);
        if ($points <= 0) {
            return true;
        }
        $this->refresh();
        if ((float) ($this->processing_believe_points ?? 0) < $points - 0.000001) {
            return false;
        }
        $this->decrement('processing_believe_points', $points);
        $this->increment('believe_points', $points);

        return true;
    }

    public function currentProcessingBelievePoints(): float
    {
        return round((float) ($this->processing_believe_points ?? 0), 2);
    }

    /**
     * Earliest datetime when held Processing BP becomes spendable (card security review).
     */
    public function nextProcessingBelievePointsReleaseAt(): ?\Illuminate\Support\Carbon
    {
        $batches = $this->processingBelievePointsBatches();
        $first = $batches[0] ?? null;
        if ($first === null || empty($first['available_on'])) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($first['available_on']);
    }

    /**
     * Open Processing BP lots grouped by expected Available On date (earliest first).
     *
     * @return list<array{amount: float, available_on: string|null}>
     */
    public function processingBelievePointsBatches(): array
    {
        $lots = BelievePointProcessingLot::query()
            ->with(['purchase:id,points_available_at,stripe_funds_available_at,points_released,status'])
            ->where('user_id', $this->id)
            ->whereNull('released_at')
            ->where('amount', '>', 0)
            ->orderBy('id')
            ->get();

        $byDate = [];

        if ($lots->isNotEmpty()) {
            foreach ($lots as $lot) {
                $purchase = $lot->purchase;
                if ($purchase && ($purchase->points_released || $purchase->status !== 'completed')) {
                    continue;
                }
                $at = $purchase?->points_available_at
                    ?? $purchase?->stripe_funds_available_at
                    ?? null;
                $key = $at ? $at->format('Y-m-d H:i') : '_unknown';
                if (! isset($byDate[$key])) {
                    $byDate[$key] = [
                        'amount' => 0.0,
                        'available_on' => $at?->toIso8601String(),
                        'sort' => $at?->timestamp ?? PHP_INT_MAX,
                    ];
                }
                $byDate[$key]['amount'] = round($byDate[$key]['amount'] + (float) $lot->amount, 2);
            }
        } else {
            // Fallback when lots were never created for older purchases.
            $purchases = BelievePointPurchase::query()
                ->where('user_id', $this->id)
                ->where('status', 'completed')
                ->where('points_released', false)
                ->where('points', '>', 0)
                ->orderBy('points_available_at')
                ->get(['id', 'points', 'points_available_at', 'stripe_funds_available_at']);

            foreach ($purchases as $purchase) {
                $at = $purchase->points_available_at ?? $purchase->stripe_funds_available_at;
                $key = $at ? $at->format('Y-m-d H:i') : '_unknown';
                if (! isset($byDate[$key])) {
                    $byDate[$key] = [
                        'amount' => 0.0,
                        'available_on' => $at?->toIso8601String(),
                        'sort' => $at?->timestamp ?? PHP_INT_MAX,
                    ];
                }
                $byDate[$key]['amount'] = round($byDate[$key]['amount'] + (float) $purchase->points, 2);
            }
        }

        $batches = array_values($byDate);
        usort($batches, static fn (array $a, array $b): int => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        return array_map(static fn (array $row): array => [
            'amount' => round((float) $row['amount'], 2),
            'available_on' => $row['available_on'],
        ], $batches);
    }

    /**
     * Deduct purchased Believe Points from Available for non–gift-card BIU spends
     * (marketplace, events, merchants, credits, re-gifting, Bridge, etc.).
     *
     * Gift BP reporting is included in Available but may only be spent via the Gift Card module.
     * Purchased BP = Available − Gift reporting.
     *
     * @return bool Returns true if deduction was successful, false if insufficient purchased BP
     */
    public function deductBelievePoints(float $points): bool
    {
        $points = round(max(0, (float) $points), 2);
        if ($points <= 0) {
            return true;
        }

        $this->refresh();
        if ($this->purchasedBelievePointsBalance() + 0.000001 < $points) {
            return false;
        }

        $this->decrement('believe_points', $points);
        // Gift reporting unchanged when spending purchased BP.
        $this->clampGiftBelievePointsReporting();

        ProcessBelievePointsAutoReplenishJob::dispatch($this->id)->afterResponse();

        return true;
    }

    /**
     * Settled Available Believe Points (spendable). Gift BP is reporting-only and is
     * always a subset of this balance (never additional BP).
     */
    public function totalBelievePointsBalance(): float
    {
        return round((float) ($this->believe_points ?? 0), 2);
    }

    /**
     * Ensure Gift BP reporting never exceeds Available BP.
     */
    public function clampGiftBelievePointsReporting(): void
    {
        $available = round((float) ($this->believe_points ?? 0), 2);
        $gifted = round((float) ($this->gifted_believe_points ?? 0), 2);

        if ($gifted <= $available + 0.000001) {
            return;
        }

        $this->forceFill([
            'gifted_believe_points' => max(0, $available),
        ])->saveQuietly();
    }

    /**
     * Credit a received gift: Available BP increases, and Gift BP reporting increases by the same amount.
     * Gift BP is not a separate spendable wallet.
     */
    public function addGiftedBelievePoints(float $points): void
    {
        $points = round(max(0, (float) $points), 2);
        if ($points <= 0) {
            return;
        }
        $this->increment('believe_points', $points);
        $this->increment('gifted_believe_points', $points);
    }

    /**
     * Purchased portion of Available BP (Available − Gift reporting).
     * Usable for every BIU spend except the Gift Card module (that uses full Available).
     */
    public function purchasedBelievePointsBalance(): float
    {
        $available = round((float) ($this->believe_points ?? 0), 2);
        $gifted = round((float) ($this->gifted_believe_points ?? 0), 2);

        return round(max(0, $available - min($gifted, $available)), 2);
    }

    /**
     * Deduct BP for the Gift Card module.
     *
     * Closed-loop: full Available (Purchased + Gift); Gift reporting decreases by min(Gift, amount).
     * Open-loop Visa/MC: not sold with BP — pay with BIU Wallet (this path should not run for open-loop).
     *
     * @return array{from_gifted: float}|null Null if insufficient eligible BP.
     */
    public function deductAvailableBelievePointsForGiftCard(float $amount, bool $isClosedLoop = true): ?array
    {
        $amount = round(max(0, (float) $amount), 2);
        if ($amount <= 0) {
            return ['from_gifted' => 0.0];
        }

        $this->refresh();
        $available = round((float) ($this->believe_points ?? 0), 2);
        $gifted = round((float) ($this->gifted_believe_points ?? 0), 2);
        $purchased = round(max(0, $available - min($gifted, $available)), 2);

        if ($isClosedLoop) {
            if ($available < $amount - 0.000001) {
                return null;
            }

            $fromGifted = round(min($gifted, $amount), 2);
            if ($fromGifted > 0) {
                $this->decrement('gifted_believe_points', $fromGifted);
            }
        } else {
            // Visa/MC: cannot spend Gift BP — purchased portion only.
            if ($purchased < $amount - 0.000001) {
                return null;
            }
            $fromGifted = 0.0;
        }

        $this->decrement('believe_points', $amount);
        $this->clampGiftBelievePointsReporting();
        ProcessBelievePointsAutoReplenishJob::dispatch($this->id)->afterResponse();

        return ['from_gifted' => $fromGifted];
    }

    /**
     * Refund Available BP deducted for a pending or failed delayed gift card redemption.
     * Optionally restore Gift BP reporting that was reduced on the original closed-loop purchase.
     */
    public function refundAvailableBelievePointsForGiftCard(float $amount, float $restoreGiftReporting = 0): void
    {
        $amount = round(max(0, (float) $amount), 2);
        $restoreGiftReporting = round(max(0, (float) $restoreGiftReporting), 2);
        if ($amount <= 0 && $restoreGiftReporting <= 0) {
            return;
        }

        if ($amount > 0) {
            $this->increment('believe_points', $amount);
        }
        if ($restoreGiftReporting > 0) {
            $this->increment('gifted_believe_points', $restoreGiftReporting);
        }
        $this->clampGiftBelievePointsReporting();
    }

    /**
     * Deduct Believe Points for the Gift Card module.
     * Closed-loop: full Available. Visa/MC ($isClosedLoop=false): purchased only.
     *
     * @return array{from_gifted: float, from_purchased: float}|null Null if insufficient eligible BP.
     */
    public function deductBelievePointsForGiftCard(float $amount, bool $isClosedLoop = true): ?array
    {
        $amount = round(max(0, (float) $amount), 2);
        if ($amount <= 0) {
            return ['from_gifted' => 0.0, 'from_purchased' => 0.0];
        }

        $result = $this->deductAvailableBelievePointsForGiftCard($amount, $isClosedLoop);
        if ($result === null) {
            return null;
        }

        $fromGifted = $result['from_gifted'];

        return [
            'from_gifted' => $fromGifted,
            'from_purchased' => round(max(0, $amount - $fromGifted), 2),
        ];
    }

    /**
     * Refund a gift-card Believe Points payment back to Available BP.
     * Restores Gift BP reporting for the gifted portion when provided.
     */
    public function refundBelievePointsGiftCardBuckets(float $fromGifted, float $fromPurchased): void
    {
        $fromGifted = round(max(0, (float) $fromGifted), 2);
        $fromPurchased = round(max(0, (float) $fromPurchased), 2);
        $total = round($fromGifted + $fromPurchased, 2);
        if ($total <= 0) {
            return;
        }

        $this->increment('believe_points', $total);
        if ($fromGifted > 0) {
            $this->increment('gifted_believe_points', $fromGifted);
        }
        $this->clampGiftBelievePointsReporting();
    }

    /**
     * Get the current believe points balance of the user.
     */
    public function currentBelievePoints(): float
    {
        return (float) ($this->believe_points ?? 0);
    }

    /**
     * Add reward points to the user's balance and create a ledger entry.
     *
     * @param  string  $source  (e.g., 'nonprofit_assessment')
     * @param  int|float  $points  Supports fractional reward points (e.g. 0.10 per $1 USD).
     * @param  int|null  $referenceId  (e.g., assessment_id)
     */
    public function addRewardPoints(
        int|float $points,
        string $source,
        ?int $referenceId = null,
        ?string $description = null,
        ?array $metadata = null
    ): void {
        $points = round((float) $points, 2);
        if ($points <= 0) {
            return;
        }

        $this->increment('reward_points', $points);

        RewardPointLedger::createCredit(
            $this->id,
            $source,
            $referenceId,
            $points,
            $description,
            $metadata
        );
    }

    /**
     * Deduct reward points from the user's balance and create a ledger entry.
     *
     * @param  bool  $allowNegativeBalance  When true (e.g. quiz wrong answers), deduct full amount even if balance goes below zero.
     * @return bool Returns true if deduction succeeded; without {@see $allowNegativeBalance}, false means insufficient balance.
     */
    public function deductRewardPoints(
        int|float $points,
        string $source,
        ?int $referenceId = null,
        ?string $description = null,
        ?array $metadata = null,
        bool $allowNegativeBalance = false
    ): bool {
        $points = round((float) $points, 2);
        if ($points <= 0) {
            return true;
        }

        if (! $allowNegativeBalance) {
            $balance = $this->availableRewardPointsBalance();
            if ($balance < $points) {
                return false;
            }
        }

        $this->decrement('reward_points', $points);

        RewardPointLedger::createDebit(
            $this->id,
            $source,
            $referenceId,
            $points,
            $description,
            $metadata
        );

        return true;
    }

    /**
     * Get the current reward points balance of the user.
     */
    public function currentRewardPoints(): float
    {
        return round((float) ($this->reward_points ?? 0), 2);
    }

    /**
     * Reward points tied to Believe Point purchases still in the processing/settlement window.
     */
    public function processingRewardPointsBalance(): float
    {
        return round((float) BelievePointPurchase::query()
            ->where('user_id', $this->id)
            ->where('status', 'completed')
            ->where('points_released', false)
            ->where('reward_points_awarded', '>', 0)
            ->sum('reward_points_awarded'), 2);
    }

    /**
     * Settled reward points (excludes purchase-linked points still processing).
     */
    public function availableRewardPointsBalance(): float
    {
        return round(max(0, $this->currentRewardPoints() - $this->processingRewardPointsBalance()), 2);
    }

    /**
     * Total reward points (available + processing).
     */
    public function totalRewardPointsBalance(): float
    {
        return round($this->availableRewardPointsBalance() + $this->processingRewardPointsBalance(), 2);
    }

    /**
     * Send the email verification notification.
     *
     * @param  string|null  $domain  The domain from the request context (where user is accessing from)
     * @return void
     */
    /**
     * Send password-reset email immediately after the HTTP response (via job).
     * SMTP failures are re-queued on the mail queue for retry.
     */
    public function sendPasswordResetNotification($token): void
    {
        $domain = null;
        if (request()) {
            $scheme = request()->getScheme();
            $host = request()->getHost();
            $port = request()->getPort();
            $domain = $scheme.'://'.$host.($port && $port != 80 && $port != 443 ? ':'.$port : '');
        }

        // Send right after the HTTP response (no queue worker wait). Job re-queues on SMTP failure.
        SendPasswordResetEmailJob::dispatchAfterResponse($this->id, $token, $domain);
    }

    public function sendEmailVerificationNotification(?string $domain = null)
    {
        // Get domain from request if not provided
        if (! $domain && request()) {
            // Use actual request host, not config value
            $scheme = request()->getScheme();
            $host = request()->getHost();
            $port = request()->getPort();
            $domain = $scheme.'://'.$host.($port && $port != 80 && $port != 443 ? ':'.$port : '');
        }

        $this->notify(new VerifyEmailNotification($domain));
    }
}

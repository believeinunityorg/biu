<?php

namespace App\Models;

use App\Enums\GiftCardStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCard extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'external_id',
        'voucher',
        'card_number',
        'amount',
        'platform_fee',
        'platform_fee_biu_share',
        'platform_fee_org_share',
        'commission_percentage',
        'total_commission',
        'platform_commission',
        'nonprofit_commission',
        'merchant_revenue',
        'brand',
        'brand_name',
        'country',
        'currency',
        'is_sent',
        'status',
        'payment_method',
        'stripe_payment_intent_id',
        'stripe_session_id',
        'phaze_disbursement_id',
        'purchased_at',
        'requested_at',
        'scheduled_fulfillment_at',
        'fulfilled_at',
        'fulfillment_locked_at',
        'last_fulfillment_attempt_at',
        'fulfillment_attempt_count',
        'failure_reason',
        'expires_at',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'platform_fee' => 'decimal:8',
        'platform_fee_biu_share' => 'decimal:8',
        'platform_fee_org_share' => 'decimal:8',
        'commission_percentage' => 'decimal:6', // Support up to 6 decimal places for percentages
        'total_commission' => 'decimal:8', // Support up to 8 decimal places for very small commission amounts
        'platform_commission' => 'decimal:8', // Support up to 8 decimal places for very small commission amounts
        'nonprofit_commission' => 'decimal:8', // Support up to 8 decimal places for very small commission amounts
        'merchant_revenue' => 'decimal:8',
        'is_sent' => 'boolean',
        'purchased_at' => 'datetime',
        'requested_at' => 'datetime',
        'scheduled_fulfillment_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'fulfillment_locked_at' => 'datetime',
        'last_fulfillment_attempt_at' => 'datetime',
        'fulfillment_attempt_count' => 'integer',
        'expires_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return GiftCardStatus::isFulfilled($this->status)
               && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isPendingFulfillment(): bool
    {
        return $this->status === GiftCardStatus::PendingFulfillment->value;
    }

    public function statusLabel(): string
    {
        return GiftCardStatus::tryFrom($this->status)?->label() ?? (string) $this->status;
    }

    public static function generateUniqueCardNumber(): string
    {
        do {
            $cardNumber = str_pad((string) mt_rand(0, 9999999999999999), 16, '0', STR_PAD_LEFT);
        } while (self::query()->where('card_number', $cardNumber)->exists());

        return $cardNumber;
    }

    /**
     * @param  Builder<GiftCard>  $query
     * @return Builder<GiftCard>
     */
    public function scopeDueForFulfillment(Builder $query): Builder
    {
        return $query
            ->where('payment_method', 'believe_points')
            ->where('status', GiftCardStatus::PendingFulfillment->value)
            ->whereNotNull('scheduled_fulfillment_at')
            ->where('scheduled_fulfillment_at', '<=', now());
    }
}

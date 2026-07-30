<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'related_id',
        'related_type',
        'type',
        'ledger_type',
        'bp_status',
        'available_at',
        'current_owner',
        'brp_activity_type',
        'status',
        'amount',
        'fee',
        'currency',
        'payment_method',
        'transaction_id',
        'meta',
        'processed_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'processed_at' => 'datetime',
        'available_at' => 'datetime',
    ];

    public const STATUS_PENDING    = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WITHDRAWAL = 'withdrawal';
    public const STATUS_REFUND    = 'refund';
    public const STATUS_DEPOSIT   = 'deposit';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_ADJUSTED  = 'adjusted';
    public const STATUS_REVERSED  = 'reversed';

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_WITHDRAWAL,
            self::STATUS_REFUND,
            self::STATUS_DEPOSIT,
            self::STATUS_REJECTED,
            self::STATUS_ADJUSTED,
            self::STATUS_REVERSED,
        ];
    }

    // Boot method to hook into the creating event
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            // Generate a unique transaction ID if not provided
            if (empty($transaction->transaction_id)) {
                $transaction->transaction_id = 'TXN-' . strtoupper(Str::random(10));
            }
        });
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Hide ledger rows voided because Bridge never confirmed the movement.
     */
    public function scopeVisibleInWalletActivity($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('meta')
                ->orWhereRaw("JSON_EXTRACT(meta, '$.removed_from_active_ledger') IS NULL")
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.removed_from_active_ledger')) != 'true'");
        });
    }

    public static function record(array $data): self
    {
        return self::create([
            'user_id'        => $data['user_id'],
            'related_id'     => $data['related_id'] ?? null,
            'related_type'   => $data['related_type'] ?? null,
            'type'           => $data['type'] ?? 'purchase',
            'status'         => $data['status'] ?? 'pending',
            'amount'         => $data['amount'],
            'fee'            => $data['fee'] ?? 0,
            'currency'       => $data['currency'] ?? 'USD',
            'payment_method' => $data['payment_method'] ?? null,
            // 'transaction_id' is now handled automatically in boot()
            'meta'           => $data['meta'] ?? null,
            'processed_at'   => $data['processed_at'] ?? now(),
        ]);
    }
}

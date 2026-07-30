<?php

namespace App\Services\Admin;

use App\Models\BelievePointGiftInvite;
use App\Models\BelievePointPurchase;
use App\Models\BelievePointWalletTransfer;
use App\Models\CareAllianceDonation;
use App\Models\Donation;
use App\Models\Enrollment;
use App\Models\FundMeDonation;
use App\Models\GiftCard;
use App\Models\MerchantHubOfferRedemption;
use App\Models\MerchantHubReferralReward;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Raffle;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Support\ConnectionHubType;
use App\Support\UnifiedLedgerMajorType;
use App\Support\UnifiedLedgerModule;
use App\Support\UnifiedLedgerType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Query-layer filters for the admin transaction ledger (approximates UnifiedLedgerPresenter module rules).
 */
final class LedgerListFilters
{
    /**
     * @return array<int, string>
     */
    public static function ledgerTypeOptions(): array
    {
        return UnifiedLedgerType::all();
    }

    /**
     * Client V1.0 quick-filter pills (All, Purchases, Gifts, …).
     *
     * @return list<string>
     */
    public static function quickFilterOptions(): array
    {
        return [
            'all',
            'purchases',
            'gifts',
            'rewards',
            'donations',
            'gift_cards',
            'wallet',
            'subscriptions',
            'marketplace',
        ];
    }

    public static function quickFilterLabel(string $quick): string
    {
        return match (strtolower(trim($quick))) {
            'purchases' => 'Purchases',
            'gifts' => 'Gifts',
            'rewards' => 'Rewards',
            'donations' => 'Donations',
            'gift_cards' => 'Gift Cards',
            'wallet' => 'Wallet',
            'subscriptions' => 'Subscriptions',
            'marketplace' => 'Marketplace',
            default => 'All',
        };
    }

    public static function applyQuickFilter(Builder $query, string $quick): void
    {
        $quick = strtolower(trim($quick));
        if ($quick === '' || $quick === 'all' || ! in_array($quick, self::quickFilterOptions(), true)) {
            return;
        }

        match ($quick) {
            'purchases' => $query->where(function (Builder $q) {
                $q->where(function (Builder $bp) {
                    $bp->where('type', 'believe_points_purchase')
                        ->orWhere('meta->source', 'believe_points_purchase')
                        ->orWhere('meta->source', 'believe_points_purchase_bp')
                        ->orWhere('related_type', BelievePointPurchase::class)
                        ->orWhere('related_type', 'like', '%BelievePointPurchase');
                })->orWhereIn('type', [
                    'credit_purchase',
                    'email_purchase',
                    'sms_purchase',
                    'email_credit_purchase',
                    'sms_credit_purchase',
                    'newsletter_pro_targeting_purchase',
                ]);
            }),
            'gifts' => $query->where(function (Builder $q) {
                $q->whereIn('type', [
                    'bp_gift',
                    'bp_gift_sent',
                    'bp_gift_claimed',
                    'bp_gift_cancelled',
                    'bp_gift_expired',
                    'bp_gift_refunded',
                    'bp_gift_hold',
                    'bp_gift_claim',
                    'bp_gift_hold_refund',
                    'bp_gift_email_changed',
                ])->orWhere('transaction_id', 'like', 'bp_gift%')
                    ->orWhere('meta->source', 'like', 'bp_gift%');
            }),
            'rewards' => self::applyMajorType($query, UnifiedLedgerMajorType::REWARD),
            'donations' => self::applyMajorType($query, UnifiedLedgerMajorType::DONATION),
            'gift_cards' => self::scopeGiftCard(self::withRefundPayoutExclusion($query)),
            'wallet' => $query->where(function (Builder $q) {
                $q->where(function (Builder $w) {
                    self::scopeWallet($w);
                })->orWhere(function (Builder $transfer) {
                    $transfer->whereIn('type', [
                        'believe_points_wallet_transfer',
                        'bp_redemption',
                        'bridge_wallet_transfer',
                    ])->orWhereIn('meta->source', [
                        'believe_points_wallet_transfer',
                        'bp_redemption',
                        'bridge_wallet_transfer',
                    ])->orWhere('related_type', BelievePointWalletTransfer::class)
                        ->orWhere('related_type', 'like', '%BelievePointWalletTransfer');
                });
            }),
            'subscriptions' => self::applyMajorType($query, UnifiedLedgerMajorType::SUBSCRIPTION),
            'marketplace' => self::applyMajorType($query, UnifiedLedgerMajorType::MARKETPLACE),
            default => null,
        };
    }

    public static function applyLedgerType(Builder $query, string $ledgerType): void
    {
        $ledgerType = strtolower(trim($ledgerType));
        if (! in_array($ledgerType, UnifiedLedgerType::all(), true)) {
            return;
        }

        if ($ledgerType === UnifiedLedgerType::MONEY) {
            // Money rows, excluding BP-paid commerce spends that are presented as BP.
            $query->where(function (Builder $q) {
                $q->where('ledger_type', UnifiedLedgerType::MONEY)
                    ->orWhereNull('ledger_type');
            })->where(function (Builder $q) {
                $q->whereNull('payment_method')
                    ->orWhere('payment_method', '!=', 'believe_points')
                    ->orWhereIn('type', ['deposit', 'credit', 'refund']);
            });

            return;
        }

        if ($ledgerType === UnifiedLedgerType::BP) {
            $query->where(function (Builder $q) {
                $q->where('ledger_type', UnifiedLedgerType::BP)
                    ->orWhere(function (Builder $spend) {
                        $spend->where('payment_method', 'believe_points')
                            ->whereNotIn('type', ['deposit', 'credit', 'refund'])
                            ->where(function (Builder $stored) {
                                $stored->whereNull('ledger_type')
                                    ->orWhere('ledger_type', UnifiedLedgerType::MONEY);
                            });
                    });
            });

            return;
        }

        $query->where('ledger_type', $ledgerType);
    }

    /**
     * @return array<int, string>
     */
    public static function moduleOptions(): array
    {
        return UnifiedLedgerModule::filterOptions();
    }

    /**
     * @return array<int, string>
     */
    public static function majorTypeOptions(): array
    {
        return UnifiedLedgerMajorType::all();
    }

    public static function applyMajorType(Builder $query, string $majorType): void
    {
        $majorType = strtolower(trim($majorType));
        if (! in_array($majorType, UnifiedLedgerMajorType::all(), true)) {
            return;
        }

        // Approximate presenter Major Type using module + known type/meta patterns.
        match ($majorType) {
            UnifiedLedgerMajorType::DONATION => $query->where(function (Builder $q) {
                $q->where(function (Builder $d) {
                    self::whereMatchesDonationModule($d);
                })->orWhere(function (Builder $fm) {
                    self::scopeFundme($fm);
                })->orWhere(function (Builder $ca) {
                    self::scopeCampaign($ca);
                });
            })->whereNot(function (Builder $rf) {
                $rf->where('type', 'refund')->orWhere('status', Transaction::STATUS_REFUND);
            }),
            UnifiedLedgerMajorType::PURCHASE => $query->where(function (Builder $q) {
                $q->where(function (Builder $gc) {
                    self::scopeGiftCard($gc);
                })->orWhere(function (Builder $bp) {
                    $bp->where(function (Builder $inner) {
                        self::scopeBelievePoints($inner);
                    })->where(function (Builder $purchase) {
                        $purchase->where('type', 'believe_points_purchase')
                            ->orWhere('meta->source', 'believe_points_purchase')
                            ->orWhere('meta->source', 'believe_points_purchase_bp')
                            ->orWhere('related_type', BelievePointPurchase::class)
                            ->orWhere('related_type', 'like', '%BelievePointPurchase');
                    });
                })->orWhereIn('type', ['credit_purchase', 'email_purchase', 'sms_purchase']);
            }),
            UnifiedLedgerMajorType::SUBSCRIPTION => $query->where(function (Builder $q) {
                $q->where(function (Builder $s) {
                    self::scopeSupporterSubscription($s);
                })->orWhere(function (Builder $o) {
                    self::scopeOrganizationSubscription($o);
                })->orWhere(function (Builder $m) {
                    self::scopeMerchantSubscription($m);
                });
            }),
            UnifiedLedgerMajorType::TRANSFER => $query->where(function (Builder $q) {
                $q->where(function (Builder $bp) {
                    self::scopeBelievePoints($bp);
                })->whereNot(function (Builder $purchase) {
                    $purchase->where('type', 'believe_points_purchase')
                        ->orWhere('meta->source', 'believe_points_purchase')
                        ->orWhere('meta->source', 'believe_points_purchase_bp')
                        ->orWhere('type', 'bp_settlement')
                        ->orWhere('meta->source', 'bp_settlement');
                })->orWhere(function (Builder $w) {
                    self::scopeWallet($w);
                });
            }),
            UnifiedLedgerMajorType::SETTLEMENT => $query->where(function (Builder $q) {
                $q->where('type', 'bp_settlement')
                    ->orWhere('meta->source', 'bp_settlement');
            }),
            UnifiedLedgerMajorType::REWARD => self::scopeReward($query),
            UnifiedLedgerMajorType::ENROLLMENT => self::scopeConnectionHub($query),
            UnifiedLedgerMajorType::FEE => $query->where(function (Builder $q) {
                $q->whereIn('type', ['kyc_fee', 'administrative_fee'])
                    ->orWhereIn('meta->type', ['kyc_fee', 'administrative_fee']);
            }),
            UnifiedLedgerMajorType::MARKETPLACE => $query->where(function (Builder $q) {
                $q->where(function (Builder $m) {
                    self::scopeMarketplace($m);
                })->orWhere(function (Builder $s) {
                    self::scopeServicehub($s);
                })->orWhere(function (Builder $h) {
                    self::scopeMerchantHub($h);
                });
            }),
            UnifiedLedgerMajorType::COMPLIANCE => $query->where(function (Builder $q) {
                $q->whereIn('type', ['form_1023_application', 'compliance_application'])
                    ->orWhereIn('meta->type', ['form_1023_application', 'compliance_application']);
            }),
            UnifiedLedgerMajorType::ADJUSTMENT => $query->where(function (Builder $q) {
                $q->where(function (Builder $a) {
                    self::scopeAdjustment($a);
                })->orWhere(function (Builder $r) {
                    self::scopeRefund($r);
                })->orWhere(function (Builder $p) {
                    self::scopePayout($p);
                });
            }),
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function connectionHubTypeOptions(): array
    {
        return ConnectionHubType::VALUES;
    }

    public static function applyConnectionHubType(Builder $query, string $hubType): void
    {
        $hubType = strtolower(trim($hubType));
        if (! in_array($hubType, ConnectionHubType::VALUES, true)) {
            return;
        }

        $query->where(function (Builder $enrollmentQ) {
            self::whereMatchesEnrollmentModule($enrollmentQ);
        });
        $query->where(function (Builder $hubQ) use ($hubType) {
            $hubQ->where('meta->connection_hub_type', $hubType)
                ->orWhere('meta->course_type', $hubType);
        });
    }

    public static function applyOrganization(Builder $query, int $organizationId): void
    {
        if ($organizationId < 1) {
            return;
        }

        $query->where(function (Builder $q) use ($organizationId) {
            $q->where('meta->organization_id', $organizationId)
                ->orWhere(function (Builder $q2) use ($organizationId) {
                    $q2->where('related_type', Organization::class)
                        ->where('related_id', $organizationId);
                });

            if (Schema::hasTable('enrollments') && Schema::hasTable('courses') && Schema::hasTable('organizations')) {
                $q->orWhere(function (Builder $enrollmentQ) use ($organizationId) {
                    $enrollmentQ->where(function (Builder $typeQ) {
                        $typeQ->where('related_type', Enrollment::class)
                            ->orWhere('related_type', 'like', '%Enrollment');
                    })->whereExists(function ($sub) use ($organizationId) {
                        $sub->from('enrollments')
                            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
                            ->join('organizations', 'organizations.user_id', '=', 'courses.organization_id')
                            ->whereColumn('enrollments.id', 'transactions.related_id')
                            ->where('organizations.id', $organizationId);
                    });
                });
            }

            if (Schema::hasTable('courses') && Schema::hasTable('organizations')) {
                $q->orWhere(function (Builder $courseMetaQ) use ($organizationId) {
                    $courseMetaQ->whereNotNull('meta->course_id')
                        ->whereExists(function ($sub) use ($organizationId) {
                            $sub->from('courses')
                                ->join('organizations', 'organizations.user_id', '=', 'courses.organization_id')
                                ->where('organizations.id', $organizationId)
                                ->whereRaw(self::courseIdEqualsMetaCourseIdExpr());
                        });
                });
            }

            if (Schema::hasTable('donations')) {
                $q->orWhereExists(function ($sub) use ($organizationId) {
                    $sub->from('donations')
                        ->where('donations.organization_id', $organizationId)
                        ->whereRaw(self::donationIdEqualsMetaDonationIdExpr());
                });
            }
        });
    }

    public static function applyPeriod(Builder $query, string $period): void
    {
        $period = strtolower(trim($period));
        if (! in_array($period, ['day', 'week', 'month', 'year'], true)) {
            return;
        }

        $start = match ($period) {
            'day' => Carbon::now()->startOfDay(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            'year' => Carbon::now()->startOfYear(),
        };

        $query->whereRaw('COALESCE(processed_at, created_at) >= ?', [$start]);
    }

    public static function applyModule(Builder $query, string $module): void
    {
        $raw = strtolower(trim($module));
        $normalized = UnifiedLedgerModule::normalize($raw);
        $allowed = array_merge(self::moduleOptions(), ['believe_points', 'course']);
        if (! in_array($raw, $allowed, true) && ! in_array($normalized, self::moduleOptions(), true)) {
            return;
        }

        match ($normalized) {
            'refund' => self::scopeRefund($query),
            'payout' => self::scopePayout($query),
            'campaign' => self::scopeCampaign(self::withRefundPayoutExclusion($query)),
            'fundme' => self::scopeFundme(self::withRefundPayoutExclusion($query)),
            'donation' => self::scopeDonation(self::withRefundPayoutExclusion($query)),
            'servicehub' => self::scopeServicehub(self::withRefundPayoutExclusion($query)),
            'connection_hub' => self::scopeConnectionHub(self::withRefundPayoutExclusion($query)),
            'merchant_hub' => self::scopeMerchantHub(self::withRefundPayoutExclusion($query)),
            'supporter_subscription' => self::scopeSupporterSubscription(self::withRefundPayoutExclusion($query)),
            'organization_subscription' => self::scopeOrganizationSubscription(self::withRefundPayoutExclusion($query)),
            'merchant_subscription' => self::scopeMerchantSubscription(self::withRefundPayoutExclusion($query)),
            'adjustment' => self::scopeAdjustment(self::withRefundPayoutExclusion($query)),
            'gift_card' => self::scopeGiftCard(self::withRefundPayoutExclusion($query)),
            'marketplace' => self::scopeMarketplace(self::withRefundPayoutExclusion($query)),
            'general' => self::scopeBelievePoints($query),
            'reward' => self::scopeReward($query),
            'wallet' => self::scopeWallet($query),
            default => null,
        };
    }

    private static function scopeWallet(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where(function (Builder $d) {
                $d->where('type', 'deposit')
                    ->whereNotNull('meta->deposited_by');
            })->orWhere(function (Builder $w) {
                $w->where('type', 'deposit')
                    ->where('payment_method', 'wallet')
                    ->whereNotNull('meta->organization_id');
            });
        });
    }

    private static function withRefundPayoutExclusion(Builder $query): Builder
    {
        $query->whereNot(function (Builder $q) {
            $q->where('type', 'refund')
                ->orWhere('status', Transaction::STATUS_REFUND);
        })->whereNotIn('type', ['withdrawal', 'transfer_out']);

        return $query;
    }

    private static function scopeRefund(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('type', 'refund')
                ->orWhere('status', Transaction::STATUS_REFUND);
        });
    }

    private static function scopePayout(Builder $query): void
    {
        $query->whereIn('type', ['withdrawal', 'transfer_out']);
    }

    private static function scopeCampaign(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('related_type', CareAllianceDonation::class)
                ->orWhereNotNull('meta->care_alliance_donation_id')
                ->orWhere('meta->source', 'care_alliance_split')
                ->orWhere('meta->source', 'care_alliance_campaign_split');
        });
    }

    private static function scopeFundme(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('related_type', FundMeDonation::class)
                ->orWhereNotNull('meta->fundme_donation_id')
                ->orWhereNotNull('meta->fundme_campaign_id');
        });
    }

    /**
     * Rows classified as the ledger "donation" module (direct Believe donations; excludes Care Alliance & FundMe).
     *
     * @see \App\Services\Admin\UnifiedLedgerPresenter::resolveModule
     */
    private static function whereMatchesDonationModule(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where(function (Builder $inner) {
                $inner->where(function (Builder $d) {
                    $d->where('related_type', Donation::class)
                        ->whereNotNull('related_id');
                })
                    ->orWhereNotNull('meta->donation_id')
                    ->orWhere('meta->source', 'organization_donation')
                    ->orWhere('payment_method', 'donation')
                    ->orWhere(function (Builder $p) {
                        $p->where('meta->ledger_role', 'donor_payment')
                            ->whereNotNull('meta->donation_id');
                    });
            })
                ->whereNot(function (Builder $ex) {
                    $ex->where('related_type', CareAllianceDonation::class)
                        ->orWhereNotNull('meta->care_alliance_donation_id')
                        ->orWhere('meta->source', 'care_alliance_split')
                        ->orWhere('meta->source', 'care_alliance_campaign_split');
                })
                ->whereNot(function (Builder $ex) {
                    $ex->where('related_type', FundMeDonation::class)
                        ->orWhereNotNull('meta->fundme_donation_id')
                        ->orWhereNotNull('meta->fundme_campaign_id');
                });
        });
    }

    private static function scopeDonation(Builder $query): void
    {
        self::whereMatchesDonationModule($query);
    }

    private static function scopeServicehub(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('related_type', ServiceOrder::class)
                ->orWhereNotNull('meta->service_order_id');
        });
    }

    private static function scopeConnectionHub(Builder $query): void
    {
        $query->where(function (Builder $q) {
            self::whereMatchesEnrollmentModule($q);
        });
    }

    /**
     * @deprecated Use scopeConnectionHub — kept as alias for legacy filter value `course`.
     */
    private static function scopeCourse(Builder $query): void
    {
        self::scopeConnectionHub($query);
    }

    /**
     * Connection Hub course / event enrollment rows (including legacy meta-only links).
     */
    private static function whereMatchesEnrollmentModule(Builder $query): void
    {
        $query->where('related_type', Enrollment::class)
            ->orWhere('related_type', 'like', '%Enrollment')
            ->orWhere('meta->source', 'course_enrollment')
            ->orWhereNotNull('meta->enrollment_record_id')
            ->orWhereNotNull('meta->enrollment_id');
    }

    /**
     * Paid Connection Hub enrollments are stored as type `purchase`; free as `enrollment`.
     */
    public static function applyEnrollmentWalletType(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('type', 'enrollment')
                ->orWhere(function (Builder $purchase) {
                    $purchase->where('type', 'purchase');
                    self::whereMatchesEnrollmentModule($purchase);
                });
        });
    }

    private static function scopeMerchantHub(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('related_type', MerchantHubOfferRedemption::class)
                ->orWhere('related_type', MerchantHubReferralReward::class);
        });
    }

    /**
     * Platform plans & wallet plans (supporter paying BIU), including KYC fee and plan checkout stored as purchase + meta.
     */
    private static function scopeSupporterSubscription(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('related_type', Plan::class)
                ->orWhere('related_type', 'like', '%Plan')
                ->orWhereIn('type', ['plan_subscription', 'kyc_fee', 'wallet_subscription'])
                ->orWhereNotNull('meta->wallet_plan_id')
                ->orWhere(function (Builder $p) {
                    $p->where('type', 'purchase')
                        ->whereNotNull('meta->plan_id')
                        ->whereNotNull('meta->plan_name');
                });
        });
    }

    /**
     * Org marketing credits (newsletter/SMS/email), nonprofit commission without merchant — not supporter platform/wallet plans.
     */
    private static function scopeOrganizationSubscription(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where(function (Builder $c) {
                $c->where('type', 'commission')
                    ->where(function (Builder $m) {
                        $m->whereNull('meta->merchant_id')
                            ->orWhere('meta->merchant_id', '')
                            ->orWhere('meta->merchant_id', '0');
                    });
            })
                ->orWhereIn('type', ['newsletter_pro_targeting_lifetime', 'sms_purchase', 'email_purchase'])
                ->orWhereIn('meta->type', ['newsletter_pro_targeting_lifetime', 'sms_purchase', 'email_purchase']);
        });
    }

    private static function scopeMerchantSubscription(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where(function (Builder $c) {
                $c->where('type', 'commission')
                    ->whereNotNull('meta->merchant_id')
                    ->where('meta->merchant_id', '!=', '')
                    ->where('meta->merchant_id', '!=', '0');
            })
                ->orWhere(function (Builder $m) {
                    $m->whereNotNull('meta->merchant_id')
                        ->where('meta->merchant_id', '!=', '')
                        ->where('meta->merchant_id', '!=', '0')
                        ->whereNotNull('meta->subscription_id');
                });
        });
    }

    private static function scopeAdjustment(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('type', 'adjustment')
                ->orWhereNotNull('meta->adjustment_reason');
        });
    }

    private static function scopeBelievePoints(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where(function (Builder $bp) {
                $bp->where('related_type', BelievePointPurchase::class)
                    ->orWhere('related_type', 'like', '%BelievePointPurchase')
                    ->orWhere('related_type', BelievePointWalletTransfer::class)
                    ->orWhere('related_type', 'like', '%BelievePointWalletTransfer')
                    ->orWhere('related_type', BelievePointGiftInvite::class)
                    ->orWhere('related_type', 'like', '%BelievePointGiftInvite')
                    ->orWhere('related_type', 'like', '%SupporterBelievePointGift')
                    ->orWhere('type', 'believe_points_wallet_transfer')
                    ->orWhere('type', 'bp_settlement')
                    ->orWhere('type', 'bp_gift')
                    ->orWhere('type', 'bp_gift_sent')
                    ->orWhere('type', 'bp_gift_claimed')
                    ->orWhere('type', 'bp_gift_cancelled')
                    ->orWhere('type', 'bp_gift_expired')
                    ->orWhere('type', 'bp_gift_refunded')
                    ->orWhere('type', 'bp_gift_hold')
                    ->orWhere('type', 'bp_gift_claim')
                    ->orWhere('type', 'bp_gift_hold_refund')
                    ->orWhere('type', 'bp_gift_email_changed')
                    ->orWhere('meta->source', 'believe_points_purchase')
                    ->orWhere('meta->source', 'believe_points_purchase_refund')
                    ->orWhere('meta->source', 'believe_points_wallet_transfer')
                    ->orWhere('meta->source', 'bp_settlement')
                    ->orWhere('meta->source', 'bp_gift')
                    ->orWhere('meta->source', 'bp_gift_sent')
                    ->orWhere('meta->source', 'bp_gift_claimed')
                    ->orWhere('meta->source', 'bp_gift_cancelled')
                    ->orWhere('meta->source', 'bp_gift_expired')
                    ->orWhere('meta->source', 'bp_gift_refunded')
                    ->orWhere('meta->source', 'bp_gift_hold')
                    ->orWhere('meta->source', 'bp_gift_claim')
                    ->orWhere('meta->source', 'bp_gift_hold_refund')
                    ->orWhere('meta->source', 'bp_gift_email_changed');
            })
                ->whereNot(function (Builder $rf) {
                    $rf->where('type', 'refund')
                        ->orWhere('status', Transaction::STATUS_REFUND);
                })
                // BRP earn/redeem rows use the Reward module, not Believe Points (BP currency).
                ->whereNot(function (Builder $brp) {
                    self::whereMatchesRewardModule($brp);
                });
        });
    }

    private static function scopeReward(Builder $query): void
    {
        $query->where(function (Builder $q) {
            self::whereMatchesRewardModule($q);
        });
    }

    private static function whereMatchesRewardModule(Builder $query): void
    {
        $query->where('ledger_type', UnifiedLedgerType::BRP)
            ->orWhere('currency', 'BRP')
            ->orWhere('meta->source', 'reward_point_ledger')
            ->orWhere('meta->source', 'believe_points_purchase_brp')
            ->orWhere('transaction_id', 'like', 'brp:%');
    }

    private static function scopeGiftCard(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('related_type', GiftCard::class)
                ->orWhere('related_type', 'like', '%GiftCard')
                ->orWhere('type', 'gift_card_purchase')
                ->orWhere('meta->type', 'gift_card_purchase')
                ->orWhereNotNull('meta->gift_card_id');
        });
    }

    private static function scopeMarketplace(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('related_type', Order::class)
                ->orWhere('related_type', Raffle::class)
                ->orWhere(function (Builder $p) {
                    $p->where('type', 'purchase')
                        ->whereNot(function (Builder $bp) {
                            $bp->where('related_type', BelievePointPurchase::class)
                                ->orWhere('related_type', 'like', '%BelievePointPurchase');
                        })
                        ->whereNot(function (Builder $don) {
                            self::whereMatchesDonationModule($don);
                        })
                        ->whereNot(function (Builder $mh) {
                            $mh->where('related_type', MerchantHubOfferRedemption::class)
                                ->orWhere('related_type', MerchantHubReferralReward::class);
                        })
                        ->whereNot(function (Builder $so) {
                            $so->where('related_type', ServiceOrder::class)
                                ->orWhereNotNull('meta->service_order_id');
                        })
                        ->whereNot(function (Builder $en) {
                            self::whereMatchesEnrollmentModule($en);
                        })
                        ->whereNot(function (Builder $ca) {
                            $ca->where('related_type', CareAllianceDonation::class)
                                ->orWhereNotNull('meta->care_alliance_donation_id')
                                ->orWhere('meta->source', 'care_alliance_split')
                                ->orWhere('meta->source', 'care_alliance_campaign_split');
                        })
                        ->whereNot(function (Builder $fm) {
                            $fm->where('related_type', FundMeDonation::class)
                                ->orWhereNotNull('meta->fundme_donation_id')
                                ->orWhereNotNull('meta->fundme_campaign_id');
                        });
                })
                ->orWhere('type', 'transfer_in')
                ->orWhere(function (Builder $dep) {
                    $dep->where('type', 'deposit')
                        ->where(function (Builder $z) {
                            $z->whereNull('meta->donation_id')
                                ->where(function (Builder $pm) {
                                    $pm->whereNull('payment_method')
                                        ->orWhere('payment_method', 'not like', '%donat%');
                                });
                        })
                        ->whereNot(function (Builder $so) {
                            $so->where('related_type', ServiceOrder::class)
                                ->orWhereNotNull('meta->service_order_id');
                        })
                        ->whereNot(function (Builder $en) {
                            self::whereMatchesEnrollmentModule($en);
                        })
                        ->whereNot(function (Builder $ca) {
                            $ca->where('related_type', CareAllianceDonation::class)
                                ->orWhereNotNull('meta->care_alliance_donation_id')
                                ->orWhere('meta->source', 'care_alliance_split')
                                ->orWhere('meta->source', 'care_alliance_campaign_split');
                        })
                        ->whereNot(function (Builder $fm) {
                            $fm->where('related_type', FundMeDonation::class)
                                ->orWhereNotNull('meta->fundme_donation_id')
                                ->orWhereNotNull('meta->fundme_campaign_id');
                        });
                });
        });

        $query->whereNot(function (Builder $gc) {
            $gc->where('related_type', GiftCard::class)
                ->orWhere('related_type', 'like', '%GiftCard')
                ->orWhere('type', 'gift_card_purchase')
                ->orWhere('meta->type', 'gift_card_purchase')
                ->orWhereNotNull('meta->gift_card_id');
        });

        $query->whereNot(function (Builder $brp) {
            self::whereMatchesRewardModule($brp);
        });
    }

    private static function donationIdEqualsMetaDonationIdExpr(): string
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return 'donations.id = CAST(json_extract(transactions.meta, \'$.donation_id\') AS INTEGER)';
        }

        return 'donations.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(transactions.meta, \'$.donation_id\')) AS UNSIGNED)';
    }

    private static function courseIdEqualsMetaCourseIdExpr(): string
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return 'courses.id = CAST(json_extract(transactions.meta, \'$.course_id\') AS INTEGER)';
        }

        return 'courses.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(transactions.meta, \'$.course_id\')) AS UNSIGNED)';
    }
}

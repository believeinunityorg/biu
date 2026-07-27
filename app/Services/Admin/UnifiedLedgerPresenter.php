<?php

namespace App\Services\Admin;

use App\Models\CareAllianceDonation;
use App\Models\Event;
use App\Models\FundMeDonation;
use App\Models\Merchant;
use App\Models\MerchantHubOfferRedemption;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\UnifiedLedgerClassificationService;
use App\Services\EnrollmentLedgerService;
use App\Support\ConnectionHubType;
use App\Support\UnifiedLedgerBpModule;
use App\Support\UnifiedLedgerType;

/**
 * Builds the admin "BIU unified ledger" row: combines the workbook spec (module, transaction_type,
 * From/To, amount breakdown, provider, reference) with finance columns from ledger_report.
 */
class UnifiedLedgerPresenter
{
    /**
     * @param  array<string, mixed>  $ledgerReport  Output of TransactionLedgerController::buildLedgerReportRow
     * @param  array<string, mixed>|null  $donationPayload
     * @param  array{related_kind: string, related_label: string, related_display_name: string}  $related
     * @return array<string, mixed>
     */
    public function present(
        Transaction $t,
        array $ledgerReport,
        ?array $donationPayload,
        ?string $donationPerspective,
        array $related,
    ): array {
        $meta = is_array($t->meta) ? $t->meta : [];
        $sourceType = (string) ($ledgerReport['source_type'] ?? 'ledger_unclassified');
        $module = $this->resolveModule($t, $sourceType, $ledgerReport);
        $transactionType = $this->resolveTransactionType($t, $module, $sourceType, $donationPerspective);
        $parties = $this->resolveParties($t, $donationPayload, $donationPerspective, $ledgerReport, $related, $module);
        $amounts = $this->resolveAmounts($t, $meta, $ledgerReport);
        $sellingPayouts = $this->resolveSellingPayoutAmounts($ledgerReport);
        $provider = $this->resolveProvider($t, $meta, $ledgerReport, $donationPayload);
        $reference = $this->resolveExternalReference($t, $meta);
        $relatedRecord = $this->resolveRelatedRecordLabel($t, $donationPayload, $related, $sourceType);
        $subscriber = $this->resolveSubscriberContact($parties, $t, $donationPayload);
        $when = $t->processed_at ?? $t->created_at;
        $orderForMarkup = $this->resolveOrderForSellingPriceMarkup($t);
        $connectionHubType = $this->resolveConnectionHubType($t);
        $sellingPriceMarkupPercent = $orderForMarkup !== null
            ? $this->resolveSellingPriceMarkupPercentFromOrder($orderForMarkup)
            : null;
        $sellingPriceMarkupAmount = $orderForMarkup !== null
            ? $this->resolveSellingPriceMarkupAmountFromOrder($orderForMarkup)
            : null;
        $supplierCostAmount = $orderForMarkup !== null
            ? $this->resolveSupplierCostAmountFromOrder($orderForMarkup)
            : null;

        if ($supplierCostAmount === null && $module === 'gift_card') {
            $face = $ledgerReport['gift_card_face_value'] ?? $ledgerReport['subtotal_amount'] ?? null;
            if (is_numeric($face) && (float) $face > 0) {
                $supplierCostAmount = round((float) $face, 2);
            }
        }

        $parties = $this->applyGeneralModuleTransferParties($t, $module, $connectionHubType, $parties);
        $eventName = $this->resolveBpTransferEventName($t, $module, $connectionHubType)
            ?? $this->resolveEventName($t);

        return [
            'txn_id' => $t->id,
            'datetime_iso' => $when->toIso8601String(),
            'module' => $module,
            'transaction_type' => $transactionType,
            'direction' => $this->resolveDirection($t, $module),
            'from_type' => $parties['from_type'],
            'from_name' => $parties['from_name'],
            'from_email' => $parties['from_email'],
            'from_id' => $parties['from_id'],
            'to_type' => $parties['to_type'],
            'to_name' => $parties['to_name'],
            'to_email' => $parties['to_email'],
            'to_id' => $parties['to_id'],
            'related_record' => $relatedRecord,
            'subtotal_amount' => $amounts['subtotal'],
            'sales_tax_amount' => $amounts['sales_tax'],
            'shipping_amount' => $amounts['shipping'],
            'gross_amount' => $amounts['gross'],
            'processor_fee_amount' => $amounts['processor_fee'],
            'stripe_fee_amount' => $amounts['stripe_fee'],
            'bridge_fee_amount' => $amounts['bridge_fee'],
            'biu_fee_amount' => $amounts['biu_fee'],
            'split_amount' => $amounts['split'],
            'refund_amount' => $amounts['refund'],
            'net_amount' => $amounts['net'],
            'supplier_payout_amount' => $sellingPayouts['supplier'],
            'organization_payout_amount' => $sellingPayouts['organization'],
            'platform_payout_amount' => $sellingPayouts['platform'],
            'supporter_payout_amount' => $sellingPayouts['supporter'],
            'currency' => $t->currency ?? 'USD',
            'status' => $this->resolveDisplayStatus($t),
            'provider' => $provider,
            'reference' => $reference,
            'organization_id' => $ledgerReport['organization_id'] ?? null,
            'organization_name' => $this->resolveOrganizationName($ledgerReport, $parties),
            'organization_ein' => isset($ledgerReport['organization_ein']) && $ledgerReport['organization_ein'] !== ''
                ? (string) $ledgerReport['organization_ein']
                : null,
            'subscriber_name' => $subscriber['name'],
            'subscriber_email' => $subscriber['email'],
            'merchant_name' => $this->resolveMerchantName($t, $module, $parties),
            'campaign_name' => $this->resolveCampaignName($t, $module, $donationPayload, $related),
            'event_name' => $eventName,
            'connection_hub_type' => $connectionHubType,
            'connection_hub_type_label' => $connectionHubType !== null && $connectionHubType !== ''
                ? ConnectionHubType::label($connectionHubType)
                : null,
            'supplier_name' => isset($ledgerReport['supplier_name']) && $ledgerReport['supplier_name'] !== ''
                ? (string) $ledgerReport['supplier_name']
                : null,
            'supplier_type' => isset($ledgerReport['supplier_type']) && $ledgerReport['supplier_type'] !== ''
                ? (string) $ledgerReport['supplier_type']
                : null,
            /** First marketplace line with a catalog Product: `products.profit_margin_percentage` (Printify / manual / hub resale). */
            'selling_price_markup_percent' => $sellingPriceMarkupPercent,
            /** Sum of (line retail − line cost) for lines with a Product + margin; cost from `source_cost`×qty or implied from % (retail×m/(100+m)). */
            'selling_price_markup_amount' => $sellingPriceMarkupAmount,
            /** Sum of supplier base cost for those catalog lines: `source_cost`×qty, else line ÷ (1 + %÷100); pairs with markup (Subtotal_line ≈ Cost + Markup). */
            'supplier_cost_amount' => $supplierCostAmount,
            'wallet_amount' => UnifiedLedgerWalletAmountResolver::resolve($t),
            ...UnifiedLedgerClassificationService::presentForTransaction($t),
        ];
    }

    /**
     * First line with a linked Product that has profit_margin_percentage.
     */
    private function resolveSellingPriceMarkupPercentFromOrder(Order $order): ?float
    {
        $order->loadMissing(['items.product']);
        foreach ($order->items as $item) {
            $product = $item->product;
            if ($product !== null && $product->profit_margin_percentage !== null) {
                return round((float) $product->profit_margin_percentage, 2);
            }
        }

        return null;
    }

    /**
     * Total catalog markup dollars on the order: per line, if Product has profit_margin_percentage,
     * use source_cost × qty vs line subtotal when source_cost is set; else retail × m/(100+m) when m > 0.
     */
    private function resolveSellingPriceMarkupAmountFromOrder(Order $order): ?float
    {
        $order->loadMissing(['items.product']);
        $sum = 0.0;
        $found = false;
        foreach ($order->items as $item) {
            $product = $item->product;
            if ($product === null || $product->profit_margin_percentage === null) {
                continue;
            }
            $m = (float) $product->profit_margin_percentage;
            if ($m < 0) {
                continue;
            }
            $lineSubtotal = (float) $item->subtotal;
            $qty = max(1, (int) $item->quantity);
            if ($lineSubtotal <= 0) {
                continue;
            }
            $found = true;
            $sourceCost = $product->source_cost;
            if ($sourceCost !== null && (float) $sourceCost >= 0) {
                $totalCost = (float) $sourceCost * $qty;
                $sum += max(0.0, round($lineSubtotal - $totalCost, 2));
            } elseif ($m > 0) {
                $sum += round($lineSubtotal * ($m / (100.0 + $m)), 2);
            }
        }

        return $found ? round($sum, 2) : null;
    }

    /**
     * Per catalog line with margin: supplier cost = `source_cost`×qty, or retail ÷ (1 + markup%÷100) when cost is not stored.
     * Sum of line costs for lines included in {@see resolveSellingPriceMarkupAmountFromOrder()}.
     */
    private function resolveSupplierCostAmountFromOrder(Order $order): ?float
    {
        $order->loadMissing(['items.product']);
        $sum = 0.0;
        $found = false;
        foreach ($order->items as $item) {
            $product = $item->product;
            if ($product === null || $product->profit_margin_percentage === null) {
                continue;
            }
            $m = (float) $product->profit_margin_percentage;
            if ($m < 0) {
                continue;
            }
            $lineSubtotal = (float) $item->subtotal;
            $qty = max(1, (int) $item->quantity);
            if ($lineSubtotal <= 0) {
                continue;
            }
            $found = true;
            $sourceCost = $product->source_cost;
            if ($sourceCost !== null && (float) $sourceCost >= 0) {
                $sum += round((float) $sourceCost * $qty, 2);
            } elseif ($m > 0) {
                $sum += round($lineSubtotal / (1.0 + $m / 100.0), 2);
            } else {
                $sum += round($lineSubtotal, 2);
            }
        }

        return $found ? round($sum, 2) : null;
    }

    private function resolveOrderForSellingPriceMarkup(Transaction $t): ?Order
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';
        if (($rt === Order::class || str_ends_with($rt, 'Order')) && $t->related_id !== null && (int) $t->related_id > 0) {
            return Order::query()->find((int) $t->related_id);
        }
        if (! empty($meta['order_id'])) {
            $oid = (int) $meta['order_id'];
            if ($oid > 0) {
                return Order::query()->find($oid);
            }
        }

        return null;
    }

    /**
     * Supplier / nonprofit / platform settlement lines for selling modules (marketplace, Service Hub, etc.).
     *
     * @param  array<string, mixed>  $ledgerReport
     * @return array{supplier: float|null, organization: float|null, platform: float|null, supporter: float|null}
     */
    private function resolveSellingPayoutAmounts(array $ledgerReport): array
    {
        $pick = function (string $key) use ($ledgerReport): ?float {
            if (! array_key_exists($key, $ledgerReport)) {
                return null;
            }
            $v = $ledgerReport[$key];
            if ($v === null || $v === '') {
                return null;
            }

            return round((float) $v, 2);
        };

        return [
            'supplier' => $pick('supplier_payout'),
            'organization' => $pick('organization_payout'),
            'platform' => $pick('platform_payout'),
            'supporter' => $pick('supporter_payout'),
        ];
    }

    /**
     * Believe Reward Points (BRP) ledger rows — earn/redeem credits, not BP purchases or marketplace sales.
     */
    private function isBrpLedgerModule(Transaction $t): bool
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        if ($t->ledger_type === UnifiedLedgerType::BRP) {
            return true;
        }
        if (strtoupper((string) ($t->currency ?? '')) === 'BRP') {
            return true;
        }
        if (in_array(($meta['source'] ?? ''), ['reward_point_ledger', 'believe_points_purchase_brp'], true)) {
            return true;
        }

        return str_starts_with((string) ($t->transaction_id ?? ''), 'brp:');
    }

    /**
     * @param  array<string, mixed>  $ledgerReport
     */
    private function resolveModule(Transaction $t, string $sourceType, array $ledgerReport = []): string
    {
        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';
        $base = $rt !== '' ? class_basename($rt) : '';

        if ($t->type === 'refund' || $t->status === Transaction::STATUS_REFUND) {
            return 'refund';
        }
        if (in_array($t->type, ['withdrawal', 'transfer_out'], true)) {
            return 'payout';
        }

        // BRP earn/redeem rows are Reward — never Marketplace (fallback) or Believe Points (BP currency).
        if ($this->isBrpLedgerModule($t)) {
            return 'reward';
        }

        return match ($sourceType) {
            'donation' => 'donation',
            'fundme_donation' => 'fundme',
            'care_alliance_donation' => 'campaign',
            'believe_points_purchase' => 'believe_points',
            'believe_points_wallet_transfer' => 'believe_points',
            'bp_settlement' => 'believe_points',
            'bp_gift_hold', 'bp_gift_claim', 'bp_gift_hold_refund', 'bp_gift_email_changed',
            'bp_gift', 'bp_gift_sent', 'bp_gift_claimed', 'bp_gift_cancelled', 'bp_gift_expired', 'bp_gift_refunded' => 'believe_points',
            'order' => $base === 'MerchantHubOfferRedemption'
                ? 'merchant_hub'
                : ($this->isGiftCardPurchaseContext($t)
                    ? 'gift_card'
                    : ($this->isOrgMarketingCommsProductType($this->effectiveWalletProductType($t))
                        ? 'organization_subscription'
                        : 'marketplace')),
            'service_order' => 'servicehub',
            'enrollment' => 'connection_hub',
            'plan_subscription', 'wallet_plan_subscription' => 'supporter_subscription',
            'gift_card' => 'gift_card',
            'raffle' => 'marketplace',
            'merchant_hub_redemption', 'merchant_hub_referral' => 'merchant_hub',
            'commission' => $this->moduleForCommission($t),
            'ledger_unclassified' => match ($base) {
                'Enrollment' => 'connection_hub',
                'Plan' => 'supporter_subscription',
                'GiftCard' => 'gift_card',
                'Raffle' => 'marketplace',
                'MerchantHubOfferRedemption' => 'merchant_hub',
                'MerchantHubReferralReward' => 'merchant_hub',
                'BelievePointGiftInvite' => 'believe_points',
                'SupporterBelievePointGift' => 'believe_points',
                default => $this->moduleFromMetaOrType($t, $ledgerReport),
            },
            default => match ($base) {
                'Enrollment' => 'connection_hub',
                'Plan' => 'supporter_subscription',
                'GiftCard' => 'gift_card',
                'Raffle' => 'marketplace',
                'MerchantHubOfferRedemption' => 'merchant_hub',
                'MerchantHubReferralReward' => 'merchant_hub',
                'BelievePointGiftInvite' => 'believe_points',
                'SupporterBelievePointGift' => 'believe_points',
                default => $this->moduleFromMetaOrType($t, $ledgerReport),
            },
        };
    }

    private function moduleForCommission(Transaction $t): string
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        if (! empty($meta['merchant_id'])) {
            return 'merchant_subscription';
        }

        return 'organization_subscription';
    }

    /**
     * Real product line for ledger classification. The DB `transactions.type` column was historically a
     * short ENUM, so values like `newsletter_pro_targeting_lifetime` were stored as `purchase`. Prefer
     * `meta['type']` (Stripe metadata echoed into meta) and known description patterns for legacy rows.
     */
    private function effectiveWalletProductType(Transaction $t): string
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $direct = strtolower(trim((string) ($t->type ?? '')));
        $fromMeta = strtolower(trim((string) ($meta['type'] ?? '')));

        $canonical = ['newsletter_pro_targeting_lifetime', 'sms_purchase', 'email_purchase', 'gift_card_purchase', 'fundme_donation'];
        if (in_array($fromMeta, $canonical, true)) {
            return $fromMeta;
        }
        if (in_array($direct, $canonical, true)) {
            return $direct;
        }

        $desc = (string) ($meta['description'] ?? '');
        if ($desc !== '' && str_contains($desc, 'Newsletter Pro targeting') && str_contains($desc, 'lifetime')) {
            return 'newsletter_pro_targeting_lifetime';
        }

        return $direct;
    }

    private function isOrgMarketingCommsProductType(string $normalizedType): bool
    {
        return in_array(strtolower(trim($normalizedType)), [
            'newsletter_pro_targeting_lifetime',
            'sms_purchase',
            'email_purchase',
        ], true);
    }

    /**
     * Gift card purchases (Phaze / org flows): polymorphic GiftCard, explicit wallet type, or meta echoes.
     */
    private function isGiftCardPurchaseContext(Transaction $t): bool
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';
        if ($rt !== '' && (str_ends_with($rt, 'GiftCard') || class_basename($rt) === 'GiftCard')) {
            return true;
        }
        $eff = $this->effectiveWalletProductType($t);
        if ($eff === 'gift_card_purchase') {
            return true;
        }
        if (! empty($meta['gift_card_id'])) {
            return true;
        }

        return false;
    }

    /**
     * Map `transactions.type` strings (product / checkout flows) to a BIU module.
     * Returns null so {@see moduleFromMetaOrType()} can still apply deposit / ledger heuristics.
     */
    private function resolveModuleSlugFromWalletType(string $type, Transaction $t): ?string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return null;
        }

        return match ($type) {
            'commission' => $this->moduleForCommission($t),
            // Org marketing / comms products (sold by organizations — not catalog marketplace).
            'newsletter_pro_targeting_lifetime',
            'sms_purchase',
            'email_purchase' => 'organization_subscription',
            'gift_card_purchase' => 'gift_card',
            'purchase',
            'raffle_sale',
            'raffle_tickets',
            'administrative_fee',
            'animal_purchase',
            'winning_bid',
            'redemption',
            'credit_purchase',
            'fractional_ownership',
            'form_1023_application',
            'compliance_application',
            'merchant_hub_redemption' => 'marketplace',
            'enrollment',
            'free',
            'paid',
            'cancellation' => 'course',
            'service_order' => 'servicehub',
            'fundme_donation' => 'fundme',
            'donation' => 'donation',
            'plan_subscription',
            'wallet_subscription',
            'kyc_fee' => 'supporter_subscription',
            'merchant_subscription' => 'organization_subscription',
            'believe_points_purchase',
            'believe_points_wallet_transfer',
            'bp_settlement',
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
            'believe_points_auto_replenish',
            'believe_points_auto_replenish_setup' => 'believe_points',
            'referral_reward',
            'direct_referral',
            'big_boss_override' => 'merchant_hub',
            'deposit',
            'withdrawal',
            'refund',
            'transfer_in',
            'transfer_out',
            'transfer' => null,
            default => null,
        };
    }

    /**
     * When `source_type` is unclassified, infer module from meta, polymorphic type, and ledger report (selling columns).
     *
     * @param  array<string, mixed>  $ledgerReport
     */
    private function moduleFromMetaOrType(Transaction $t, array $ledgerReport = []): string
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $type = (string) ($t->type ?? '');
        $effectiveType = $this->effectiveWalletProductType($t);
        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';
        $base = $rt !== '' ? class_basename($rt) : '';

        // Org newsletter / SMS / email products: never classify as marketplace just because checkout has order_id.
        if ($this->isOrgMarketingCommsProductType($effectiveType)) {
            return 'organization_subscription';
        }

        // Gift cards: own module (not general Marketplace).
        if ($this->isGiftCardPurchaseContext($t)) {
            return 'gift_card';
        }

        if (EnrollmentLedgerService::transactionIsEnrollment($t)) {
            return 'connection_hub';
        }

        // Meta keys (often set when related_type is null).
        if (($meta['source'] ?? '') === 'course_enrollment') {
            return 'connection_hub';
        }
        if (! empty($meta['order_id'])) {
            return 'marketplace';
        }
        if (! empty($meta['service_order_id'])) {
            return 'servicehub';
        }
        if (! empty($meta['enrollment_id'])) {
            return 'connection_hub';
        }
        if (! empty($meta['care_alliance_donation_id'])) {
            return 'campaign';
        }
        if (! empty($meta['fundme_donation_id']) || ! empty($meta['fundme_campaign_id'])) {
            return 'fundme';
        }
        if (! empty($meta['donation_id'])) {
            return 'donation';
        }
        if (! empty($meta['offer_id']) && ! empty($meta['receipt_code'])) {
            return 'merchant_hub';
        }

        // When source_type is "ledger_unclassified", related_type usually tells the real module.
        if ($rt !== '') {
            if (str_ends_with($rt, 'BelievePointPurchase')) {
                return 'believe_points';
            }
            if (str_ends_with($rt, 'BelievePointWalletTransfer')) {
                return 'believe_points';
            }
            if (str_ends_with($rt, 'BelievePointGiftInvite')) {
                return 'believe_points';
            }
            if (str_contains($rt, 'CareAllianceDonation')) {
                return 'campaign';
            }
            if (str_contains($rt, 'FundMeDonation')) {
                return 'fundme';
            }
            if (str_ends_with($rt, 'ServiceOrder')) {
                return 'servicehub';
            }
            if ($base === 'Order') {
                if ($this->isOrgMarketingCommsProductType($effectiveType)) {
                    return 'organization_subscription';
                }
                if ($this->isGiftCardPurchaseContext($t)) {
                    return 'gift_card';
                }

                return 'marketplace';
            }
            if (str_ends_with($rt, 'Enrollment')) {
                return 'connection_hub';
            }
            if ($base === 'Plan') {
                return 'supporter_subscription';
            }
            if (str_ends_with($rt, 'GiftCard')) {
                return 'gift_card';
            }
            if (str_ends_with($rt, 'Raffle')) {
                return 'marketplace';
            }
            if (str_ends_with($rt, 'MerchantHubOfferRedemption') || str_ends_with($rt, 'MerchantHubReferralReward')) {
                return 'merchant_hub';
            }
            if (str_ends_with($rt, 'Donation')) {
                return 'donation';
            }
        }

        if (! empty($meta['subscription_id']) || str_contains(strtolower((string) ($meta['plan_name'] ?? '')), 'subscription')) {
            if (! empty($meta['merchant_id'])) {
                return 'merchant_subscription';
            }
            if (! empty($meta['wallet_plan_id']) || ! empty($meta['plan_id'])) {
                return 'supporter_subscription';
            }

            return 'organization_subscription';
        }

        if (! empty($meta['wallet_plan_id'])) {
            return 'supporter_subscription';
        }

        // Platform plan checkout (PlansController success): type may be `purchase` with plan_id + plan_name.
        if ($type === 'purchase' && ! empty($meta['plan_id']) && ! empty($meta['plan_name']) && empty($meta['order_id'])) {
            return 'supporter_subscription';
        }

        if (in_array($type, ['plan_subscription', 'wallet_subscription', 'kyc_fee'], true)) {
            return 'supporter_subscription';
        }

        if ($type === 'adjustment' || ($meta['adjustment_reason'] ?? null)) {
            return 'adjustment';
        }

        $moduleFromWalletType = $this->resolveModuleSlugFromWalletType($effectiveType, $t);
        if ($moduleFromWalletType !== null) {
            return $moduleFromWalletType;
        }

        // Ledger report: selling / checkout lines (Stripe settlement deposits often match this even without order_id in meta).
        $subtotal = isset($ledgerReport['subtotal_amount']) ? (float) $ledgerReport['subtotal_amount'] : 0.0;
        $supplierLabel = isset($ledgerReport['supplier_name']) ? trim((string) $ledgerReport['supplier_name']) : '';
        $supplierPayout = isset($ledgerReport['supplier_payout']) && $ledgerReport['supplier_payout'] !== null && $ledgerReport['supplier_payout'] !== ''
            ? (float) $ledgerReport['supplier_payout']
            : null;
        $hasSellingReport = $subtotal > 0
            || $supplierLabel !== ''
            || ($supplierPayout !== null && abs($supplierPayout) > 0.00001);

        if ($type === 'deposit') {
            $pm = strtolower((string) ($t->payment_method ?? ''));
            if (str_contains($pm, 'donat') || ! empty($meta['donation_id']) || ! empty($meta['care_alliance_donation_id'])) {
                return 'donation';
            }
            // Manual org wallet top-up (not a Stripe sale).
            if (! empty($meta['deposited_by']) && ! empty($meta['organization_id'])) {
                return 'wallet';
            }
            if (! empty($meta['order_id'])) {
                return 'marketplace';
            }
            if ($hasSellingReport) {
                return 'marketplace';
            }
            if (in_array($pm, ['stripe', 'card', 'link', 'us_bank_account', 'apple_pay', 'google_pay', 'klarna', 'affirm'], true)
                || ! empty($meta['stripe_session_id'])
                || ! empty($meta['payment_intent'])
                || ! empty($meta['stripe_charge_id'])
                || ! empty($meta['stripe_payment_intent'])) {
                return 'marketplace';
            }
            if ($pm === 'wallet') {
                return 'wallet';
            }

            // Ambiguous deposit: treat as internal wallet movement, not a manual accounting adjustment.
            return 'wallet';
        }

        if ($type === 'transfer_in') {
            return 'payout';
        }

        if ($hasSellingReport && empty($meta['donation_id'])) {
            return 'marketplace';
        }

        if (strtolower((string) ($t->payment_method ?? '')) === 'wallet' && ! empty($meta['organization_id'])) {
            return 'wallet';
        }

        // Last resort: prefer Marketplace over "adjustment" for unknown commerce-adjacent rows.
        return 'marketplace';
    }

    private function resolveTransactionType(
        Transaction $t,
        string $module,
        string $sourceType,
        ?string $donationPerspective,
    ): string {
        if ($t->type === 'refund') {
            $scope = is_array($t->meta) ? (string) ($t->meta['refund_scope'] ?? '') : '';

            return match ($scope) {
                'shipping' => 'shipping_refund',
                'tax' => 'tax_refund',
                default => 'payment_refund',
            };
        }

        if (in_array($t->type, ['withdrawal', 'transfer_out'], true)) {
            $meta = is_array($t->meta) ? $t->meta : '';
            $isMerchant = ! empty($meta['merchant_id']) || (! empty($meta['payout_recipient']) && $meta['payout_recipient'] === 'merchant');

            return $isMerchant ? 'merchant_payout' : 'organization_payout';
        }

        $walletType = $this->effectiveWalletProductType($t);
        if ($walletType === 'newsletter_pro_targeting_lifetime') {
            return 'newsletter_pro_targeting_purchase';
        }
        if ($walletType === 'sms_purchase') {
            return 'sms_credit_purchase';
        }
        if ($walletType === 'email_purchase') {
            return 'email_credit_purchase';
        }
        if ($walletType === 'gift_card_purchase') {
            return 'gift_card_purchase';
        }

        return match ($module) {
            'donation' => $this->donationTransactionType($t, $donationPerspective),
            'fundme' => 'fundme_contribution',
            'campaign' => 'campaign_contribution',
            'believe_points', 'reward' => $this->believePointsTransactionType($t),
            'gift_card' => 'gift_card_purchase',
            'marketplace' => 'marketplace_sale',
            'servicehub' => 'service_payment',
            'connection_hub', 'course' => $this->connectionHubTransactionType($t),
            'merchant_hub' => 'merchant_hub_sale',
            'supporter_subscription' => 'supporter_subscription_paid',
            'organization_subscription' => 'organization_subscription_paid',
            'merchant_subscription' => 'merchant_subscription_paid',
            'wallet' => 'wallet_deposit',
            'payout' => 'organization_payout',
            'refund' => 'payment_refund',
            'adjustment' => 'adjustment',
            default => $sourceType !== 'ledger_unclassified' ? str_replace('-', '_', $sourceType) : str_replace('-', '_', (string) ($t->type ?? 'ledger')),
        };
    }

    private function believePointsTransactionType(Transaction $t): string
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        if ($t->ledger_type === UnifiedLedgerType::BRP
            || ($meta['source'] ?? '') === 'believe_points_purchase_brp') {
            return 'brp_participation_reward';
        }
        if (($meta['source'] ?? '') === 'bp_redemption' || $t->type === 'bp_redemption') {
            return 'bp_redemption';
        }
        if (($meta['source'] ?? '') === 'bridge_wallet_transfer' || $t->type === 'bridge_wallet_transfer') {
            return 'bridge_wallet_transfer';
        }
        if (($meta['source'] ?? '') === 'believe_points_wallet_transfer'
            || $t->type === 'believe_points_wallet_transfer') {
            return 'bp_redemption';
        }
        if (($meta['source'] ?? '') === 'bp_settlement' || $t->type === 'bp_settlement') {
            return 'bp_settlement';
        }
        if ($this->isBelievePointGiftTransaction($t)) {
            return match ((string) $t->type) {
                'bp_gift_sent' => 'bp_gift_sent',
                'bp_gift_claimed' => 'bp_gift_claimed',
                'bp_gift_cancelled' => 'bp_gift_cancelled',
                'bp_gift_expired' => 'bp_gift_expired',
                'bp_gift_refunded' => 'bp_gift_refunded',
                default => 'bp_gift_sent',
            };
        }

        return 'believe_points_purchase';
    }

    private function isBelievePointGiftTransaction(Transaction $t): bool
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        $source = (string) ($meta['source'] ?? '');
        $txnId = (string) $t->transaction_id;

        return in_array($t->type, [
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
        ], true)
            || in_array($source, [
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
            ], true)
            || str_starts_with($txnId, 'bp_gift');
    }

    private function donationTransactionType(Transaction $t, ?string $donationPerspective): string
    {
        if (in_array($donationPerspective, ['donor', 'campaign'], true)) {
            return 'donation_payment';
        }
        if ($t->type === 'purchase' && ($t->payment_method ?? '') === 'donation') {
            return 'donation_payment';
        }
        $meta = is_array($t->meta) ? $t->meta : [];
        if (($meta['ledger_role'] ?? '') === 'donor_payment') {
            return 'donation_payment';
        }

        return 'donation_received';
    }

    private function resolveDirection(Transaction $t, string $module): string
    {
        if ($t->type === 'refund' || in_array($t->type, ['withdrawal', 'transfer_out'], true)) {
            return 'debit';
        }
        if ($module === 'payout') {
            return 'debit';
        }

        // Spends paid with Believe Points leave the supporter wallet (gift cards, marketplace, courses, …).
        $walletAmount = UnifiedLedgerWalletAmountResolver::resolve($t);
        if ($walletAmount !== null && $walletAmount < 0) {
            return 'debit';
        }

        return 'credit';
    }

    /**
     * @param  array<string, mixed>  $ledgerReport
     * @param  array{related_kind: string, related_label: string, related_display_name: string}  $related
     * @return array{from_type: string, from_name: string|null, from_email: string|null, from_id: int|null, to_type: string, to_name: string|null, to_email: string|null, to_id: int|null}
     */
    private function resolveParties(
        Transaction $t,
        ?array $donationPayload,
        ?string $donationPerspective,
        array $ledgerReport,
        array $related,
        string $module,
    ): array {
        $orgId = $ledgerReport['organization_id'] ?? null;
        $orgName = $ledgerReport['organization_name'] ?? null;
        $orgEmail = null;
        if ($orgId) {
            $orgEmail = Organization::query()->whereKey($orgId)->value('email');
        }

        $walletUser = $t->relationLoaded('user') ? $t->user : null;
        if (! $walletUser && $t->user_id) {
            $walletUser = User::query()->find($t->user_id, ['id', 'name', 'email']);
        }

        $donorUser = null;
        if ($donationPayload && ($donationPayload['kind'] ?? '') === 'donation' && ! empty($donationPayload['donor_user_id'])) {
            $donorUser = User::query()->find((int) $donationPayload['donor_user_id'], ['id', 'name', 'email']);
        }

        $defaultFrom = [
            'from_type' => 'supporter',
            'from_name' => $walletUser?->name,
            'from_email' => $walletUser?->email,
            'from_id' => $walletUser?->id,
        ];
        $defaultTo = [
            'to_type' => 'organization',
            'to_name' => $orgName,
            'to_email' => $orgEmail,
            'to_id' => $orgId,
        ];

        if ($module === 'merchant_hub' || ($module === 'marketplace' && str_contains(strtolower($related['related_kind'] ?? ''), 'merchant'))) {
            $defaultTo['to_type'] = 'merchant';
        }

        // Platform / wallet plans purchased by a supporter (not nonprofit org subscription billing).
        if ($module === 'supporter_subscription') {
            $payer = $walletUser;
            $defaultFrom = [
                'from_type' => 'supporter',
                'from_name' => $payer?->name,
                'from_email' => $payer?->email,
                'from_id' => $payer?->id,
            ];
            $defaultTo = [
                'to_type' => 'platform',
                'to_name' => 'BIU Platform',
                'to_email' => null,
                'to_id' => null,
            ];
        }

        if ($module === 'organization_subscription' || $module === 'merchant_subscription') {
            $payer = $walletUser;
            $defaultFrom = [
                'from_type' => $module === 'merchant_subscription' ? 'merchant' : 'organization',
                'from_name' => $payer?->name ?? $orgName,
                'from_email' => $payer?->email ?? $orgEmail,
                'from_id' => $payer?->id,
            ];
            $defaultTo = [
                'to_type' => 'platform',
                'to_name' => 'BIU Platform',
                'to_email' => null,
                'to_id' => null,
            ];
        }

        if ($module === 'payout') {
            $defaultFrom = [
                'from_type' => 'platform',
                'from_name' => 'BIU Platform',
                'from_email' => null,
                'from_id' => null,
            ];
            $defaultTo = [
                'to_type' => $orgId ? 'organization' : 'merchant',
                'to_name' => $orgName ?? $walletUser?->name,
                'to_email' => $orgEmail ?? $walletUser?->email,
                'to_id' => $orgId ?? $walletUser?->id,
            ];
        }

        if ($module === 'refund') {
            $defaultFrom = [
                'from_type' => 'platform',
                'from_name' => 'BIU Platform',
                'from_email' => null,
                'from_id' => null,
            ];
            $defaultTo = [
                'to_type' => 'buyer',
                'to_name' => $walletUser?->name,
                'to_email' => $walletUser?->email,
                'to_id' => $walletUser?->id,
            ];
        }

        if ($module === 'campaign' && $donationPerspective === 'campaign') {
            $defaultFrom['from_type'] = 'supporter';
            $defaultTo['to_type'] = 'organization';
        }

        if ($donationPayload && ($donationPayload['kind'] ?? '') === 'donation' && ! ($donationPayload['missing'] ?? false)) {
            if ($donorUser) {
                $defaultFrom['from_name'] = $donorUser->name;
                $defaultFrom['from_email'] = $donorUser->email;
                $defaultFrom['from_id'] = $donorUser->id;
            }
            if ($orgName === null && ! empty($donationPayload['organization_name'])) {
                $defaultTo['to_name'] = (string) $donationPayload['organization_name'];
            }
        }

        if (in_array($donationPerspective, ['recipient_direct', 'recipient_split', 'alliance_fee'], true)) {
            $defaultTo['to_name'] = $orgName ?? $defaultTo['to_name'];
            $defaultTo['to_type'] = $donationPerspective === 'alliance_fee' ? 'organization' : 'organization';
        }

        if ($donationPerspective === 'donor' || $donationPerspective === 'campaign') {
            $defaultFrom['from_type'] = 'supporter';
            if ($donorUser) {
                $defaultFrom['from_name'] = $donorUser->name;
                $defaultFrom['from_email'] = $donorUser->email;
                $defaultFrom['from_id'] = $donorUser->id;
            }
            $defaultTo['to_type'] = 'organization';
            $defaultTo['to_name'] = $orgName ?? $related['related_display_name'] ?? $defaultTo['to_name'];
        }

        if ($module === 'marketplace' || $module === 'gift_card') {
            if ($walletUser) {
                $payerOrg = Organization::forAuthUser($walletUser);
                if ($payerOrg) {
                    $defaultFrom['from_type'] = 'organization';
                    $defaultFrom['from_name'] = $payerOrg->name;
                    $defaultFrom['from_email'] = $payerOrg->email ?: $walletUser->email;
                    $defaultFrom['from_id'] = $payerOrg->id;
                }
            }
            if ($orgName !== null && $orgName !== '') {
                $defaultTo['to_type'] = 'organization';
                $defaultTo['to_name'] = $orgName;
                $defaultTo['to_id'] = $orgId ?? $defaultTo['to_id'];
                $defaultTo['to_email'] = $orgEmail ?? $defaultTo['to_email'];
            }
        }

        if (($module === 'believe_points' || $module === 'reward') && $walletUser) {
            $rowMeta = is_array($t->meta) ? $t->meta : [];
            if ($this->isBelievePointGiftTransaction($t)) {
                return $this->resolveBelievePointGiftParties($t, $walletUser, $rowMeta);
            }
            $isWalletTransfer = in_array($rowMeta['source'] ?? '', ['bp_redemption', 'bridge_wallet_transfer', 'believe_points_wallet_transfer'], true)
                || in_array($t->type, ['bp_redemption', 'bridge_wallet_transfer', 'believe_points_wallet_transfer'], true);
            $isBridgeMoneyTransfer = ($rowMeta['source'] ?? '') === 'bridge_wallet_transfer'
                || $t->type === 'bridge_wallet_transfer';

            $defaultFrom = [
                'from_type' => 'buyer',
                'from_name' => $walletUser->name,
                'from_email' => $walletUser->email,
                'from_id' => (int) $walletUser->id,
            ];
            $defaultTo = $isWalletTransfer && ! $isBridgeMoneyTransfer
                ? [
                    'to_type' => 'platform',
                    'to_name' => 'BIU Platform',
                    'to_email' => null,
                    'to_id' => null,
                ]
                : ($isBridgeMoneyTransfer
                    ? [
                        'to_type' => 'wallet',
                        'to_name' => 'Believe Bridge wallet',
                        'to_email' => $walletUser->email,
                        'to_id' => (int) $walletUser->id,
                    ]
                    : [
                        'to_type' => '',
                        'to_name' => null,
                        'to_email' => null,
                        'to_id' => null,
                    ]);
        }

        $rowMeta = is_array($t->meta) ? $t->meta : [];
        if ($module === 'refund' && $walletUser && ($rowMeta['source'] ?? '') === 'believe_points_purchase_refund') {
            $party = $this->resolveBelievePointsPayerFromUser($walletUser);
            $defaultFrom = [
                'from_type' => 'platform',
                'from_name' => 'BIU Platform',
                'from_email' => null,
                'from_id' => null,
            ];
            $defaultTo = [
                'to_type' => $party['from_type'],
                'to_name' => $party['from_name'],
                'to_email' => $party['from_email'],
                'to_id' => $party['from_id'],
            ];
        }

        if ($module === 'refund' && $walletUser && ($rowMeta['source'] ?? '') === 'believe_points_wallet_transfer') {
            $defaultFrom = [
                'from_type' => 'platform',
                'from_name' => 'BIU Platform',
                'from_email' => null,
                'from_id' => null,
            ];
            $defaultTo = [
                'to_type' => 'buyer',
                'to_name' => $walletUser->name,
                'to_email' => $walletUser->email,
                'to_id' => (int) $walletUser->id,
            ];
        }

        return array_merge($defaultFrom, $defaultTo);
    }

    /**
     * Prefer gift lifecycle status (pending / claimed / cancelled / expired) for Gift BP rows.
     */
    private function resolveDisplayStatus(Transaction $t): string
    {
        if (! $this->isBelievePointGiftTransaction($t)) {
            return (string) $t->status;
        }

        $meta = is_array($t->meta) ? $t->meta : [];
        $giftStatus = strtolower(trim((string) ($meta['gift_status'] ?? '')));
        if (in_array($giftStatus, ['pending', 'claimed', 'cancelled', 'expired'], true)) {
            return $giftStatus;
        }

        return match ((string) $t->type) {
            'bp_gift_sent', 'bp_gift_hold' => $t->status === Transaction::STATUS_PENDING ? 'pending' : 'claimed',
            'bp_gift_claimed', 'bp_gift_claim' => 'claimed',
            'bp_gift_cancelled' => 'cancelled',
            'bp_gift_expired' => 'expired',
            'bp_gift_refunded', 'bp_gift_hold_refund' => str_contains(strtolower((string) ($meta['refund_reason'] ?? '')), 'expir')
                ? 'expired'
                : 'cancelled',
            'bp_gift_email_changed' => 'pending',
            default => (string) $t->status,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{from_type: string, from_name: string|null, from_email: string|null, from_id: int|null, to_type: string, to_name: string|null, to_email: string|null, to_id: int|null}
     */
    private function resolveBelievePointGiftParties(Transaction $t, User $walletUser, array $meta): array
    {
        $senderId = isset($meta['sender_id']) ? (int) $meta['sender_id'] : null;
        $senderName = isset($meta['sender_name']) && $meta['sender_name'] !== ''
            ? (string) $meta['sender_name']
            : (isset($meta['from_name']) && $meta['from_name'] !== '' ? (string) $meta['from_name'] : null);
        $recipientId = isset($meta['recipient_id']) ? (int) $meta['recipient_id'] : (isset($meta['to_id']) ? (int) $meta['to_id'] : null);
        $recipientName = isset($meta['recipient_name']) && $meta['recipient_name'] !== ''
            ? (string) $meta['recipient_name']
            : (isset($meta['to_name']) && $meta['to_name'] !== '' ? (string) $meta['to_name'] : null);
        $recipientEmail = isset($meta['recipient_email']) && $meta['recipient_email'] !== ''
            ? (string) $meta['recipient_email']
            : null;

        if ($senderName === null && $senderId && $senderId === (int) $walletUser->id) {
            $senderName = $walletUser->name;
        }
        if ($senderName === null && $senderId) {
            $sender = User::query()->find($senderId, ['id', 'name', 'email']);
            $senderName = $sender?->name;
        }
        if ($senderName === null && ($meta['source'] ?? '') === 'bp_gift_hold') {
            $senderName = $walletUser->name;
            $senderId = (int) $walletUser->id;
        }

        if ($recipientName === null || $recipientName === '') {
            $recipientName = $recipientEmail;
        }
        if (($recipientName === null || $recipientName === '') && $recipientId) {
            $recipient = User::query()->find($recipientId, ['id', 'name', 'email']);
            $recipientName = $recipient?->name;
            $recipientEmail = $recipientEmail ?? $recipient?->email;
        }

        // Claim row is stored on the recipient wallet — still show Sender → Recipient.
        if ($senderName === null && isset($meta['from_name'])) {
            $senderName = (string) $meta['from_name'];
        }
        if (($recipientName === null || $recipientName === '') && in_array($t->type, ['bp_gift_claim', 'bp_gift_claimed'], true)) {
            $recipientName = $walletUser->name;
            $recipientId = (int) $walletUser->id;
            $recipientEmail = $walletUser->email;
        }

        return [
            'from_type' => 'supporter',
            'from_name' => $senderName,
            'from_email' => $senderId && $senderId === (int) $walletUser->id ? $walletUser->email : null,
            'from_id' => $senderId,
            'to_type' => 'supporter',
            'to_name' => $recipientName,
            'to_email' => $recipientEmail,
            'to_id' => $recipientId ?: null,
        ];
    }

    /**
     * Believe Points purchases are billed to a user; if they act as an org (owner or board-linked), show the org as payer.
     *
     * @return array{from_type: string, from_name: string|null, from_email: string|null, from_id: int|null}
     */
    private function resolveBelievePointsPayerFromUser(User $walletUser): array
    {
        $org = Organization::forAuthUser($walletUser);
        if ($org) {
            return [
                'from_type' => 'organization',
                'from_name' => $org->name,
                'from_email' => $org->email ?: $walletUser->email,
                'from_id' => (int) $org->id,
            ];
        }

        return [
            'from_type' => 'buyer',
            'from_name' => $walletUser->name,
            'from_email' => $walletUser->email,
            'from_id' => (int) $walletUser->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $ledgerReport
     * @param  array{from_type: string, from_name: string|null, from_email: string|null, from_id: int|null, to_type: string, to_name: string|null, to_email: string|null, to_id: int|null}  $parties
     */
    private function resolveOrganizationName(array $ledgerReport, array $parties): ?string
    {
        $name = isset($ledgerReport['organization_name']) ? trim((string) $ledgerReport['organization_name']) : '';
        if ($name !== '') {
            return $name;
        }

        if (($parties['to_type'] ?? '') === 'organization' && ! empty($parties['to_name'])) {
            return trim((string) $parties['to_name']);
        }

        if (($parties['from_type'] ?? '') === 'organization' && ! empty($parties['from_name'])) {
            return trim((string) $parties['from_name']);
        }

        return null;
    }

    /**
     * @param  array{from_type: string, from_name: string|null, from_email: string|null, from_id: int|null, to_type: string, to_name: string|null, to_email: string|null, to_id: int|null}  $parties
     * @param  array<string, mixed>|null  $donationPayload
     * @return array{name: string|null, email: string|null}
     */
    private function resolveSubscriberContact(array $parties, Transaction $t, ?array $donationPayload): array
    {
        $fromType = strtolower((string) ($parties['from_type'] ?? ''));
        $toType = strtolower((string) ($parties['to_type'] ?? ''));

        if (in_array($fromType, ['supporter', 'buyer', 'organization', 'merchant'], true)) {
            $name = isset($parties['from_name']) && $parties['from_name'] !== '' ? (string) $parties['from_name'] : null;
            $email = isset($parties['from_email']) && $parties['from_email'] !== '' ? (string) $parties['from_email'] : null;
            if ($name !== null || $email !== null) {
                return ['name' => $name, 'email' => $email];
            }
        }

        if (in_array($toType, ['supporter', 'buyer'], true)) {
            $name = isset($parties['to_name']) && $parties['to_name'] !== '' ? (string) $parties['to_name'] : null;
            $email = isset($parties['to_email']) && $parties['to_email'] !== '' ? (string) $parties['to_email'] : null;
            if ($name !== null || $email !== null) {
                return ['name' => $name, 'email' => $email];
            }
        }

        if ($donationPayload !== null && ! empty($donationPayload['donor_user_id'])) {
            $donor = User::query()->find((int) $donationPayload['donor_user_id'], ['name', 'email']);
            if ($donor !== null) {
                return [
                    'name' => $donor->name ?: null,
                    'email' => $donor->email ?: null,
                ];
            }
        }

        $meta = is_array($t->meta) ? $t->meta : [];
        foreach ([['donor_name', 'donor_email'], ['customer_name', 'customer_email'], ['payer_name', 'payer_email']] as [$nameKey, $emailKey]) {
            $name = isset($meta[$nameKey]) && is_string($meta[$nameKey]) ? trim($meta[$nameKey]) : '';
            $email = isset($meta[$emailKey]) && is_string($meta[$emailKey]) ? trim($meta[$emailKey]) : '';
            if ($name !== '' || $email !== '') {
                return [
                    'name' => $name !== '' ? $name : null,
                    'email' => $email !== '' ? $email : null,
                ];
            }
        }

        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';
        if (str_ends_with($rt, 'FundMeDonation') && $t->related_id) {
            $donation = FundMeDonation::query()->find((int) $t->related_id, ['donor_name', 'donor_email', 'anonymous']);
            if ($donation !== null && ! $donation->anonymous) {
                $name = trim((string) ($donation->donor_name ?? ''));
                $email = trim((string) ($donation->donor_email ?? ''));
                if ($name !== '' || $email !== '') {
                    return [
                        'name' => $name !== '' ? $name : null,
                        'email' => $email !== '' ? $email : null,
                    ];
                }
            }
        }

        if (! empty($parties['from_name']) || ! empty($parties['from_email'])) {
            return [
                'name' => ! empty($parties['from_name']) ? (string) $parties['from_name'] : null,
                'email' => ! empty($parties['from_email']) ? (string) $parties['from_email'] : null,
            ];
        }

        $walletUser = $t->relationLoaded('user') ? $t->user : null;
        if (! $walletUser && $t->user_id) {
            $walletUser = User::query()->find($t->user_id, ['id', 'name', 'email']);
        }
        if ($walletUser !== null) {
            return [
                'name' => $walletUser->name ?: null,
                'email' => $walletUser->email ?: null,
            ];
        }

        return ['name' => null, 'email' => null];
    }

    /**
     * @param  array{from_type: string, from_name: string|null, from_email: string|null, from_id: int|null, to_type: string, to_name: string|null, to_email: string|null, to_id: int|null}  $parties
     */
    private function resolveMerchantName(Transaction $t, string $module, array $parties): ?string
    {
        if (($parties['from_type'] ?? '') === 'merchant' && ! empty($parties['from_name'])) {
            return (string) $parties['from_name'];
        }

        if (($parties['to_type'] ?? '') === 'merchant' && ! empty($parties['to_name'])) {
            return (string) $parties['to_name'];
        }

        $meta = is_array($t->meta) ? $t->meta : [];

        if (! empty($meta['merchant_name']) && is_string($meta['merchant_name'])) {
            $name = trim($meta['merchant_name']);

            return $name !== '' ? $name : null;
        }

        if (! empty($meta['merchant_id']) && is_numeric($meta['merchant_id'])) {
            $merchant = Merchant::query()->find((int) $meta['merchant_id'], ['name', 'business_name']);
            if ($merchant !== null) {
                $label = trim((string) ($merchant->business_name ?: $merchant->name));

                return $label !== '' ? $label : null;
            }
        }

        if (! in_array($module, ['merchant_hub', 'merchant_subscription'], true)) {
            return null;
        }

        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';
        if (str_ends_with($rt, 'MerchantHubOfferRedemption') && $t->related_id) {
            $redemption = MerchantHubOfferRedemption::query()
                ->with('offer.merchant:id,name')
                ->find((int) $t->related_id);
            $hubMerchant = $redemption?->offer?->merchant;
            if ($hubMerchant !== null && trim((string) $hubMerchant->name) !== '') {
                return trim((string) $hubMerchant->name);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $donationPayload
     * @param  array{related_kind: string, related_label: string, related_display_name: string}  $related
     */
    private function resolveCampaignName(
        Transaction $t,
        string $module,
        ?array $donationPayload,
        array $related,
    ): ?string {
        if ($donationPayload !== null) {
            if (($donationPayload['kind'] ?? '') === 'care_alliance_campaign') {
                $name = trim((string) ($donationPayload['campaign_name'] ?? ''));

                return $name !== '' ? $name : null;
            }

            $allianceName = trim((string) ($donationPayload['care_alliance_name'] ?? ''));
            if ($allianceName !== '' && in_array($module, ['donation', 'campaign'], true)) {
                return $allianceName;
            }
        }

        $meta = is_array($t->meta) ? $t->meta : [];
        foreach (['campaign_name', 'fundme_campaign_name', 'fundme_campaign_title', 'care_alliance_name'] as $key) {
            if (! empty($meta[$key]) && is_string($meta[$key])) {
                $value = trim($meta[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';

        if ((str_ends_with($rt, 'FundMeDonation') || $module === 'fundme') && $t->related_id) {
            $donation = FundMeDonation::query()->with('campaign:id,title')->find((int) $t->related_id);
            if ($donation?->campaign?->title) {
                return (string) $donation->campaign->title;
            }
        }

        if ((str_ends_with($rt, 'CareAllianceDonation') || $module === 'campaign') && $t->related_id) {
            $donation = CareAllianceDonation::query()->with('campaign:id,name')->find((int) $t->related_id);
            if ($donation?->campaign?->name) {
                return (string) $donation->campaign->name;
            }
        }

        if (in_array($module, ['campaign', 'fundme'], true)) {
            $label = trim((string) ($related['related_label'] ?? ''));

            return $label !== '' && $label !== '—' ? $label : null;
        }

        return null;
    }

    private function resolveEventName(Transaction $t): ?string
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        foreach (['event_name', 'event_title', 'course_name'] as $key) {
            if (! empty($meta[$key]) && is_string($meta[$key])) {
                $value = trim($meta[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        // Gift card brand-aware fallback when meta.event_name is missing.
        if ($this->isGiftCardPurchaseContext($t)) {
            $brand = trim((string) ($meta['brand'] ?? $meta['brand_name'] ?? ''));
            if ($brand !== '') {
                return 'Purchased '.$brand.' Gift Card';
            }

            return 'Transfer BP to Gift Card Module';
        }

        if (! empty($meta['course_id']) && is_numeric($meta['course_id'])) {
            $courseName = \App\Models\Course::query()->whereKey((int) $meta['course_id'])->value('name');
            if (is_string($courseName) && trim($courseName) !== '') {
                return trim($courseName);
            }
        }

        if (! empty($meta['event_id']) && is_numeric($meta['event_id'])) {
            $name = Event::query()->whereKey((int) $meta['event_id'])->value('name');
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        $rt = $t->related_type ? ltrim((string) $t->related_type, '\\') : '';
        if (($rt === Event::class || str_ends_with($rt, 'Event')) && $t->related_id) {
            $name = Event::query()->whereKey((int) $t->related_id)->value('name');
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    private function resolveConnectionHubType(Transaction $t): ?string
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        foreach (['connection_hub_type', 'course_type'] as $key) {
            $raw = strtolower(trim((string) ($meta[$key] ?? '')));
            if ($raw !== '' && in_array($raw, ConnectionHubType::VALUES, true)) {
                return $raw;
            }
        }

        if (! empty($meta['course_id']) && is_numeric($meta['course_id'])) {
            $type = \App\Models\Course::query()->whereKey((int) $meta['course_id'])->value('type');
            if (is_string($type)) {
                $raw = strtolower(trim($type));
                if ($raw !== '' && in_array($raw, ConnectionHubType::VALUES, true)) {
                    return $raw;
                }
            }
        }

        return null;
    }

    private function connectionHubTransactionType(Transaction $t): string
    {
        return match ($this->resolveConnectionHubType($t)) {
            ConnectionHubType::EVENTS => 'event_registration',
            ConnectionHubType::LEARNING => 'course_enrollment',
            ConnectionHubType::COMPANION => 'companion_enrollment',
            ConnectionHubType::EARNING => 'earning_enrollment',
            default => 'connection_hub_enrollment',
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $ledgerReport
     * @return array{subtotal: float|null, sales_tax: float|null, shipping: float|null, gross: float, stripe_fee: float, bridge_fee: float, biu_fee: float, split: float, refund: float, net: float|null, processor_fee: float}
     */
    private function resolveAmounts(Transaction $t, array $meta, array $ledgerReport): array
    {
        $classified = UnifiedLedgerClassificationService::classify($t);
        $ledgerType = $classified['ledger_type'] ?? UnifiedLedgerType::MONEY;
        $currency = strtoupper((string) ($t->currency ?? ''));

        if ($ledgerType === UnifiedLedgerType::BRP || $currency === 'BRP') {
            $brpAmount = round(abs((float) $t->amount), 2);

            return [
                'subtotal' => null,
                'sales_tax' => null,
                'shipping' => null,
                'gross' => 0,
                'stripe_fee' => 0,
                'bridge_fee' => 0,
                'biu_fee' => 0,
                'split' => 0,
                'refund' => 0,
                'net' => $brpAmount > 0 ? $brpAmount : null,
                'processor_fee' => 0,
            ];
        }

        // Settlement is a status change inside General Module — not a buy/sell.
        $metaSource = (string) ($meta['source'] ?? '');
        if ($t->type === 'bp_settlement' || $metaSource === 'bp_settlement') {
            return [
                'subtotal' => null,
                'sales_tax' => null,
                'shipping' => null,
                'gross' => 0,
                'stripe_fee' => 0,
                'bridge_fee' => 0,
                'biu_fee' => 0,
                'split' => 0,
                'refund' => 0,
                'net' => null,
                'processor_fee' => 0,
            ];
        }

        $gross = (float) ($ledgerReport['gross_amount'] ?? 0);
        $stripe = (float) ($ledgerReport['stripe_fee'] ?? 0);
        $bridge = (float) ($ledgerReport['bridge_fee'] ?? 0);
        $biu = (float) ($ledgerReport['biu_fee'] ?? 0);
        $split = (float) ($ledgerReport['split_deduction'] ?? 0);
        $refund = (float) ($ledgerReport['refund_amount'] ?? 0);
        $net = $ledgerReport['net_to_organization'] ?? null;
        if ($net !== null) {
            $net = (float) $net;
        }

        $subtotal = $this->metaFloat($meta, ['subtotal_amount', 'subtotal', 'line_subtotal']);
        $tax = $this->metaFloat($meta, ['sales_tax_amount', 'sales_tax', 'tax_amount', 'tax_total']);
        $shipping = $this->metaFloat($meta, ['shipping_amount', 'shipping', 'shipping_total']);

        $lrSub = $ledgerReport['subtotal_amount'] ?? null;
        if ($lrSub !== null && is_numeric($lrSub) && (float) $lrSub > 0) {
            $subtotal = round((float) $lrSub, 2);
        }
        $lrTax = $ledgerReport['sales_tax_amount'] ?? null;
        if ($lrTax !== null && is_numeric($lrTax) && (float) $lrTax > 0) {
            $tax = round((float) $lrTax, 2);
        }
        $lrShip = $ledgerReport['shipping_amount'] ?? null;
        if ($lrShip !== null && is_numeric($lrShip) && (float) $lrShip > 0) {
            $shipping = round((float) $lrShip, 2);
        }

        if ($subtotal <= 0 && $gross > 0) {
            $subtotal = $gross - $tax - $shipping;
            if ($subtotal < 0) {
                $subtotal = $gross;
            }
        }

        $processorFee = $stripe + $bridge;

        return [
            'subtotal' => $subtotal > 0 ? round($subtotal, 2) : null,
            'sales_tax' => $tax > 0 ? round($tax, 2) : null,
            'shipping' => $shipping > 0 ? round($shipping, 2) : null,
            'gross' => round($gross, 2),
            'stripe_fee' => round($stripe, 2),
            'bridge_fee' => round($bridge, 2),
            'biu_fee' => round($biu, 2),
            'split' => round($split, 2),
            'refund' => round($refund, 2),
            'net' => $net !== null ? round($net, 2) : null,
            'processor_fee' => round($processorFee, 2),
        ];
    }

    private function metaFloat(array $meta, array $keys): float
    {
        foreach ($keys as $k) {
            if (! array_key_exists($k, $meta) || $meta[$k] === null || $meta[$k] === '') {
                continue;
            }
            $v = $meta[$k];
            if (is_numeric($v)) {
                return round((float) $v, 2);
            }
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $donationPayload
     */
    private function resolveProvider(Transaction $t, array $meta, array $ledgerReport, ?array $donationPayload = null): string
    {
        if ($donationPayload !== null && ($donationPayload['kind'] ?? '') === 'donation') {
            $dpm = strtolower((string) ($donationPayload['payment_method'] ?? ''));
            if ($dpm === 'believe_points' || str_contains($dpm, 'believe_point')) {
                return 'points';
            }
        }

        $pm = strtolower((string) ($t->payment_method ?? ''));
        if ($pm === 'believe_points' || str_contains($pm, 'point') || str_contains($pm, 'believe_point')) {
            return 'points';
        }
        $metaDonationPm = strtolower((string) ($meta['donation_payment_method'] ?? ''));
        if ($metaDonationPm === 'believe_points' || str_contains($metaDonationPm, 'believe_point')) {
            return 'points';
        }
        if (str_starts_with((string) ($t->transaction_id ?? ''), 'believe_points_donation')) {
            return 'points';
        }
        if ((float) ($ledgerReport['bridge_fee'] ?? 0) > 0
            || ! empty($meta['bridge_transfer_id'])
            || ! empty($meta['bridge_payment_id'])
            || ! empty($meta['bridge_customer_id'])
            || ! empty($meta['bridge_virtual_account_id'])) {
            if (str_starts_with((string) ($t->transaction_id ?? ''), 'pi_')) {
                return 'stripe';
            }

            return 'bridge';
        }
        if (str_contains($pm, 'stripe') || str_contains($pm, 'card')
            || str_starts_with((string) ($t->transaction_id ?? ''), 'pi_')
            || str_starts_with((string) ($t->transaction_id ?? ''), 'ch_')) {
            return 'stripe';
        }
        if (! empty($meta['stripe_payment_intent']) || ! empty($meta['stripe_payment_intent_id'])) {
            return 'stripe';
        }

        return 'internal';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveExternalReference(Transaction $t, array $meta): string
    {
        foreach (['stripe_payment_intent', 'stripe_payment_intent_id', 'payment_intent', 'bridge_transfer_id', 'bridge_payment_id', 'paypal_order_id'] as $k) {
            if (! empty($meta[$k]) && is_string($meta[$k])) {
                return $meta[$k];
            }
        }
        $tid = (string) ($t->transaction_id ?? '');

        return $tid !== '' ? $tid : '—';
    }

    /**
     * @param  array<string, mixed>|null  $donationPayload
     * @param  array{related_kind: string, related_label: string, related_display_name: string}  $related
     */
    private function resolveRelatedRecordLabel(Transaction $t, ?array $donationPayload, array $related, string $sourceType): string
    {
        if ($donationPayload && ($donationPayload['missing'] ?? false)) {
            return 'Donation #'.($donationPayload['donation_id'] ?? '?').' (missing)';
        }
        if (($donationPayload['kind'] ?? '') === 'donation' && ! empty($donationPayload['id'])) {
            return 'Donation #'.$donationPayload['id'];
        }
        if (($donationPayload['kind'] ?? '') === 'care_alliance_campaign' && ! empty($donationPayload['id'])) {
            return 'Campaign Donation #'.$donationPayload['id'];
        }

        if ($sourceType === 'fundme_donation' && $t->related_id) {
            return 'FundMe Donation #'.$t->related_id;
        }
        if ($sourceType === 'order' && $t->related_id) {
            return 'Order #'.$t->related_id;
        }
        if ($sourceType === 'service_order' && $t->related_id) {
            return 'Service Order #'.$t->related_id;
        }
        if ($sourceType === 'believe_points_purchase' && $t->related_id) {
            return $t->type === 'refund'
                ? 'Believe Points refund #'.$t->related_id
                : 'Believe Points purchase #'.$t->related_id;
        }
        if ($sourceType === 'bp_redemption' && $t->related_id) {
            return $t->status === Transaction::STATUS_REFUND
                ? 'Transfer to Bridge Wallet refund #'.$t->related_id
                : 'Transfer to Bridge Wallet #'.$t->related_id;
        }
        if ($sourceType === 'bridge_wallet_transfer' && $t->related_id) {
            return $t->status === Transaction::STATUS_REFUND
                ? 'Bridge Wallet Transfer refund #'.$t->related_id
                : 'Bridge Wallet Transfer #'.$t->related_id;
        }
        if ($sourceType === 'believe_points_wallet_transfer' && $t->related_id) {
            return $t->status === Transaction::STATUS_REFUND
                ? 'Transfer to Bridge Wallet refund #'.$t->related_id
                : 'Transfer to Bridge Wallet #'.$t->related_id;
        }

        $label = trim((string) ($related['related_label'] ?? ''));
        if ($label !== '' && $label !== '—') {
            return $label;
        }

        if ($t->related_type && $t->related_id !== null && $t->related_id !== '') {
            $base = class_basename(ltrim((string) $t->related_type, '\\'));

            return $base.' #'.$t->related_id;
        }

        return '—';
    }

    /**
     * BP spends: From General Module → destination module (presentation audit trail).
     *
     * @param  array{from_type: string, from_name: string|null, from_email: string|null, from_id: int|null, to_type: string, to_name: string|null, to_email: string|null, to_id: int|null}  $parties
     * @return array{from_type: string, from_name: string|null, from_email: string|null, from_id: int|null, to_type: string, to_name: string|null, to_email: string|null, to_id: int|null}
     */
    private function applyGeneralModuleTransferParties(
        Transaction $t,
        string $module,
        ?string $connectionHubType,
        array $parties,
    ): array {
        $destination = $this->resolveBpSpendDestination($t, $module, $connectionHubType);
        if ($destination === null) {
            // Settlement stays inside General Module.
            if ($t->type === 'bp_settlement' || (($t->meta['source'] ?? '') === 'bp_settlement')) {
                return [
                    'from_type' => 'module',
                    'from_name' => UnifiedLedgerBpModule::generalLabel(),
                    'from_email' => null,
                    'from_id' => null,
                    'to_type' => 'module',
                    'to_name' => UnifiedLedgerBpModule::generalLabel(),
                    'to_email' => null,
                    'to_id' => null,
                ];
            }

            return $parties;
        }

        return [
            'from_type' => 'module',
            'from_name' => UnifiedLedgerBpModule::generalLabel(),
            'from_email' => null,
            'from_id' => null,
            'to_type' => 'module',
            'to_name' => UnifiedLedgerBpModule::destinationLabel($destination),
            'to_email' => null,
            'to_id' => null,
        ];
    }

    private function resolveBpTransferEventName(Transaction $t, string $module, ?string $connectionHubType): ?string
    {
        $meta = is_array($t->meta) ? $t->meta : [];
        if (! empty($meta['event_name']) && is_string($meta['event_name'])) {
            $existing = trim($meta['event_name']);
            // Prefer stored transfer / settlement wording; upgrade legacy gift labels later via brand.
            if ($existing !== '' && (
                str_starts_with($existing, 'Transfer')
                || $existing === 'BP Settlement'
                || str_starts_with($existing, 'Gift ')
                || $existing === 'BP Purchase'
                || str_starts_with($existing, 'Purchased')
            )) {
                return $existing;
            }
        }

        if ($t->type === 'bp_settlement' || ($meta['source'] ?? '') === 'bp_settlement') {
            return 'BP Settlement';
        }

        $destination = $this->resolveBpSpendDestination($t, $module, $connectionHubType);
        if ($destination === null) {
            return null;
        }

        if ($destination === UnifiedLedgerBpModule::GIFT_CARD) {
            $brand = trim((string) ($meta['brand'] ?? $meta['brand_name'] ?? ''));
            if ($brand !== '') {
                return 'Purchased '.$brand.' Gift Card';
            }
        }

        return UnifiedLedgerBpModule::transferEventName($destination);
    }

    private function resolveBpSpendDestination(Transaction $t, string $module, ?string $connectionHubType): ?string
    {
        $meta = is_array($t->meta) ? $t->meta : [];

        if ($this->isBelievePointGiftTransaction($t)) {
            return null;
        }

        $source = (string) ($meta['source'] ?? '');
        $type = (string) $t->type;

        if ($type === 'bp_settlement' || $source === 'bp_settlement') {
            return null;
        }

        if (in_array($source, ['believe_points_purchase_bp', 'believe_points_purchase'], true)
            || ($meta['ledger_role'] ?? '') === 'bp_credit') {
            return null;
        }

        if (in_array($source, ['bp_redemption', 'believe_points_wallet_transfer'], true)
            || in_array($type, ['bp_redemption', 'believe_points_wallet_transfer'], true)) {
            return UnifiedLedgerBpModule::BRIDGE_WALLET;
        }

        if ($source === 'bridge_wallet_transfer' || $type === 'bridge_wallet_transfer') {
            // Money-side Bridge row — not a BP General Module spend.
            return null;
        }

        if (! UnifiedLedgerWalletAmountResolver::isBelievePointsPaidSpend($t, $meta)) {
            return null;
        }

        $fromModule = UnifiedLedgerBpModule::destinationFromPresenterModule($module, $connectionHubType);
        if ($fromModule !== null) {
            return $fromModule;
        }

        if ($this->isGiftCardPurchaseContext($t)) {
            return UnifiedLedgerBpModule::GIFT_CARD;
        }

        return null;
    }
}

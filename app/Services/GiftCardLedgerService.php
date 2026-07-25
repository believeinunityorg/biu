<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\Transaction;

/**
 * Gift card purchases → unified transaction ledger financials.
 */
final class GiftCardLedgerService
{
    /**
     * @param  array<string, mixed>  $financials
     * @return array<string, mixed>
     */
    public static function mergeLedgerFinancials(GiftCard $giftCard, Transaction $t, array $financials): array
    {
        $faceValue = (float) ($giftCard->amount ?? 0);
        $meta = is_array($giftCard->meta) ? $giftCard->meta : [];
        $txMeta = is_array($t->meta) ? $t->meta : [];

        // Only treat as buyer platform fee when recorded on the purchase (avoid rewriting legacy face-value-only rows).
        $platformFee = 0.0;
        if ($giftCard->platform_fee !== null) {
            $platformFee = (float) $giftCard->platform_fee;
        } elseif (array_key_exists('platform_fee', $meta) || array_key_exists('platform_fee', $txMeta)) {
            $platformFee = (float) ($meta['platform_fee'] ?? $txMeta['platform_fee'] ?? 0);
        } elseif ((float) $t->fee > 0) {
            $platformFee = (float) $t->fee;
        }

        $hasRecordedFee = $platformFee > 0
            || $giftCard->platform_fee !== null
            || array_key_exists('platform_fee', $meta)
            || array_key_exists('platform_fee', $txMeta)
            || (float) $t->fee > 0;

        // Prefer stored split; legacy rows with a fee but no split keep 100% with BIU.
        $biuFeeShare = 0.0;
        $orgFeeShare = 0.0;
        if ($hasRecordedFee) {
            if ($giftCard->platform_fee_biu_share !== null || $giftCard->platform_fee_org_share !== null) {
                $biuFeeShare = (float) ($giftCard->platform_fee_biu_share ?? 0);
                $orgFeeShare = (float) ($giftCard->platform_fee_org_share ?? 0);
            } elseif (array_key_exists('platform_fee_biu_share', $meta) || array_key_exists('platform_fee_org_share', $meta)
                || array_key_exists('platform_fee_biu_share', $txMeta) || array_key_exists('platform_fee_org_share', $txMeta)) {
                $biuFeeShare = (float) ($meta['platform_fee_biu_share'] ?? $txMeta['platform_fee_biu_share'] ?? 0);
                $orgFeeShare = (float) ($meta['platform_fee_org_share'] ?? $txMeta['platform_fee_org_share'] ?? 0);
            } else {
                $biuFeeShare = $platformFee;
                $orgFeeShare = 0.0;
            }
        }

        $buyerTotal = round(max(0.0, $faceValue + $platformFee), 2);
        if ((float) $t->amount > $buyerTotal) {
            $buyerTotal = round((float) $t->amount, 2);
        }

        $stripe = max(
            (float) ($financials['stripe_fee'] ?? 0),
            0.0
        );

        $financials['gross_amount'] = $buyerTotal > 0 ? $buyerTotal : round((float) $t->amount, 2);
        $financials['subtotal'] = round(max(0.0, $faceValue), 2);
        $financials['subtotal_amount'] = round(max(0.0, $faceValue), 2);
        $financials['stripe_fee'] = round($stripe, 2);
        $financials['gift_card_sales'] = round(max(0.0, $faceValue), 2);
        $financials['gift_card_face_value'] = round(max(0.0, $faceValue), 2);
        $financials['gift_card_total_charged'] = $buyerTotal > 0 ? $buyerTotal : round((float) $t->amount, 2);
        $financials['platform_fee'] = round(max(0.0, $platformFee), 2);
        $financials['platform_fee_amount'] = round(max(0.0, $platformFee), 2);
        $financials['platform_fee_biu_share'] = round(max(0.0, $biuFeeShare), 2);
        $financials['platform_fee_org_share'] = round(max(0.0, $orgFeeShare), 2);
        $financials['biu_fee'] = round(max(0.0, $biuFeeShare), 2);
        $financials['believe_biu_fee'] = round(max(0.0, $biuFeeShare), 2);

        $provider = $giftCard->total_commission !== null ? (float) $giftCard->total_commission : null;
        $biuShare = $giftCard->platform_commission !== null ? (float) $giftCard->platform_commission : null;
        $org = $giftCard->nonprofit_commission !== null ? (float) $giftCard->nonprofit_commission : null;
        $merchant = (float) ($giftCard->merchant_revenue ?? 0);

        if ($provider !== null && $provider > 0) {
            $financials['provider_commission'] = round($provider, 2);
        }
        if ($biuShare !== null && $biuShare > 0) {
            $financials['biu_revenue_share'] = round($biuShare, 2);
            $financials['platform_payout'] = round($biuFeeShare + $biuShare, 2);
        } else {
            $financials['platform_payout'] = round($biuFeeShare, 2);
        }
        if ($org !== null && $org > 0) {
            $financials['organization_revenue'] = round($org, 2);
            $financials['organization_payout'] = round($orgFeeShare + $org, 2);
            $financials['net_to_organization'] = round($orgFeeShare + $org, 2);
        } elseif ($orgFeeShare > 0) {
            $financials['organization_payout'] = round($orgFeeShare, 2);
            $financials['net_to_organization'] = round($orgFeeShare, 2);
        }
        if ($merchant > 0) {
            $financials['merchant_revenue'] = round($merchant, 2);
            $financials['merchant_payout'] = round($merchant, 2);
        } else {
            $financials['merchant_revenue'] = 0.0;
            $financials['merchant_payout'] = 0.0;
        }

        $financials['supplier_payout'] = 0.0;

        $giftCard->loadMissing('organization');
        if ($giftCard->organization !== null) {
            $financials['organization_name'] = (string) $giftCard->organization->name;
        }

        return $financials;
    }
}

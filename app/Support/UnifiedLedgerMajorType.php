<?php

namespace App\Support;

/**
 * Business taxonomy for the admin Transaction Ledger (reporting / filters).
 * Distinct from currency rail {@see UnifiedLedgerType} (money / bp / brp).
 */
final class UnifiedLedgerMajorType
{
    public const PURCHASE = 'purchase';

    public const SUBSCRIPTION = 'subscription';

    public const TRANSFER = 'transfer';

    public const REWARD = 'reward';

    public const ENROLLMENT = 'enrollment';

    public const DONATION = 'donation';

    public const FEE = 'fee';

    public const MARKETPLACE = 'marketplace';

    public const COMPLIANCE = 'compliance';

    public const ADJUSTMENT = 'adjustment';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PURCHASE,
            self::SUBSCRIPTION,
            self::TRANSFER,
            self::REWARD,
            self::ENROLLMENT,
            self::DONATION,
            self::FEE,
            self::MARKETPLACE,
            self::COMPLIANCE,
            self::ADJUSTMENT,
        ];
    }

    public static function label(string $major): string
    {
        return match ($major) {
            self::PURCHASE => 'Purchase',
            self::SUBSCRIPTION => 'Subscription',
            self::TRANSFER => 'Transfer',
            self::REWARD => 'Reward',
            self::ENROLLMENT => 'Enrollment',
            self::DONATION => 'Donation',
            self::FEE => 'Fee',
            self::MARKETPLACE => 'Marketplace',
            self::COMPLIANCE => 'Compliance',
            self::ADJUSTMENT => 'Adjustment',
            default => str_replace('_', ' ', ucwords($major, '_')),
        };
    }

    /**
     * Derive Major Type + Sub Type (slug + labels) from module + fine-grained transaction type.
     *
     * @return array{
     *     major_type: string,
     *     major_type_label: string,
     *     sub_type: string,
     *     sub_type_label: string
     * }
     */
    public static function classify(string $module, string $transactionType, ?string $walletType = null): array
    {
        $module = strtolower(trim($module));
        $transactionType = strtolower(trim($transactionType));
        $walletType = $walletType !== null ? strtolower(trim($walletType)) : null;

        if ($module === 'believe_points') {
            $module = UnifiedLedgerModule::GENERAL;
        }
        if ($module === 'course') {
            $module = UnifiedLedgerModule::CONNECTION_HUB;
        }
        if ($module === 'service_hub') {
            $module = UnifiedLedgerModule::SERVICE_HUB;
        }

        [$major, $sub, $subLabel] = self::map($module, $transactionType, $walletType);

        return [
            'major_type' => $major,
            'major_type_label' => self::label($major),
            'sub_type' => $sub,
            'sub_type_label' => $subLabel,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string} major, sub slug, sub label
     */
    private static function map(string $module, string $transactionType, ?string $walletType): array
    {
        if (in_array($transactionType, ['kyc_fee', 'administrative_fee'], true)
            || in_array($walletType, ['kyc_fee', 'administrative_fee'], true)) {
            $sub = $transactionType === 'administrative_fee' || $walletType === 'administrative_fee'
                ? 'administrative_fee'
                : 'kyc_fee';

            return [
                self::FEE,
                $sub,
                $sub === 'administrative_fee' ? 'Administrative Fee' : 'KYC Fee',
            ];
        }

        if (in_array($transactionType, ['form_1023_application', 'compliance_application'], true)
            || in_array($walletType, ['form_1023_application', 'compliance_application'], true)) {
            $sub = str_contains((string) $walletType, '1023') || str_contains($transactionType, '1023')
                ? 'form_1023_application'
                : 'compliance_application';

            return [
                self::COMPLIANCE,
                $sub,
                $sub === 'form_1023_application' ? 'Form 1023 Application' : 'Compliance Application',
            ];
        }

        if (in_array($transactionType, [
            'payment_refund',
            'shipping_refund',
            'tax_refund',
            'donation_refund',
        ], true) || $module === 'refund') {
            return [self::ADJUSTMENT, 'refund', self::subLabel('refund')];
        }

        return match ($module) {
            UnifiedLedgerModule::DONATION => [
                self::DONATION,
                $transactionType === 'donation_payment' ? 'donation_received' : ($transactionType ?: 'donation_received'),
                self::subLabel($transactionType === 'donation_payment' ? 'donation_received' : ($transactionType ?: 'donation_received')),
            ],
            UnifiedLedgerModule::FUNDME, UnifiedLedgerModule::CAMPAIGN => [
                self::DONATION,
                $transactionType ?: ($module === UnifiedLedgerModule::FUNDME ? 'fundme_contribution' : 'campaign_contribution'),
                self::subLabel($transactionType ?: ($module === UnifiedLedgerModule::FUNDME ? 'fundme_contribution' : 'campaign_contribution')),
            ],
            UnifiedLedgerModule::GENERAL => self::mapGeneral($transactionType),
            UnifiedLedgerModule::REWARD => [
                self::REWARD,
                $transactionType ?: 'brp_participation_reward',
                self::subLabel($transactionType ?: 'brp_participation_reward'),
            ],
            UnifiedLedgerModule::GIFT_CARD => [
                self::PURCHASE,
                'gift_card_purchase',
                'Gift Card Purchase',
            ],
            UnifiedLedgerModule::MARKETPLACE => self::mapMarketplace($transactionType, $walletType),
            UnifiedLedgerModule::SERVICE_HUB => [
                self::MARKETPLACE,
                'service_order',
                'Service Order',
            ],
            UnifiedLedgerModule::MERCHANT_HUB => [
                self::MARKETPLACE,
                $transactionType === 'merchant_hub_sale' ? 'merchant_redemption' : ($transactionType ?: 'merchant_redemption'),
                self::subLabel($transactionType === 'merchant_hub_sale' ? 'merchant_redemption' : ($transactionType ?: 'merchant_redemption')),
            ],
            UnifiedLedgerModule::CONNECTION_HUB => [
                self::ENROLLMENT,
                $transactionType ?: 'course_enrollment',
                self::subLabel($transactionType ?: 'course_enrollment'),
            ],
            UnifiedLedgerModule::SUPPORTER_SUBSCRIPTION,
            UnifiedLedgerModule::ORGANIZATION_SUBSCRIPTION,
            UnifiedLedgerModule::MERCHANT_SUBSCRIPTION => self::mapSubscription($module, $transactionType, $walletType),
            UnifiedLedgerModule::WALLET => [
                self::TRANSFER,
                'deposit',
                'Deposit',
            ],
            UnifiedLedgerModule::PAYOUT => [
                self::ADJUSTMENT,
                'withdrawal',
                'Withdrawal',
            ],
            UnifiedLedgerModule::ADJUSTMENT => [
                self::ADJUSTMENT,
                $transactionType ?: 'adjustment',
                self::subLabel($transactionType ?: 'adjustment'),
            ],
            default => [
                self::ADJUSTMENT,
                $transactionType !== '' ? $transactionType : 'adjustment',
                self::subLabel($transactionType !== '' ? $transactionType : 'adjustment'),
            ],
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function mapGeneral(string $transactionType): array
    {
        return match ($transactionType) {
            'believe_points_purchase' => [self::PURCHASE, 'bp_purchase', 'BP Purchase'],
            'bp_settlement' => [self::TRANSFER, 'bp_settlement', 'BP Settlement'],
            'bp_redemption', 'believe_points_wallet_transfer' => [self::TRANSFER, 'bp_redemption', 'Transfer to BIU Wallet'],
            'bridge_wallet_transfer' => [self::TRANSFER, 'bridge_wallet_transfer', 'BIU Wallet Transfer'],
            'bp_gift_sent' => [self::TRANSFER, 'bp_gift_sent', 'BP Gift Sent'],
            'bp_gift_claimed' => [self::TRANSFER, 'bp_gift_claimed', 'BP Gift Claimed'],
            'bp_gift_cancelled' => [self::TRANSFER, 'bp_gift_cancelled', 'BP Gift Cancelled'],
            'bp_gift_expired' => [self::TRANSFER, 'bp_gift_expired', 'BP Gift Expired'],
            'bp_gift_refunded' => [self::TRANSFER, 'bp_gift_refunded', 'BP Gift Refunded'],
            'brp_participation_reward' => [self::REWARD, 'brp_participation_reward', 'BRP Reward'],
            default => [
                self::TRANSFER,
                $transactionType !== '' ? $transactionType : 'transfer',
                self::subLabel($transactionType !== '' ? $transactionType : 'transfer'),
            ],
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function mapMarketplace(string $transactionType, ?string $walletType): array
    {
        if (in_array($walletType, ['raffle_tickets', 'raffle_sale'], true)
            || in_array($transactionType, ['raffle_tickets', 'raffle_sale'], true)) {
            $sub = str_contains((string) $walletType, 'ticket') || $transactionType === 'raffle_tickets'
                ? 'raffle_ticket_purchase'
                : 'raffle_sale';

            return [self::MARKETPLACE, $sub, self::subLabel($sub)];
        }

        return [self::MARKETPLACE, 'marketplace_sale', 'Marketplace Sale'];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function mapSubscription(string $module, string $transactionType, ?string $walletType): array
    {
        if ($walletType === 'newsletter_pro_targeting_lifetime'
            || $transactionType === 'newsletter_pro_targeting_purchase') {
            return [self::SUBSCRIPTION, 'newsletter_pro_targeting_lifetime', 'Newsletter Pro Targeting Lifetime'];
        }
        if ($walletType === 'sms_purchase' || $transactionType === 'sms_credit_purchase') {
            return [self::SUBSCRIPTION, 'sms_purchase', 'SMS Purchase'];
        }
        if ($walletType === 'email_purchase' || $transactionType === 'email_credit_purchase') {
            return [self::SUBSCRIPTION, 'email_purchase', 'Email Purchase'];
        }
        if ($walletType === 'credit_purchase' || $transactionType === 'credit_purchase') {
            return [self::PURCHASE, 'credit_purchase', 'Credit Purchase'];
        }

        return match ($module) {
            UnifiedLedgerModule::SUPPORTER_SUBSCRIPTION => [
                self::SUBSCRIPTION,
                'prime_membership',
                'Prime Membership',
            ],
            UnifiedLedgerModule::MERCHANT_SUBSCRIPTION => [
                self::SUBSCRIPTION,
                'merchant_subscription',
                'Merchant Subscription',
            ],
            default => [
                self::SUBSCRIPTION,
                'organization_subscription',
                'Organization Subscription',
            ],
        };
    }

    public static function subLabel(string $sub): string
    {
        $map = [
            'bp_purchase' => 'BP Purchase',
            'believe_points_purchase' => 'BP Purchase',
            'gift_card_purchase' => 'Gift Card Purchase',
            'credit_purchase' => 'Credit Purchase',
            'email_purchase' => 'Email Purchase',
            'email_credit_purchase' => 'Email Purchase',
            'sms_purchase' => 'SMS Purchase',
            'sms_credit_purchase' => 'SMS Purchase',
            'newsletter_pro_targeting_lifetime' => 'Newsletter Pro Targeting Lifetime',
            'newsletter_pro_targeting_purchase' => 'Newsletter Pro Targeting Lifetime',
            'prime_membership' => 'Prime Membership',
            'supporter_subscription_paid' => 'Prime Membership',
            'merchant_subscription' => 'Merchant Subscription',
            'merchant_subscription_paid' => 'Merchant Subscription',
            'organization_subscription' => 'Organization Subscription',
            'organization_subscription_paid' => 'Organization Subscription',
            'bp_gift_sent' => 'BP Gift Sent',
            'bp_gift_claimed' => 'BP Gift Claimed',
            'bp_gift_cancelled' => 'BP Gift Cancelled',
            'bp_gift_expired' => 'BP Gift Expired',
            'bp_gift_refunded' => 'BP Gift Refunded',
            'bp_settlement' => 'BP Settlement',
            'bp_redemption' => 'Transfer to BIU Wallet',
            'believe_points_wallet_transfer' => 'Transfer to BIU Wallet',
            'bridge_wallet_transfer' => 'BIU Wallet Transfer',
            'deposit' => 'Deposit',
            'withdrawal' => 'Withdrawal',
            'transfer_in' => 'Transfer In',
            'transfer_out' => 'Transfer Out',
            'transfer' => 'Transfer',
            'brp_participation_reward' => 'BRP Reward',
            'referral_reward' => 'Referral Reward',
            'commission' => 'Commission',
            'course_enrollment' => 'Course Enrollment',
            'event_registration' => 'Event Registration',
            'companion_enrollment' => 'Companion Enrollment',
            'earning_enrollment' => 'Earning Enrollment',
            'connection_hub_enrollment' => 'Course Enrollment',
            'donation_received' => 'Donation Received',
            'donation_payment' => 'Donation Received',
            'donation_refund' => 'Donation Refund',
            'fundme_contribution' => 'Donation Received',
            'campaign_contribution' => 'Donation Received',
            'kyc_fee' => 'KYC Fee',
            'administrative_fee' => 'Administrative Fee',
            'merchant_redemption' => 'Merchant Redemption',
            'merchant_hub_sale' => 'Merchant Redemption',
            'service_order' => 'Service Order',
            'service_payment' => 'Service Order',
            'marketplace_sale' => 'Marketplace Sale',
            'raffle_ticket_purchase' => 'Raffle Ticket Purchase',
            'raffle_sale' => 'Raffle Sale',
            'form_1023_application' => 'Form 1023 Application',
            'compliance_application' => 'Compliance Application',
            'adjustment' => 'Adjustment',
            'cancellation' => 'Cancellation',
            'refund' => 'Refund',
            'payment_refund' => 'Refund',
            'shipping_refund' => 'Refund',
            'tax_refund' => 'Refund',
            'wallet_deposit' => 'Deposit',
            'organization_payout' => 'Withdrawal',
            'merchant_payout' => 'Withdrawal',
        ];

        if (isset($map[$sub])) {
            return $map[$sub];
        }

        return str_replace('_', ' ', ucwords($sub, '_'));
    }
}

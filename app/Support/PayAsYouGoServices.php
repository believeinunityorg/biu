<?php

namespace App\Support;

use App\Models\EmailPackage;
use App\Models\SmsPackage;
use App\Models\User;

/**
 * Aggregates pay-as-you-go balances and purchasable packs (email, SMS, AI tokens).
 */
final class PayAsYouGoServices
{
    /** @var list<string> */
    public const AI_PACK_KEYS = ['addon_10k', 'addon_25k', 'addon_50k'];

    /**
     * @return array{
     *     email: array{
     *         balance: int,
     *         included: int,
     *         used: int,
     *         rate_label: string,
     *         min_reup_label: string|null,
     *         packages: list<array{id: int, name: string, description: string|null, emails_count: int, price: float, purchasable: bool}>
     *     },
     *     sms: array{
     *         balance: int,
     *         included: int,
     *         used: int,
     *         rate_label: string,
     *         min_reup_label: string|null,
     *         packages: list<array{id: int, name: string, description: string|null, sms_count: int, price: float}>
     *     },
     *     ai: array{
     *         balance: int,
     *         included: int,
     *         used: int,
     *         rate_label: string,
     *         min_reup_label: string|null,
     *         packages: list<array{key: string, tokens: int, price: float, label: string}>
     *     },
     *     sms_auto_recharge_enabled: bool
     * }
     */
    public static function forUser(User $user): array
    {
        $emailStats = UserEmailCredits::stats($user);
        $emailPackages = EmailPackageCatalog::activeForCheckout();

        $smsIncluded = (int) ($user->sms_included ?? 0);
        $smsUsed = (int) ($user->sms_used ?? 0);
        $smsLeft = max(0, $smsIncluded - $smsUsed);

        $smsPackages = SmsPackage::active()->ordered()->get()->map(static function ($package) {
            return [
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'sms_count' => (int) $package->sms_count,
                'price' => (float) $package->price,
            ];
        })->values()->all();

        $aiIncluded = (int) ($user->ai_tokens_included ?? 0);
        $aiUsed = (int) ($user->ai_tokens_used ?? 0);
        if ($aiIncluded > 0) {
            $aiUsed = min($aiUsed, $aiIncluded);
        }
        $aiLeft = $aiIncluded > 0 ? max(0, $aiIncluded - $aiUsed) : 0;

        $aiPackages = self::aiPackages();

        return [
            'email' => [
                'balance' => $emailStats['emails_left'],
                'included' => $emailStats['emails_included'],
                'used' => $emailStats['emails_used'],
                'rate_label' => self::emailRateLabel($emailPackages),
                'min_reup_label' => self::emailMinReupLabel($emailPackages),
                'packages' => $emailPackages,
            ],
            'sms' => [
                'balance' => $smsLeft,
                'included' => $smsIncluded,
                'used' => $smsUsed,
                'rate_label' => self::smsRateLabel($smsPackages),
                'min_reup_label' => self::smsMinReupLabel($smsPackages),
                'packages' => $smsPackages,
            ],
            'ai' => [
                'balance' => $aiLeft,
                'included' => $aiIncluded,
                'used' => $aiUsed,
                'rate_label' => self::aiRateLabel($aiPackages),
                'min_reup_label' => self::aiMinReupLabel($aiPackages),
                'packages' => $aiPackages,
            ],
            'sms_auto_recharge_enabled' => (bool) ($user->sms_auto_recharge_enabled ?? false),
        ];
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, emails_count: int, price: float, purchasable: bool}>  $packages
     */
    private static function emailRateLabel(array $packages): string
    {
        $reference = collect($packages)->firstWhere('purchasable', true)
            ?? collect($packages)->sortBy('price')->first();

        if ($reference && $reference['emails_count'] > 0) {
            $perThousand = ((float) $reference['price'] / $reference['emails_count']) * 1000;

            return '$'.number_format($perThousand, 2).' per 1,000 emails';
        }

        return '$1.00 per 1,000 emails';
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, emails_count: int, price: float, purchasable: bool}>  $packages
     */
    private static function emailMinReupLabel(array $packages): ?string
    {
        $min = collect($packages)
            ->filter(static fn (array $p) => $p['purchasable'])
            ->sortBy('price')
            ->first();

        if (! $min) {
            return null;
        }

        return '$'.number_format((float) $min['price'], 2).' ('.number_format($min['emails_count']).' emails)';
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, sms_count: int, price: float}>  $packages
     */
    private static function smsRateLabel(array $packages): string
    {
        $reference = collect($packages)->sortBy('price')->first();

        if ($reference && $reference['sms_count'] > 0) {
            $perText = (float) $reference['price'] / $reference['sms_count'];

            return '$'.number_format($perText, 3).' per text';
        }

        return '$0.015 per text';
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, sms_count: int, price: float}>  $packages
     */
    private static function smsMinReupLabel(array $packages): ?string
    {
        $min = collect($packages)->sortBy('price')->first();

        if (! $min) {
            return null;
        }

        return '$'.number_format((float) $min['price'], 2).' ('.number_format($min['sms_count']).' texts)';
    }

    /**
     * @return list<array{key: string, tokens: int, price: float, label: string}>
     */
    private static function aiPackages(): array
    {
        $catalog = [
            'addon_10k' => ['amount' => 2.0, 'credits' => 10_000],
            'addon_25k' => ['amount' => 5.0, 'credits' => 25_000],
            'addon_50k' => ['amount' => 10.0, 'credits' => 50_000],
        ];

        $packages = [];

        foreach (self::AI_PACK_KEYS as $key) {
            if (! isset($catalog[$key])) {
                continue;
            }

            $row = $catalog[$key];
            $packages[] = [
                'key' => $key,
                'tokens' => (int) $row['credits'],
                'price' => (float) $row['amount'],
                'label' => number_format($row['credits']).' tokens',
            ];
        }

        return $packages;
    }

    /**
     * @param  list<array{key: string, tokens: int, price: float, label: string}>  $packages
     */
    private static function aiRateLabel(array $packages): string
    {
        $reference = collect($packages)->firstWhere('key', 'addon_25k') ?? ($packages[0] ?? null);

        if ($reference && $reference['tokens'] > 0) {
            $perPack = ((float) $reference['price'] / $reference['tokens']) * 25_000;

            return '$'.number_format($perPack, 2).' per 25,000 tokens';
        }

        return '$5.00 per 25,000 tokens';
    }

    /**
     * @param  list<array{key: string, tokens: int, price: float, label: string}>  $packages
     */
    private static function aiMinReupLabel(array $packages): ?string
    {
        $min = collect($packages)->sortBy('price')->first();

        if (! $min) {
            return null;
        }

        return '$'.number_format((float) $min['price'], 2).' ('.number_format($min['tokens']).' tokens)';
    }
}

<?php

namespace App\Support;

use App\Enums\GiftCardStatus;
use App\Models\GiftCard;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Normalize Phaze purchase / webhook payloads into gift-card credentials.
 *
 * Field names vary by brand and API version; this extractor checks common aliases
 * without changing the purchase workflow.
 */
final class PhazeGiftCardPayload
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     pin: ?string,
     *     card_number: ?string,
     *     voucher: ?string,
     *     barcode: ?string,
     *     barcode_url: ?string,
     *     qr_code: ?string,
     *     qr_code_url: ?string,
     *     claim_url: ?string,
     *     how_to_use: ?string,
     *     redemption_instructions: ?string,
     *     expires_at: ?Carbon,
     *     status: ?string,
     *     transaction_id: ?string,
     *     order_id: ?string,
     *     face_value: ?float,
     *     currency: ?string,
     *     product_name: ?string
     * }
     */
    public static function extractCredentials(array $payload): array
    {
        $nested = [];
        foreach (['voucherDetails', 'voucher_details', 'card', 'cardDetails', 'card_details', 'data', 'result'] as $nestKey) {
            if (isset($payload[$nestKey]) && is_array($payload[$nestKey])) {
                $nested[] = $payload[$nestKey];
            }
        }

        // Some responses return a vouchers/cards array
        foreach (['vouchers', 'cards', 'items'] as $listKey) {
            if (isset($payload[$listKey]) && is_array($payload[$listKey]) && isset($payload[$listKey][0]) && is_array($payload[$listKey][0])) {
                $nested[] = $payload[$listKey][0];
            }
        }

        $sources = array_merge([$payload], $nested);

        $pin = self::firstStringFromSources($sources, [
            'pin', 'PIN', 'cardPin', 'card_pin', 'cardPIN',
            'securityCode', 'security_code', 'securityPin', 'security_pin',
            'cvv', 'CVV', 'cvc', 'CVC',
        ]);

        $cardNumber = self::firstStringFromSources($sources, [
            'cardNumber', 'card_number', 'cardNo', 'card_no', 'gan', 'accountNumber', 'account_number',
        ]);

        $voucher = self::firstStringFromSources($sources, [
            'voucher', 'voucherCode', 'voucher_code', 'code', 'redemptionCode', 'redemption_code',
        ]);

        $barcode = self::firstStringFromSources($sources, [
            'barcode', 'barCode', 'bar_code',
        ]);

        $barcodeUrl = self::firstUrlFromSources($sources, [
            'barcodeUrl', 'barcode_url', 'barCodeUrl', 'bar_code_url', 'barcodeImage', 'barcode_image',
        ]);

        $qrCode = self::firstStringFromSources($sources, [
            'qrCode', 'qr_code', 'QRCode',
        ]);

        $qrCodeUrl = self::firstUrlFromSources($sources, [
            'qrCodeUrl', 'qr_code_url', 'qrUrl', 'qr_url', 'qrCodeImage', 'qr_code_image',
        ]);

        // If barcode/qr values look like URLs, treat them as image URLs
        if ($barcode && self::looksLikeUrl($barcode) && ! $barcodeUrl) {
            $barcodeUrl = $barcode;
            $barcode = null;
        }
        if ($qrCode && self::looksLikeUrl($qrCode) && ! $qrCodeUrl) {
            $qrCodeUrl = $qrCode;
            $qrCode = null;
        }

        // Nested voucher objects from Phaze use plain "url" for the redeem link.
        $claimUrl = self::firstUrlFromSources($sources, [
            'claimUrl', 'claim_url', 'redemptionUrl', 'redemption_url',
            'redeemUrl', 'redeem_url', 'url',
        ]);

        // Avoid treating top-level unrelated URLs as claim links when vouchers[] is present.
        if ($claimUrl && isset($payload['vouchers'][0]) && is_array($payload['vouchers'][0])) {
            $voucherUrl = self::firstUrlFromSources([$payload['vouchers'][0]], ['url', 'claimUrl', 'redemptionUrl', 'redeemUrl']);
            if ($voucherUrl) {
                $claimUrl = $voucherUrl;
            }
        }

        $howToUse = self::firstStringFromSources($sources, [
            'howToUse', 'how_to_use',
        ]);

        $redemptionInstructions = self::firstStringFromSources($sources, [
            'redemptionInstructions', 'redemption_instructions', 'instructions',
            'usageInstructions', 'usage_instructions',
        ]) ?: $howToUse;

        $expiresAt = self::firstDateFromSources($sources, [
            'expiresAt', 'expires_at', 'expiryDate', 'expiry_date', 'expiry',
            'validityDate', 'validity_date',
            'validTill', 'valid_till', 'validUntil', 'valid_until', 'expirationDate', 'expiration_date',
        ]);

        $status = self::normalizeProviderStatus(self::firstStringFromSources($sources, ['status']));
        $transactionId = self::firstStringFromSources($sources, ['id', 'transactionId', 'transaction_id']);
        $orderId = self::firstStringFromSources($sources, ['orderId', 'orderID', 'order_id']);
        $currency = self::firstStringFromSources($sources, ['baseCurrency', 'voucherCurrency', 'currency']);
        $productName = self::firstStringFromSources($sources, ['productName', 'product_name', 'brand', 'brandName', 'brand_name']);

        // Phaze Walmart-style vouchers return a short numeric "code" (PIN) without a separate pin field.
        if (! $pin && $voucher && preg_match('/^\d{3,12}$/', $voucher)) {
            $pin = $voucher;
        }

        $faceValue = null;
        foreach (['faceValue', 'face_value', 'denomination', 'amount', 'price'] as $key) {
            foreach ($sources as $source) {
                if (isset($source[$key]) && is_numeric($source[$key])) {
                    $faceValue = (float) $source[$key];
                    break 2;
                }
            }
        }

        return [
            'pin' => $pin,
            'card_number' => $cardNumber,
            'voucher' => $voucher,
            'barcode' => $barcode,
            'barcode_url' => $barcodeUrl,
            'qr_code' => $qrCode,
            'qr_code_url' => $qrCodeUrl,
            'claim_url' => $claimUrl,
            'how_to_use' => $howToUse,
            'redemption_instructions' => $redemptionInstructions,
            'expires_at' => $expiresAt,
            'status' => $status,
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'face_value' => $faceValue,
            'currency' => $currency,
            'product_name' => $productName,
        ];
    }

    /**
     * Build GiftCard update attributes from a Phaze payload.
     * Only sets values that are present; never clears existing credentials with nulls.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    public static function buildCredentialUpdates(GiftCard $giftCard, array $payload, array $extraMeta = []): array
    {
        $credentials = self::extractCredentials($payload);
        $existingMeta = is_array($giftCard->meta) ? $giftCard->meta : [];
        $update = [];

        if ($credentials['voucher']) {
            $update['voucher'] = $credentials['voucher'];
        }

        if ($credentials['card_number']) {
            $update['card_number'] = $credentials['card_number'];
        }

        if ($credentials['pin']) {
            $update['pin'] = $credentials['pin'];
        }

        if ($credentials['expires_at']) {
            $update['expires_at'] = $credentials['expires_at'];
        }

        if ($credentials['transaction_id']) {
            $update['external_id'] = (string) $credentials['transaction_id'];
            $update['phaze_disbursement_id'] = (string) $credentials['transaction_id'];
        }

        // Keep local gift-card status aligned with Phaze ("processed" => completed).
        if (self::isProviderSuccessStatus($credentials['status'])
            && ! in_array($giftCard->status, [GiftCardStatus::Failed->value], true)
            && ! GiftCardStatus::isAwaitingFulfillment($giftCard->status)
            && $giftCard->status !== GiftCardStatus::Processing->value
        ) {
            $update['status'] = GiftCardStatus::Completed->value;
            if (! $giftCard->fulfilled_at) {
                $update['fulfilled_at'] = now();
            }
        } elseif (self::isProviderFailedStatus($credentials['status'])) {
            $update['status'] = GiftCardStatus::Failed->value;
        }

        $credentialMeta = array_filter([
            'pin' => $credentials['pin'],
            'card_number' => $credentials['card_number'],
            'voucher' => $credentials['voucher'],
            'barcode' => $credentials['barcode'],
            'barcode_url' => $credentials['barcode_url'],
            'qr_code' => $credentials['qr_code'],
            'qr_code_url' => $credentials['qr_code_url'],
            'claim_url' => $credentials['claim_url'],
            'how_to_use' => $credentials['how_to_use'],
            'redemption_instructions' => $credentials['redemption_instructions'],
            'transaction_id' => $credentials['transaction_id'],
            'order_id' => $credentials['order_id'],
            'status' => $credentials['status'],
            'currency' => $credentials['currency'],
            'product_name' => $credentials['product_name'],
            'face_value' => $credentials['face_value'],
            'expires_at' => $credentials['expires_at']?->toIso8601String(),
        ], static fn ($value) => $value !== null && $value !== '');

        $meta = array_merge($existingMeta, $extraMeta, [
            'phaze_purchase' => $payload,
        ]);

        if ($credentialMeta !== []) {
            $meta['phaze_credentials'] = array_merge(
                is_array($existingMeta['phaze_credentials'] ?? null) ? $existingMeta['phaze_credentials'] : [],
                $credentialMeta
            );
        }

        if ($credentials['how_to_use'] && empty($meta['howToUse'])) {
            $meta['howToUse'] = $credentials['how_to_use'];
        }

        if ($credentials['order_id'] && empty($meta['orderId'])) {
            $meta['orderId'] = $credentials['order_id'];
        }

        if ($credentials['status']) {
            $meta['phaze_status'] = $credentials['status'];
        }

        if ($credentials['transaction_id']) {
            $meta['phaze_purchase_id'] = $credentials['transaction_id'];
        }

        $update['meta'] = $meta;

        return $update;
    }

    /**
     * Normalize Phaze provider status for storage/display.
     * Phaze admin uses "Processed"; API returns "processed".
     */
    public static function normalizeProviderStatus(?string $status): ?string
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        return strtolower(trim($status));
    }

    public static function isProviderSuccessStatus(?string $status): bool
    {
        $status = self::normalizeProviderStatus($status);

        return in_array($status, ['processed', 'completed', 'success', 'complete', 'fulfilled'], true);
    }

    public static function isProviderFailedStatus(?string $status): bool
    {
        $status = self::normalizeProviderStatus($status);

        return in_array($status, ['failed', 'error', 'cancelled', 'canceled', 'rejected'], true);
    }

    public static function providerStatusLabel(?string $status): string
    {
        $status = self::normalizeProviderStatus($status);
        if ($status === null) {
            return 'Unknown';
        }

        return match ($status) {
            'processed' => 'Processed',
            'completed', 'complete', 'success', 'fulfilled' => 'Processed',
            'pending', 'processing', 'created' => 'Pending',
            'failed', 'error', 'rejected' => 'Failed',
            'cancelled', 'canceled' => 'Cancelled',
            default => ucfirst($status),
        };
    }

    /**
     * Resolve display credentials from a gift card (column + meta fallbacks).
     *
     * @return array{
     *     pin: ?string,
     *     card_number: ?string,
     *     voucher: ?string,
     *     barcode: ?string,
     *     barcode_url: ?string,
     *     qr_code: ?string,
     *     qr_code_url: ?string,
     *     claim_url: ?string,
     *     redemption_instructions: ?string,
     *     how_to_use: ?string
     * }
     */
    public static function resolveForDisplay(GiftCard $giftCard): array
    {
        $meta = is_array($giftCard->meta) ? $giftCard->meta : [];
        $stored = is_array($meta['phaze_credentials'] ?? null) ? $meta['phaze_credentials'] : [];
        $phaze = [];
        foreach (['phaze_purchase', 'phaze_initial_response', 'phaze_webhook'] as $key) {
            if (isset($meta[$key]) && is_array($meta[$key])) {
                $phaze = array_merge($phaze, $meta[$key]);
            }
        }

        $fromPhaze = $phaze !== [] ? self::extractCredentials($phaze) : [
            'pin' => null,
            'card_number' => null,
            'voucher' => null,
            'barcode' => null,
            'barcode_url' => null,
            'qr_code' => null,
            'qr_code_url' => null,
            'claim_url' => null,
            'how_to_use' => null,
            'redemption_instructions' => null,
        ];

        $pick = static function (?string ...$candidates): ?string {
            foreach ($candidates as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }

            return null;
        };

        return [
            'pin' => $pick($giftCard->pin ?? null, Arr::get($stored, 'pin'), $fromPhaze['pin']),
            'card_number' => $pick($giftCard->card_number, Arr::get($stored, 'card_number'), $fromPhaze['card_number']),
            'voucher' => $pick($giftCard->voucher, Arr::get($stored, 'voucher'), $fromPhaze['voucher']),
            'barcode' => $pick(Arr::get($stored, 'barcode'), $fromPhaze['barcode']),
            'barcode_url' => $pick(Arr::get($stored, 'barcode_url'), $fromPhaze['barcode_url']),
            'qr_code' => $pick(Arr::get($stored, 'qr_code'), $fromPhaze['qr_code']),
            'qr_code_url' => $pick(Arr::get($stored, 'qr_code_url'), $fromPhaze['qr_code_url']),
            'claim_url' => $pick(Arr::get($stored, 'claim_url'), $fromPhaze['claim_url']),
            'how_to_use' => $pick(
                Arr::get($stored, 'how_to_use'),
                Arr::get($meta, 'howToUse'),
                $fromPhaze['how_to_use']
            ),
            'redemption_instructions' => $pick(
                Arr::get($stored, 'redemption_instructions'),
                $fromPhaze['redemption_instructions'],
                Arr::get($stored, 'how_to_use'),
                Arr::get($meta, 'howToUse'),
                $fromPhaze['how_to_use']
            ),
        ];
    }

    /**
     * Flatten non-empty scalar fields from a Phaze payload for admin/org display.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar>
     */
    public static function flattenDisplayFields(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value)) {
                $out[$key] = $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $keys
     */
    private static function firstStringFromSources(array $sources, array $keys): ?string
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $source)) {
                    continue;
                }
                $value = $source[$key];
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $keys
     */
    private static function firstUrlFromSources(array $sources, array $keys): ?string
    {
        $value = self::firstStringFromSources($sources, $keys);

        return $value && self::looksLikeUrl($value) ? $value : null;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $keys
     */
    private static function firstDateFromSources(array $sources, array $keys): ?Carbon
    {
        $raw = self::firstStringFromSources($sources, $keys);
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function looksLikeUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }
}

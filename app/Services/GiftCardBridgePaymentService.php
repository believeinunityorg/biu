<?php

namespace App\Services;

use App\Models\BridgeIntegration;
use App\Models\User;
use App\Support\SupporterSubscriptionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Charge a supporter's Bridge wallet into the platform reserve for open-loop gift cards.
 * Reverse direction of BP → Wallet (reserve → member).
 */
class GiftCardBridgePaymentService
{
    public function __construct(
        private readonly BridgeService $bridgeService,
        private readonly BelievePointsWalletTransferSettingsService $reserveSettings,
        private readonly BridgeWalletReadService $bridgeWalletReadService,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     message?: string,
     *     error_code?: string,
     *     transfer_id?: string|null,
     *     bridge_wallet_id?: string|null,
     *     amount?: float,
     *     balance?: float|null
     * }
     */
    public function chargeToPlatformReserve(User $user, float $amount, ?string $idempotencyKey = null): array
    {
        $amount = round(max(0, (float) $amount), 2);
        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid charge amount.',
                'error_code' => 'INVALID_AMOUNT',
            ];
        }

        if (($user->role ?? null) !== 'user'
            || SupporterSubscriptionService::currentTierSlug($user) !== SupporterSubscriptionService::SLUG_PRIME
        ) {
            return [
                'success' => false,
                'message' => 'Visa and Mastercard gift cards paid with Believe Cash are available for Prime Supporters only.',
                'error_code' => 'PRIME_REQUIRED',
            ];
        }

        if ($this->bridgeService->isSandbox()) {
            return [
                'success' => false,
                'message' => 'Believe Cash gift card purchases are only available in production.',
                'error_code' => 'SANDBOX_UNAVAILABLE',
            ];
        }

        $reserveCustomerId = $this->reserveSettings->prefundedCustomerId();
        $reserveWalletId = $this->reserveSettings->prefundedWalletId();
        if ($reserveCustomerId === '' || $reserveWalletId === '') {
            return [
                'success' => false,
                'message' => 'Platform Bridge reserve is not configured. Please try again later.',
                'error_code' => 'RESERVE_NOT_CONFIGURED',
            ];
        }

        $integration = BridgeIntegration::resolveForAuthUser($user);
        if ($integration === null || empty($integration->bridge_customer_id)) {
            return [
                'success' => false,
                'message' => 'Connect and verify your Believe Cash before buying with Believe Cash balance.',
                'error_code' => 'BRIDGE_WALLET_REQUIRED',
            ];
        }

        $senderWallet = $this->bridgeService->resolveCustomerBridgeWallet($integration);
        if ($senderWallet === null || ($senderWallet['initiation_required'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Your Believe Cash is not ready yet. Finish wallet setup, then try again.',
                'error_code' => 'BRIDGE_WALLET_REQUIRED',
            ];
        }

        $available = (float) ($senderWallet['balance'] ?? 0);
        try {
            $snapshot = $this->bridgeWalletReadService->getWalletSnapshot($integration);
            if (isset($snapshot['balance']) && is_numeric($snapshot['balance'])) {
                $available = (float) $snapshot['balance'];
            }
        } catch (\Throwable) {
            // fall back to resolveCustomerBridgeWallet balance
        }

        if ($available + 0.000001 < $amount) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Insufficient Believe Cash balance. You need $%s but only have $%s.',
                    number_format($amount, 2),
                    number_format($available, 2),
                ),
                'error_code' => 'INSUFFICIENT_BRIDGE_BALANCE',
                'balance' => $available,
                'amount' => $amount,
            ];
        }

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : (string) Str::uuid();

        $transferResult = $this->bridgeService->createWalletToWalletTransfer(
            (string) $integration->bridge_customer_id,
            (string) $senderWallet['wallet_id'],
            $reserveCustomerId,
            $reserveWalletId,
            $amount,
            'USD',
            null,
            null,
            $idempotencyKey,
        );

        if (! ($transferResult['success'] ?? false)) {
            $message = (string) ($transferResult['error'] ?? $transferResult['message'] ?? 'Bridge transfer failed.');
            Log::warning('Gift card Bridge charge failed', [
                'user_id' => $user->id,
                'amount' => $amount,
                'response' => $transferResult,
            ]);

            return [
                'success' => false,
                'message' => $message !== ''
                    ? $message
                    : 'Could not charge your Believe Cash. Please try again.',
                'error_code' => (string) ($transferResult['error_code'] ?? 'BRIDGE_TRANSFER_FAILED'),
                'balance' => $available,
                'amount' => $amount,
            ];
        }

        $transferId = $transferResult['data']['id']
            ?? $transferResult['data']['transfer_id']
            ?? null;

        return [
            'success' => true,
            'transfer_id' => $transferId !== null ? (string) $transferId : null,
            'bridge_wallet_id' => (string) $senderWallet['wallet_id'],
            'amount' => $amount,
            'balance' => round(max(0, $available - $amount), 2),
        ];
    }
}

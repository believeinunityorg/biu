<?php

namespace App\Services;

use App\Models\BelievePointWalletTransfer;
use App\Models\BridgeIntegration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BelievePointsToBridgeWalletService
{
    private const LIQUIDITY_RETRY_HOURS = 24;

    public function __construct(
        private readonly BridgeService $bridgeService,
        private readonly BelievePointsWalletTransferSettingsService $settings,
        private readonly BridgeWalletNotifier $bridgeWalletNotifier,
        private readonly BridgeWalletReadService $bridgeWalletReadService,
    ) {}

    /**
     * @return array{success: bool, message?: string, data?: array<string, mixed>, error_code?: string}
     */
    public function transfer(User $user, float $amount, ?string $idempotencyKey = null): array
    {
        $amount = round(max(0, (float) $amount), 2);

        if (! $this->settings->isEnabled()) {
            return [
                'success' => false,
                'message' => 'Moving Believe Points to your wallet is not available right now.',
                'error_code' => 'BP_WALLET_TRANSFER_DISABLED',
            ];
        }

        if (! $this->settings->userCanTransfer($user)) {
            return [
                'success' => false,
                'message' => 'Moving Believe Points to your wallet is available for Prime Supporters and organization accounts.',
                'error_code' => 'BP_WALLET_TRANSFER_NOT_ELIGIBLE',
            ];
        }

        if ($this->bridgeService->isSandbox()) {
            return [
                'success' => false,
                'message' => 'Moving Believe Points to your Bridge wallet is only available in production.',
                'error_code' => 'SANDBOX_BP_WALLET_TRANSFER_UNAVAILABLE',
            ];
        }

        if ($amount < $this->settings->minAmount() || $amount > $this->settings->maxAmount()) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Amount must be between $%s and $%s.',
                    number_format($this->settings->minAmount(), 2),
                    number_format($this->settings->maxAmount(), 2),
                ),
                'error_code' => 'INVALID_AMOUNT',
            ];
        }

        $integration = BridgeIntegration::resolveForAuthUser($user);
        if ($integration === null || empty($integration->bridge_customer_id)) {
            return [
                'success' => false,
                'message' => 'Connect and verify your Believe wallet before moving Believe Points.',
                'error_code' => 'BRIDGE_WALLET_REQUIRED',
            ];
        }

        $recipientWallet = $this->bridgeService->resolveCustomerBridgeWallet($integration);
        if ($recipientWallet === null) {
            return [
                'success' => false,
                'message' => 'Your Bridge wallet is not ready yet. Complete wallet setup first.',
                'error_code' => 'BRIDGE_WALLET_REQUIRED',
            ];
        }

        if ($recipientWallet['initiation_required'] ?? false) {
            return [
                'success' => false,
                'message' => 'Your Bridge wallet needs to be activated before you can receive funds.',
                'error_code' => 'BRIDGE_WALLET_INITIATION_REQUIRED',
            ];
        }

        $idempotencyKey = trim((string) ($idempotencyKey ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = (string) Str::uuid();
        }

        $existing = BelievePointWalletTransfer::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->responseFromTransferRecord($existing, $user);
        }

        if ($this->settings->resolvedPrefundedWallet() === null) {
            return [
                'success' => false,
                'message' => 'Moving Believe Points to your wallet is not available right now.',
                'error_code' => 'PREFUNDED_WALLET_NOT_CONFIGURED',
            ];
        }

        if (! $this->userHasPurchasedBalance($user, $amount)) {
            return [
                'success' => false,
                'message' => 'Insufficient purchased Believe Points. Gift BP can only be spent on gift cards.',
                'error_code' => 'INSUFFICIENT_BELIEVE_POINTS',
            ];
        }

        // Deduct BP and persist the transfer row BEFORE any Bridge disbursement.
        $reserved = $this->reserveTransferPoints(
            $user,
            $integration,
            $amount,
            $idempotencyKey,
            $recipientWallet['wallet_id'],
        );

        if (! ($reserved['success'] ?? false)) {
            return $reserved;
        }

        /** @var BelievePointWalletTransfer $transfer */
        $transfer = $reserved['transfer'];

        return $this->disburseReservedTransfer($transfer, $integration, $user);
    }

    /**
     * @return array{processed: int, expired: int, reconciled: int}
     */
    public function processDuePendingTransfers(): array
    {
        $processed = 0;

        BelievePointWalletTransfer::query()
            ->where('status', BelievePointWalletTransfer::STATUS_PENDING)
            ->whereNull('bridge_transfer_id')
            ->where(function ($query) {
                $query->whereNull('retry_until')
                    ->orWhere('retry_until', '>', now());
            })
            ->orderBy('created_at')
            ->chunkById(50, function ($transfers) use (&$processed) {
                foreach ($transfers as $transfer) {
                    $this->processPendingTransfer($transfer);
                    $processed++;
                }
            });

        $expired = 0;

        BelievePointWalletTransfer::query()
            ->where('status', BelievePointWalletTransfer::STATUS_PENDING)
            ->whereNull('bridge_transfer_id')
            ->whereNotNull('retry_until')
            ->where('retry_until', '<=', now())
            ->orderBy('id')
            ->chunkById(50, function ($transfers) use (&$expired) {
                foreach ($transfers as $transfer) {
                    $this->refundFailedTransfer(
                        $transfer,
                        'Transfer could not be completed within 24 hours.',
                    );
                    $expired++;
                }
            });

        return [
            'processed' => $processed,
            'expired' => $expired,
            'reconciled' => $this->reconcileSubmittedTransfers(),
        ];
    }

    public function processPendingTransfer(BelievePointWalletTransfer $transfer): void
    {
        if ($transfer->status !== BelievePointWalletTransfer::STATUS_PENDING || $transfer->bridge_transfer_id) {
            return;
        }

        if ($transfer->retry_until !== null && now()->greaterThan($transfer->retry_until)) {
            $this->refundFailedTransfer(
                $transfer,
                'Transfer could not be completed within 24 hours.',
            );

            return;
        }

        $integration = $transfer->bridgeIntegration;
        $user = $transfer->user;

        if ($integration === null || $user === null) {
            return;
        }

        $recipientWallet = $this->bridgeService->resolveCustomerBridgeWallet($integration);
        if ($recipientWallet === null) {
            return;
        }

        $prefundedAccountId = $this->settings->prefundedAccountId();

        $bridgeResult = $this->bridgeService->createPrefundedWalletTransfer(
            $this->settings->prefundedCustomerId(),
            $this->settings->prefundedWalletId(),
            (string) $integration->bridge_customer_id,
            $recipientWallet['wallet_id'],
            (float) $transfer->amount,
            $transfer->idempotency_key,
            $prefundedAccountId !== '' ? $prefundedAccountId : null,
            $this->settings->prefundedAccountName() !== '' ? $this->settings->prefundedAccountName() : null,
        );

        if ($bridgeResult['success'] ?? false) {
            $this->markTransferSubmitted($transfer, $bridgeResult);
            $this->recordWalletTransferLedger($transfer->fresh());

            return;
        }

        if ($this->shouldQueueForLiquidityRetry($bridgeResult)) {
            return;
        }

        $this->refundFailedTransfer(
            $transfer,
            (string) ($bridgeResult['error'] ?? $bridgeResult['message'] ?? 'Bridge transfer failed'),
        );
    }

    public function syncFromBridgeTransfer(string $bridgeTransferId, string $bridgeState): void
    {
        $transfer = BelievePointWalletTransfer::query()
            ->where('bridge_transfer_id', $bridgeTransferId)
            ->whereNotIn('status', [
                BelievePointWalletTransfer::STATUS_COMPLETED,
                BelievePointWalletTransfer::STATUS_REFUNDED,
            ])
            ->first();

        if ($transfer === null) {
            return;
        }

        $this->applyBridgeTransferState($transfer, $bridgeState);
    }

    /**
     * Poll Bridge for submitted transfers that never received a terminal webhook.
     */
    public function reconcileSubmittedTransfers(?int $userId = null, int $limit = 50): int
    {
        $updated = 0;

        $query = BelievePointWalletTransfer::query()
            ->where('status', BelievePointWalletTransfer::STATUS_SUBMITTED)
            ->whereNotNull('bridge_transfer_id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $query->orderByDesc('updated_at')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (BelievePointWalletTransfer $transfer) use (&$updated) {
                $bridgeTransferId = trim((string) ($transfer->bridge_transfer_id ?? ''));
                if ($bridgeTransferId === '') {
                    return;
                }

                $result = $this->bridgeService->getTransfer($bridgeTransferId);
                if (! ($result['success'] ?? false)) {
                    return;
                }

                $data = is_array($result['data'] ?? null) ? $result['data'] : [];
                $state = (string) ($data['state'] ?? $data['status'] ?? '');
                if ($state === '') {
                    return;
                }

                $before = $transfer->status;
                $this->applyBridgeTransferState($transfer, $state);

                if ($transfer->fresh()->status !== $before) {
                    $updated++;
                }
            });

        return $updated;
    }

    private function applyBridgeTransferState(BelievePointWalletTransfer $transfer, string $bridgeState): void
    {
        if (in_array($transfer->status, [
            BelievePointWalletTransfer::STATUS_COMPLETED,
            BelievePointWalletTransfer::STATUS_REFUNDED,
        ], true)) {
            return;
        }

        $normalized = strtolower(trim($bridgeState));
        $transfer->bridge_transfer_state = $normalized;

        if ($this->isTerminalSuccessBridgeState($normalized)) {
            $transfer->status = BelievePointWalletTransfer::STATUS_COMPLETED;
            $transfer->completed_at = $transfer->completed_at ?? now();
            $transfer->save();

            return;
        }

        if ($this->isTerminalFailureBridgeState($normalized)) {
            $this->refundFailedTransfer($transfer, 'Bridge transfer '.$normalized);

            return;
        }

        $transfer->save();
    }

    /**
     * Prefunded wallet-to-wallet transfers often land in the wallet before payment_processed.
     *
     * @return array<int, string>
     */
    private function terminalSuccessBridgeStates(): array
    {
        return [
            'payment_processed',
            'completed',
            'settled',
            'funds_received',
        ];
    }

    private function isTerminalSuccessBridgeState(string $state): bool
    {
        return in_array($state, $this->terminalSuccessBridgeStates(), true);
    }

    private function isTerminalFailureBridgeState(string $state): bool
    {
        return in_array($state, ['failed', 'cancelled', 'canceled', 'returned', 'refunded'], true);
    }

    /**
     * @param  array<string, mixed>  $bridgeResult
     */
    private function applyBridgeTransferStateFromResult(BelievePointWalletTransfer $transfer, array $bridgeResult): void
    {
        $state = $this->bridgeStateFromResult($bridgeResult);
        if ($state === null || $state === '') {
            return;
        }

        $this->applyBridgeTransferState($transfer, $state);
    }

    /**
     * Deduct purchased BP and create a pending transfer row. Must complete before Bridge is called.
     *
     * @return array{success: bool, transfer?: BelievePointWalletTransfer, message?: string, error_code?: string}
     */
    private function reserveTransferPoints(
        User $user,
        BridgeIntegration $integration,
        float $amount,
        string $idempotencyKey,
        string $recipientWalletId,
    ): array {
        try {
            $transfer = DB::transaction(function () use ($user, $integration, $amount, $idempotencyKey, $recipientWalletId) {
                $existing = BelievePointWalletTransfer::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
                $availablePurchased = $lockedUser->purchasedBelievePointsBalance();

                if ($availablePurchased + 0.000001 < $amount) {
                    throw new \RuntimeException('INSUFFICIENT_BELIEVE_POINTS');
                }

                $lockedUser->decrement('believe_points', $amount);
                $lockedUser->clampGiftBelievePointsReporting();

                $record = BelievePointWalletTransfer::query()->create([
                    'user_id' => $lockedUser->id,
                    'bridge_integration_id' => $integration->id,
                    'amount' => $amount,
                    'status' => BelievePointWalletTransfer::STATUS_PENDING,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => [
                        'recipient_customer_id' => $integration->bridge_customer_id,
                        'recipient_wallet_id' => $recipientWalletId,
                        'reserved_at' => now()->toIso8601String(),
                    ],
                ]);

                $this->recordWalletTransferLedger($record);

                return $record;
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_BELIEVE_POINTS') {
                return [
                    'success' => false,
                    'message' => 'Insufficient purchased Believe Points. Gift BP can only be spent on gift cards.',
                    'error_code' => 'INSUFFICIENT_BELIEVE_POINTS',
                ];
            }

            throw $e;
        }

        return [
            'success' => true,
            'transfer' => $transfer,
        ];
    }

    /**
     * Call Bridge only after BP is already deducted for this transfer row.
     * Never refund after Bridge reports success — leave pending for retry/reconcile instead.
     *
     * @return array{success: bool, message?: string, data?: array<string, mixed>, error_code?: string}
     */
    private function disburseReservedTransfer(
        BelievePointWalletTransfer $transfer,
        BridgeIntegration $integration,
        User $user,
    ): array {
        if ($transfer->status !== BelievePointWalletTransfer::STATUS_PENDING || $transfer->bridge_transfer_id) {
            return $this->responseFromTransferRecord($transfer, $user);
        }

        $metadata = is_array($transfer->metadata) ? $transfer->metadata : [];
        $recipientWalletId = (string) ($metadata['recipient_wallet_id'] ?? '');
        if ($recipientWalletId === '') {
            $resolved = $this->bridgeService->resolveCustomerBridgeWallet($integration);
            $recipientWalletId = (string) ($resolved['wallet_id'] ?? '');
        }

        if ($recipientWalletId === '') {
            $this->refundFailedTransfer($transfer, 'Recipient Bridge wallet missing after reservation.');

            return [
                'success' => false,
                'message' => 'Your Bridge wallet is not ready yet. Complete wallet setup first.',
                'error_code' => 'BRIDGE_WALLET_REQUIRED',
            ];
        }

        $prefundedAccountId = $this->settings->prefundedAccountId();

        try {
            $bridgeResult = $this->bridgeService->createPrefundedWalletTransfer(
                $this->settings->prefundedCustomerId(),
                $this->settings->prefundedWalletId(),
                (string) $integration->bridge_customer_id,
                $recipientWalletId,
                (float) $transfer->amount,
                $transfer->idempotency_key,
                $prefundedAccountId !== '' ? $prefundedAccountId : null,
                $this->settings->prefundedAccountName() !== '' ? $this->settings->prefundedAccountName() : null,
            );
        } catch (\Throwable $e) {
            // Do not refund here: Bridge may have accepted before the client errored.
            // Keep pending so the same idempotency key can be retried safely.
            Log::error('Believe Points wallet transfer Bridge call threw after BP reserved', [
                'transfer_id' => $transfer->id,
                'user_id' => $user->id,
                'amount' => $transfer->amount,
                'error' => $e->getMessage(),
            ]);

            $transfer->update([
                'retry_until' => $transfer->retry_until ?? now()->addHours(self::LIQUIDITY_RETRY_HOURS),
                'metadata' => array_merge($metadata, [
                    'last_bridge_exception' => $e->getMessage(),
                    'queued_at' => now()->toIso8601String(),
                ]),
            ]);

            return $this->pendingUserResponse($transfer->fresh(), $user);
        }

        if ($bridgeResult['success'] ?? false) {
            try {
                $this->markTransferSubmitted($transfer, $bridgeResult);
                $this->recordWalletTransferLedger($transfer->fresh());
            } catch (\Throwable $e) {
                // BP already deducted and Bridge already accepted — do not refund.
                Log::error('Believe Points wallet transfer Bridge succeeded but local submit update failed', [
                    'transfer_id' => $transfer->id,
                    'user_id' => $user->id,
                    'bridge_transfer_id' => $this->bridgeTransferIdFromResult($bridgeResult),
                    'error' => $e->getMessage(),
                ]);
            }

            $transfer->refresh();
            $snapshot = $this->bridgeWalletReadService->getWalletSnapshot($integration);

            return [
                'success' => true,
                'message' => 'Your wallet is being funded. Funds will appear when Bridge confirms the transfer.',
                'data' => [
                    'transfer_id' => $transfer->id,
                    'bridge_transfer_id' => $transfer->bridge_transfer_id,
                    'status' => $transfer->status,
                    'bridge_state' => $transfer->bridge_transfer_state,
                    'amount' => (float) $transfer->amount,
                    'believe_points_balance' => round((float) $user->fresh()->believe_points, 2),
                    'wallet_balance' => $snapshot['balance'] ?? null,
                ],
            ];
        }

        if ($this->shouldQueueForLiquidityRetry($bridgeResult)) {
            Log::info('Believe Points wallet transfer awaiting reserve liquidity', [
                'transfer_id' => $transfer->id,
                'user_id' => $user->id,
                'amount' => $transfer->amount,
                'bridge_error' => $bridgeResult['error'] ?? null,
                'bridge_error_code' => $bridgeResult['error_code'] ?? null,
            ]);

            $transfer->update([
                'retry_until' => $transfer->retry_until ?? now()->addHours(self::LIQUIDITY_RETRY_HOURS),
                'metadata' => array_merge($metadata, [
                    'awaiting_liquidity' => true,
                    'queued_at' => now()->toIso8601String(),
                    'last_bridge_error' => $bridgeResult['error'] ?? $bridgeResult['message'] ?? null,
                ]),
            ]);

            return $this->pendingUserResponse($transfer->fresh(), $user);
        }

        Log::warning('Believe Points wallet transfer rejected by Bridge after BP reserved', [
            'transfer_id' => $transfer->id,
            'user_id' => $user->id,
            'amount' => $transfer->amount,
            'reserve_customer_id' => $this->settings->prefundedCustomerId(),
            'reserve_wallet_id' => $this->settings->prefundedWalletId(),
            'recipient_customer_id' => $integration->bridge_customer_id,
            'recipient_wallet_id' => $recipientWalletId,
            'bridge_error' => $bridgeResult['error'] ?? null,
            'bridge_error_code' => $bridgeResult['error_code'] ?? null,
            'bridge_response' => $bridgeResult['response'] ?? null,
        ]);

        $this->refundFailedTransfer(
            $transfer,
            (string) ($bridgeResult['error'] ?? $bridgeResult['message'] ?? 'Bridge transfer failed'),
        );

        return [
            'success' => false,
            'message' => 'We could not complete your transfer right now. Your Believe Points were restored.',
            'error_code' => (string) ($bridgeResult['error_code'] ?? 'BRIDGE_TRANSFER_FAILED'),
            'data' => [
                'believe_points_balance' => round((float) $user->fresh()->believe_points, 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $bridgeResult
     */
    private function markTransferSubmitted(BelievePointWalletTransfer $transfer, array $bridgeResult): void
    {
        $transfer->update([
            'status' => BelievePointWalletTransfer::STATUS_SUBMITTED,
            'bridge_transfer_id' => $this->bridgeTransferIdFromResult($bridgeResult),
            'bridge_transfer_state' => $this->bridgeStateFromResult($bridgeResult),
            'retry_until' => null,
            'metadata' => array_merge($transfer->metadata ?? [], [
                'bridge_response' => $bridgeResult['data'] ?? [],
                'awaiting_liquidity' => false,
            ]),
        ]);

        $this->applyBridgeTransferStateFromResult($transfer->fresh(), $bridgeResult);

        $transfer->refresh();
        $bridgeTransferId = (string) ($transfer->bridge_transfer_id ?? '');
        if ($bridgeTransferId !== '') {
            $this->bridgeWalletNotifier->notifyTransferWebhook(
                is_array($bridgeResult['data'] ?? null) ? $bridgeResult['data'] : ['id' => $bridgeTransferId, 'amount' => $transfer->amount],
                (string) ($transfer->bridge_transfer_state ?? 'pending'),
                'transfer.created',
            );
        }
    }

    private function recordWalletTransferLedger(BelievePointWalletTransfer $transfer): void
    {
        BelievePointsWalletLedgerService::recordWalletTransfer($transfer);
    }

    /**
     * @param  array<string, mixed>  $bridgeResult
     */
    private function bridgeTransferIdFromResult(array $bridgeResult): ?string
    {
        $bridgeTransferId = (string) ($bridgeResult['data']['id'] ?? $bridgeResult['data']['transfer_id'] ?? '');

        return $bridgeTransferId !== '' ? $bridgeTransferId : null;
    }

    /**
     * @param  array<string, mixed>  $bridgeResult
     */
    private function bridgeStateFromResult(array $bridgeResult): ?string
    {
        $bridgeState = (string) ($bridgeResult['data']['state'] ?? $bridgeResult['data']['status'] ?? 'pending');

        return $bridgeState !== '' ? $bridgeState : null;
    }

    private function userHasPurchasedBalance(User $user, float $amount): bool
    {
        return $user->purchasedBelievePointsBalance() + 0.000001 >= $amount;
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string, mixed>}
     */
    private function pendingUserResponse(BelievePointWalletTransfer $transfer, User $user): array
    {
        return [
            'success' => true,
            'message' => 'Your transfer is pending. Funds will be added to your wallet within 24 hours.',
            'data' => [
                'transfer_id' => $transfer->id,
                'status' => $transfer->status,
                'amount' => (float) $transfer->amount,
                'retry_until' => $transfer->retry_until?->toIso8601String(),
                'believe_points_balance' => round((float) $user->fresh()->believe_points, 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $bridgeResult
     */
    private function shouldQueueForLiquidityRetry(array $bridgeResult): bool
    {
        $errorCode = strtolower((string) ($bridgeResult['error_code'] ?? ''));
        if ($errorCode === 'insufficient_prefunded_balance') {
            return true;
        }

        $error = strtolower((string) ($bridgeResult['error'] ?? $bridgeResult['message'] ?? ''));

        return str_contains($error, 'insufficient')
            || str_contains($error, 'liquidity')
            || str_contains($error, 'not enough')
            || str_contains($error, 'available balance')
            || str_contains($error, 'temporarily unavailable');
    }

    private function refundFailedTransfer(BelievePointWalletTransfer $transfer, string $reason): void
    {
        if ($transfer->status === BelievePointWalletTransfer::STATUS_REFUNDED) {
            return;
        }

        DB::transaction(function () use ($transfer, $reason) {
            $locked = BelievePointWalletTransfer::query()->lockForUpdate()->find($transfer->id);
            if ($locked === null || $locked->status === BelievePointWalletTransfer::STATUS_REFUNDED) {
                return;
            }

            // Do not reverse BP after a completed Bridge credit.
            if ($locked->status === BelievePointWalletTransfer::STATUS_COMPLETED) {
                return;
            }

            $user = User::query()->lockForUpdate()->find($locked->user_id);
            if ($user !== null) {
                $user->increment('believe_points', (float) $locked->amount);
                BelievePointsWalletLedgerService::recordWalletTransferRefund($locked, $reason);
            }

            $locked->update([
                'status' => BelievePointWalletTransfer::STATUS_REFUNDED,
                'failure_message' => $reason,
                'retry_until' => null,
            ]);
        });

        Log::warning('Believe Points wallet transfer refunded', [
            'transfer_id' => $transfer->id,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string, mixed>}
     */
    private function responseFromTransferRecord(BelievePointWalletTransfer $transfer, ?User $user = null): array
    {
        $user ??= $transfer->user;

        return [
            'success' => ! in_array($transfer->status, [
                BelievePointWalletTransfer::STATUS_FAILED,
                BelievePointWalletTransfer::STATUS_REFUNDED,
            ], true),
            'message' => match ($transfer->status) {
                BelievePointWalletTransfer::STATUS_COMPLETED => 'Transfer already completed.',
                BelievePointWalletTransfer::STATUS_SUBMITTED => 'Transfer already submitted.',
                BelievePointWalletTransfer::STATUS_PENDING => 'Your transfer is pending. Funds will be added to your wallet within 24 hours.',
                BelievePointWalletTransfer::STATUS_REFUNDED => 'Your transfer could not be completed. Your Believe Points were restored.',
                default => 'Transfer is being processed.',
            },
            'data' => [
                'transfer_id' => $transfer->id,
                'bridge_transfer_id' => $transfer->bridge_transfer_id,
                'status' => $transfer->status,
                'bridge_state' => $transfer->bridge_transfer_state,
                'amount' => (float) $transfer->amount,
                'retry_until' => $transfer->retry_until?->toIso8601String(),
                'believe_points_balance' => $user ? round((float) $user->fresh()->believe_points, 2) : null,
            ],
        ];
    }
}

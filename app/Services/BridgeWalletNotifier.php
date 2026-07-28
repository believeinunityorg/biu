<?php

namespace App\Services;

use App\Enums\PushNotificationModule;
use App\Events\WalletBridgeUpdated;
use App\Models\BridgeIntegration;
use App\Models\BridgeWallet;
use App\Models\Organization;
use App\Models\User;
use App\Support\ShortChannelNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Push + Reverb notifications for Bridge-first wallets (no local ledger).
 *
 * @see https://apidocs.bridge.xyz/platform/orchestration/transfers/transfer-states
 * @see https://apidocs.bridge.xyz/platform/orchestration/virtual_accounts/virtual-account-events
 */
class BridgeWalletNotifier
{
    public function __construct(
        private readonly FirebaseService $firebaseService,
    ) {}

    /**
     * @param  array<string, mixed>  $transfer
     */
    public function notifyTransferWebhook(array $transfer, ?string $state, string $eventType = 'transfer.updated'): void
    {
        $transferId = (string) ($transfer['id'] ?? '');
        if ($transferId === '') {
            return;
        }

        $state = strtolower(trim((string) ($state ?? $transfer['state'] ?? $transfer['status'] ?? 'pending')));
        $amount = round((float) ($transfer['amount'] ?? 0), 2);
        $source = is_array($transfer['source'] ?? null) ? $transfer['source'] : [];
        $destination = is_array($transfer['destination'] ?? null) ? $transfer['destination'] : [];

        $senderIntegration = $this->resolveIntegrationForCustomerId(
            (string) ($transfer['on_behalf_of'] ?? $transfer['customer_id'] ?? '')
        ) ?? $this->resolveIntegrationForWalletEndpoint($source);

        $recipientIntegration = $this->resolveIntegrationForWalletEndpoint($destination);

        $mappedStatus = $this->mapTransferStateToUiStatus($state);
        $recipientShouldRefreshBalance = in_array($mappedStatus, ['completed', 'failed', 'cancelled'], true);

        if ($senderIntegration !== null) {
            $this->notifyIntegrationUsers($senderIntegration, [
                'kind' => 'transfer',
                'event' => $eventType,
                'transfer_id' => $transferId,
                'bridge_state' => $mappedStatus,
                'bridge_transfer_state' => $state,
                'status' => $mappedStatus,
                'amount' => $amount,
                'direction' => 'outgoing',
                'counterparty_name' => $this->resolveEndpointDisplayName($destination),
                'refresh_balance' => true,
                'refresh_activity' => true,
            ], $this->buildTransferPushMessage($state, $amount, 'outgoing', $destination));
        }

        if ($recipientIntegration !== null
            && ($senderIntegration === null || $recipientIntegration->id !== $senderIntegration->id)) {
            $this->notifyIntegrationUsers($recipientIntegration, [
                'kind' => 'transfer',
                'event' => $eventType,
                'transfer_id' => $transferId,
                'bridge_state' => $mappedStatus,
                'bridge_transfer_state' => $state,
                'status' => $mappedStatus,
                'amount' => $amount,
                'direction' => 'incoming',
                'counterparty_name' => $this->resolveEndpointDisplayName($source),
                'refresh_balance' => $recipientShouldRefreshBalance,
                'refresh_activity' => true,
            ], $this->buildTransferPushMessage($state, $amount, 'incoming', $source));
        } elseif ($recipientIntegration === null) {
            Log::warning('Bridge transfer webhook: recipient integration not resolved', [
                'transfer_id' => $transferId,
                'state' => $state,
                'destination_wallet_id' => $destination['bridge_wallet_id'] ?? null,
                'destination_customer_id' => $destination['customer_id'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function notifyVirtualAccountActivity(BridgeIntegration $integration, array $event, string $activityType): void
    {
        $activityType = strtolower($activityType);
        $amount = round((float) ($event['amount'] ?? 0), 2);
        $activityId = (string) ($event['id'] ?? $event['activity_id'] ?? '');
        $depositId = isset($event['deposit_id']) ? (string) $event['deposit_id'] : '';
        $mappedStatus = in_array($activityType, ['payment_processed'], true) ? 'completed' : 'pending';

        $this->notifyIntegrationUsers($integration, [
            'kind' => 'virtual_account',
            'event' => 'virtual_account.activity',
            'activity_id' => $activityId,
            'deposit_id' => $depositId !== '' ? $depositId : null,
            'activity_type' => $activityType,
            'bridge_state' => $activityType,
            'status' => $mappedStatus,
            'amount' => $amount,
            'direction' => 'incoming',
            'refresh_balance' => $mappedStatus === 'completed',
            'refresh_activity' => true,
        ], $this->buildDepositPushMessage($activityType, $amount));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function notifyWalletActivity(BridgeIntegration $integration, array $event): void
    {
        $type = strtolower((string) ($event['type'] ?? ''));
        $amount = round((float) ($event['amount'] ?? 0), 2);
        $activityId = (string) ($event['id'] ?? '');
        $paymentRoute = is_array($event['payment_route'] ?? null) ? $event['payment_route'] : [];
        $transferId = isset($paymentRoute['transfer_id']) ? (string) $paymentRoute['transfer_id'] : '';
        $isOutgoing = in_array($type, ['withdrawal', 'withdraw', 'card_spend'], true);
        $mappedStatus = in_array($type, ['return', 'undeliverable'], true) ? 'failed' : 'completed';

        $this->notifyIntegrationUsers($integration, [
            'kind' => 'wallet_activity',
            'event' => 'bridge_wallet.activity',
            'activity_id' => $activityId,
            'transfer_id' => $transferId !== '' ? $transferId : null,
            'activity_type' => $type,
            'bridge_state' => $mappedStatus,
            'bridge_event_type' => $type,
            'status' => $mappedStatus,
            'amount' => $amount,
            'direction' => $isOutgoing ? 'outgoing' : 'incoming',
            'refresh_balance' => ! $isOutgoing || $mappedStatus === 'completed',
            'refresh_activity' => true,
        ], $this->buildWalletActivityPushMessage($type, $amount, $isOutgoing));
    }

    /**
     * Notify recipient immediately when a wallet-to-wallet send is submitted.
     *
     * @param  array<string, mixed>  $transferPayload
     */
    public function notifyOutgoingTransferCreated(
        BridgeIntegration $senderIntegration,
        BridgeIntegration $recipientIntegration,
        array $transferPayload,
        float $amount,
        string $senderName,
    ): void {
        $transferId = (string) ($transferPayload['id'] ?? $transferPayload['transfer_id'] ?? '');

        $this->notifyIntegrationUsers($senderIntegration, [
            'kind' => 'transfer',
            'event' => 'transfer.created',
            'transfer_id' => $transferId,
            'bridge_state' => 'pending',
            'bridge_transfer_state' => 'awaiting_funds',
            'status' => 'pending',
            'amount' => $amount,
            'direction' => 'outgoing',
            'counterparty_name' => $this->integratableName($recipientIntegration),
            'refresh_balance' => true,
            'refresh_activity' => true,
        ], null);

        $this->notifyIntegrationUsers($recipientIntegration, [
            'kind' => 'transfer',
            'event' => 'transfer.incoming_pending',
            'transfer_id' => $transferId,
            'bridge_state' => 'pending',
            'bridge_transfer_state' => 'awaiting_funds',
            'status' => 'pending',
            'amount' => $amount,
            'direction' => 'incoming',
            'counterparty_name' => $senderName !== '' ? $senderName : $this->integratableName($senderIntegration),
            'refresh_balance' => false,
            'refresh_activity' => true,
        ], [
            'title' => 'Incoming transfer',
            'body' => ($senderName !== '' ? $senderName : 'Someone')
                .' sent you $'.number_format($amount, 2).' — processing.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{title: string, body: string}|null  $push
     */
    private function notifyIntegrationUsers(
        BridgeIntegration $integration,
        array $payload,
        ?array $push,
    ): void {
        foreach ($this->resolveIntegrationUserIds($integration) as $userId) {
            $payload['user_id'] = $userId;
            $payload['integration_id'] = $integration->id;
            $payload['at'] = now()->toIso8601String();

            try {
                broadcast(new WalletBridgeUpdated($userId, $payload));
            } catch (\Throwable $e) {
                Log::warning('Bridge wallet Reverb broadcast failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($push !== null && $this->shouldSendPush($userId, $payload)) {
                $this->sendPush($userId, $push['title'], $push['body'], $payload);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldSendPush(int $userId, array $payload): bool
    {
        $status = (string) ($payload['status'] ?? '');
        $bridgeState = (string) ($payload['bridge_state'] ?? '');
        $transferState = (string) ($payload['bridge_transfer_state'] ?? $bridgeState);
        $direction = (string) ($payload['direction'] ?? '');
        $transferId = (string) ($payload['transfer_id'] ?? '');
        $activityId = (string) ($payload['activity_id'] ?? '');

        $notifyStates = [
            'payment_processed', 'completed',
            'funds_scheduled', 'funds_received', 'payment_submitted',
            'awaiting_funds', 'in_review',
        ];

        if ($status === 'completed' || in_array($transferState, ['payment_processed', 'completed'], true)) {
            $key = 'bridge_wallet_push:'.$userId.':'.($transferId ?: $activityId).':'.$transferState.':completed';

            return Cache::add($key, 1, now()->addDays(30));
        }

        if ($direction === 'incoming' && in_array($transferState, $notifyStates, true)) {
            $key = 'bridge_wallet_push:'.$userId.':'.($transferId ?: $activityId).':'.$transferState;

            return Cache::add($key, 1, now()->addDays(7));
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendPush(int $userId, string $title, string $body, array $payload): void
    {
        try {
            $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::WalletRewards);
            $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::WalletRewards);
            $pushLinks = ShortChannelNotification::pushDeepLinkData();

            $pushData = $this->firebaseService->stringifyFcmData([
                'type' => 'bridge_wallet_'.($payload['kind'] ?? 'update'),
                'title' => $pushTitle,
                'body' => $pushBody,
                'url' => $pushLinks['url'],
                'click_action' => $pushLinks['click_action'],
                'bridge_state' => (string) ($payload['bridge_state'] ?? ''),
                'transfer_id' => (string) ($payload['transfer_id'] ?? ''),
                'amount' => (string) ($payload['amount'] ?? ''),
                'direction' => (string) ($payload['direction'] ?? ''),
                'source_type' => 'bridge_wallet',
                'module_name' => PushNotificationModule::WalletRewards->value,
                'deep_link' => $pushLinks['deep_link'],
            ]);

            $this->firebaseService->sendToUser($userId, $pushTitle, $pushBody, $pushData);
        } catch (\Throwable $e) {
            Log::warning('Bridge wallet push notification failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, int>
     */
    private function resolveIntegrationUserIds(BridgeIntegration $integration): array
    {
        $integration->loadMissing('integratable');
        $entity = $integration->integratable;
        $ids = [];

        if ($entity instanceof User) {
            $ids[] = (int) $entity->id;
        } elseif ($entity instanceof Organization) {
            $ids = $entity->walletNotificationUserIds();
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function resolveIntegrationForCustomerId(string $customerId): ?BridgeIntegration
    {
        if ($customerId === '') {
            return null;
        }

        return BridgeIntegration::query()
            ->where('bridge_customer_id', $customerId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    public function resolveIntegrationForWalletEndpoint(array $endpoint): ?BridgeIntegration
    {
        $walletId = (string) ($endpoint['bridge_wallet_id'] ?? '');
        if ($walletId !== '') {
            $integration = $this->findIntegrationByBridgeWalletId($walletId);
            if ($integration !== null) {
                return $integration;
            }
        }

        $customerId = trim((string) ($endpoint['customer_id'] ?? ''));
        if ($customerId !== '') {
            $byCustomer = $this->resolveIntegrationForCustomerId($customerId);
            if ($byCustomer !== null) {
                return $byCustomer;
            }
        }

        $address = strtolower(trim((string) ($endpoint['to_address'] ?? $endpoint['from_address'] ?? '')));
        if ($address === '') {
            return null;
        }

        $wallet = BridgeWallet::query()
            ->whereRaw('LOWER(wallet_address) = ?', [$address])
            ->with('bridgeIntegration')
            ->first();

        return $wallet?->bridgeIntegration;
    }

    private function findIntegrationByBridgeWalletId(string $walletId): ?BridgeIntegration
    {
        if ($walletId === '') {
            return null;
        }

        $direct = BridgeIntegration::query()
            ->where('bridge_wallet_id', $walletId)
            ->first();

        if ($direct !== null) {
            return $direct;
        }

        $wallet = BridgeWallet::query()
            ->where('bridge_wallet_id', $walletId)
            ->with('bridgeIntegration')
            ->first();

        if ($wallet?->bridgeIntegration !== null) {
            return $wallet->bridgeIntegration;
        }

        return BridgeIntegration::query()
            ->where(function ($query) use ($walletId) {
                $query->whereHas('wallets', fn ($q) => $q->where('bridge_wallet_id', $walletId))
                    ->orWhereHas('primaryWallet', fn ($q) => $q->where('bridge_wallet_id', $walletId));
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    private function resolveEndpointDisplayName(array $endpoint): string
    {
        foreach (['sender_name', 'bank_beneficiary_name', 'account_holder_name'] as $key) {
            $name = trim((string) ($endpoint[$key] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $walletId = (string) ($endpoint['bridge_wallet_id'] ?? '');
        if ($walletId !== '') {
            $integration = $this->findIntegrationByBridgeWalletId($walletId);
            if ($integration !== null) {
                $name = $this->integratableName($integration);
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return '';
    }

    private function integratableName(BridgeIntegration $integration): string
    {
        $integration->loadMissing('integratable');
        $entity = $integration->integratable;
        if ($entity instanceof User || $entity instanceof Organization) {
            return trim((string) $entity->name);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $counterpartyEndpoint
     * @return array{title: string, body: string}|null
     */
    private function buildTransferPushMessage(
        string $state,
        float $amount,
        string $direction,
        array $counterpartyEndpoint,
    ): ?array {
        $amountLabel = '$'.number_format($amount, 2);
        $name = $this->resolveEndpointDisplayName($counterpartyEndpoint);

        if ($state === 'payment_processed' || $state === 'completed') {
            if ($direction === 'incoming') {
                return [
                    'title' => 'Money received',
                    'body' => $name !== ''
                        ? "You received {$amountLabel} from {$name}."
                        : "You received {$amountLabel} in your wallet.",
                ];
            }

            return [
                'title' => 'Transfer completed',
                'body' => $name !== ''
                    ? "Your transfer of {$amountLabel} to {$name} completed."
                    : "Your transfer of {$amountLabel} completed.",
            ];
        }

        if ($direction === 'incoming' && in_array($state, ['awaiting_funds', 'funds_received', 'in_review', 'payment_submitted'], true)) {
            return [
                'title' => 'Incoming transfer processing',
                'body' => $name !== ''
                    ? "{$name} sent {$amountLabel} — funds are on the way."
                    : "You have {$amountLabel} incoming — processing.",
            ];
        }

        return null;
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function buildDepositPushMessage(string $activityType, float $amount): ?array
    {
        $amountLabel = '$'.number_format($amount, 2);

        if ($activityType === 'payment_processed') {
            return [
                'title' => 'Deposit received',
                'body' => "Your wallet received {$amountLabel} from a bank deposit.",
            ];
        }

        if (in_array($activityType, ['funds_scheduled', 'funds_received', 'payment_submitted'], true)) {
            return [
                'title' => 'Deposit processing',
                'body' => "A {$amountLabel} bank deposit is on the way to your wallet.",
            ];
        }

        return null;
    }

    /**
     * @return array{title: string, body: string}|null
     */
    private function buildWalletActivityPushMessage(string $type, float $amount, bool $isOutgoing): ?array
    {
        $amountLabel = '$'.number_format($amount, 2);

        if ($isOutgoing) {
            return [
                'title' => 'Wallet withdrawal',
                'body' => "{$amountLabel} was sent to your bank account.",
            ];
        }

        if (in_array($type, ['deposit', 'direct_deposit'], true)) {
            return [
                'title' => 'Wallet deposit',
                'body' => "{$amountLabel} was added to your Believe wallet.",
            ];
        }

        return null;
    }

    private function mapTransferStateToUiStatus(string $state): string
    {
        return match ($state) {
            'payment_processed', 'completed' => 'completed',
            'failed', 'returned', 'refunded', 'error', 'undeliverable' => 'failed',
            'canceled', 'cancelled' => 'cancelled',
            default => 'pending',
        };
    }
}

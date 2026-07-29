<?php

namespace App\Services;

use App\Models\BelievePointWalletTransfer;
use App\Models\BridgeIntegration;
use App\Models\BridgeWallet;
use App\Models\GiftCard;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Read wallet balance and activity directly from Bridge (source of truth).
 *
 * @see https://apidocs.bridge.xyz/platform/wallets/move-money
 * @see https://apidocs.bridge.xyz/platform/orchestration/virtual_accounts/virtual-account-events
 */
class BridgeWalletReadService
{
    /** @var array<string, string> */
    private array $walletOwnerNames = [];

    /** @var array<string, string> */
    private array $walletAddressOwners = [];

    /** @var array<string, string> */
    private array $customerNames = [];

    /** @var array<string, string> */
    private array $externalAccountLabels = [];

    /** @var array<string, BelievePointWalletTransfer|null> */
    private array $believePointWalletTransferByBridgeId = [];

    /** @var array<string, GiftCard|null> */
    private array $giftCardByBridgeTransferId = [];

    private bool $platformWalletIndexBuilt = false;

    /**
     * @var array{
     *     virtual_account_id: string|null,
     *     by_event_id: array<string, array<string, mixed>>,
     *     best_by_deposit_id: array<string, array<string, mixed>>
     * }|null
     */
    private ?array $virtualAccountEventContext = null;

    public function __construct(
        private readonly BridgeService $bridgeService,
    ) {}

    /**
     * @return array{
     *     balance: float,
     *     currency: string,
     *     wallet_id: string,
     *     chain: string|null,
     *     balances: array<int, array<string, mixed>>
     * }|null
     */
    public function getWalletSnapshot(BridgeIntegration $integration, ?string $customerIdOverride = null): ?array
    {
        $customerId = trim((string) ($customerIdOverride ?? $integration->bridge_customer_id ?? ''));
        if ($customerId === '') {
            return null;
        }

        $resolved = $this->bridgeService->resolveCustomerBridgeWallet($integration, $customerId);
        if ($resolved === null) {
            return null;
        }

        $walletResult = $this->bridgeService->getWallet($customerId, $resolved['wallet_id']);
        if (! ($walletResult['success'] ?? false) || ! is_array($walletResult['data'] ?? null)) {
            return null;
        }

        $data = $walletResult['data'];
        $primary = $this->bridgeService->resolvePrimaryStablecoinBalance($data);

        return [
            'balance' => $primary['balance'],
            'currency' => strtoupper($primary['currency']),
            'wallet_id' => $resolved['wallet_id'],
            'chain' => $data['chain'] ?? $resolved['chain'] ?? null,
            'balances' => is_array($data['balances'] ?? null) ? $data['balances'] : [],
        ];
    }

    public function getBalance(BridgeIntegration $integration): ?float
    {
        return $this->getWalletSnapshot($integration)['balance'] ?? null;
    }

    /**
     * Bridge-connected wallets use Bridge API only — never the local ledger (sandbox or production).
     */
    public function usesBridgeWalletAsSourceOfTruth(?BridgeIntegration $integration): bool
    {
        return $this->shouldUseBridgeActivity($integration);
    }

    /**
     * True when Bridge API can supply wallet activity for this integration (incl. org stubs resolved via context).
     */
    public function shouldUseBridgeActivity(?BridgeIntegration $integration): bool
    {
        if ($integration === null) {
            return false;
        }

        if (! empty($integration->bridge_customer_id)) {
            return true;
        }

        return $this->resolveActivityContext($integration) !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActivity(BridgeIntegration $integration, int $limit = 50): array
    {
        $context = $this->resolveActivityContext($integration);
        if ($context === null) {
            return [];
        }

        $customerId = $context['customer_id'];
        $primaryIntegration = $context['primary'];
        /** @var \Illuminate\Support\Collection<int, BridgeIntegration> $relatedIntegrations */
        $relatedIntegrations = $context['integrations'];

        $this->resetNameCaches();
        $this->ensurePlatformWalletOwnerIndex();

        $snapshot = $this->getWalletSnapshot($primaryIntegration, $customerId);
        $primaryWalletId = $snapshot['wallet_id'] ?? null;
        $walletIdsForTransfers = $this->resolveMergedWalletIds(
            $relatedIntegrations,
            $primaryWalletId,
            $primaryIntegration,
            $customerId,
        );
        $historyWalletIds = $this->resolveMergedBridgeWalletIdsForHistory($relatedIntegrations, $primaryWalletId, $customerId);
        $vaContext = $this->loadVirtualAccountEventContext($primaryIntegration, $customerId, $relatedIntegrations);

        $fetchLimit = max($limit, 200);
        $activities = [];

        foreach ($historyWalletIds as $historyWalletId) {
            $activities = array_merge(
                $activities,
                $this->mapWalletHistoryActivities($customerId, $historyWalletId, $vaContext, $fetchLimit),
            );
        }

        if ($historyWalletIds !== [] && ! $this->activitiesIncludeSource($activities, 'bridge_wallet_history')) {
            Log::warning('Bridge wallet history returned no mappable activities', [
                'customer_id' => $customerId,
                'wallet_ids' => $historyWalletIds,
                'integration_id' => $primaryIntegration->id,
            ]);
        }

        $activities = array_merge(
            $activities,
            $this->mapVirtualAccountActivities($vaContext),
        );

        if (($vaContext['best_by_deposit_id'] ?? []) === [] && ($vaContext['virtual_account_id'] ?? null) !== null) {
            Log::warning('Bridge virtual account history returned no deposit activities', [
                'customer_id' => $customerId,
                'integration_id' => $primaryIntegration->id,
                'virtual_account_id' => $vaContext['virtual_account_id'],
            ]);
        }

        if ($walletIdsForTransfers !== []) {
            $viewerWalletAddress = $this->resolveIntegrationWalletAddress($primaryIntegration, $customerId);
            $activities = array_merge(
                $activities,
                $this->mapTransferActivities(
                    $customerId,
                    $walletIdsForTransfers,
                    $this->collectOnRampTransferIds($activities),
                    $viewerWalletAddress,
                    $fetchLimit,
                ),
            );
        } else {
            $viewerWalletAddress = $this->resolveIntegrationWalletAddress($primaryIntegration, $customerId);
            $activities = array_merge(
                $activities,
                $this->mapTransferActivities(
                    $customerId,
                    [],
                    $this->collectOnRampTransferIds($activities),
                    $viewerWalletAddress,
                    $fetchLimit,
                ),
            );
        }

        usort($activities, fn (array $a, array $b) => strcmp($b['sort_date'] ?? '', $a['sort_date'] ?? ''));

        $deduped = $this->dedupeActivities($activities);
        $enriched = $this->enrichActivitiesWithVirtualAccountDetails($deduped, $vaContext);

        return array_slice($enriched, 0, max(1, $limit));
    }

    /**
     * Resolve Bridge customer + all integration rows that share wallets/VAs (org + owner user).
     *
     * @return array{
     *     customer_id: string,
     *     primary: BridgeIntegration,
     *     integrations: \Illuminate\Support\Collection<int, BridgeIntegration>
     * }|null
     */
    private function resolveActivityContext(BridgeIntegration $integration): ?array
    {
        $integration->loadMissing('integratable', 'primaryWallet', 'wallets');

        $customerId = trim((string) ($integration->bridge_customer_id ?? ''));

        if ($customerId === '' && $integration->integratable_type === Organization::class) {
            $organization = $integration->integratable;
            if ($organization instanceof Organization) {
                $withCustomer = BridgeIntegration::query()
                    ->where('integratable_type', Organization::class)
                    ->where('integratable_id', $organization->id)
                    ->whereNotNull('bridge_customer_id')
                    ->where('bridge_customer_id', '!=', '')
                    ->first();

                if ($withCustomer !== null) {
                    $customerId = (string) $withCustomer->bridge_customer_id;
                    $integration = $withCustomer;
                } elseif ($organization->user_id) {
                    $ownerIntegration = BridgeIntegration::query()
                        ->where('integratable_type', User::class)
                        ->where('integratable_id', $organization->user_id)
                        ->whereNotNull('bridge_customer_id')
                        ->where('bridge_customer_id', '!=', '')
                        ->first();

                    if ($ownerIntegration !== null) {
                        $customerId = (string) $ownerIntegration->bridge_customer_id;
                    }
                }
            }
        }

        if ($customerId === '') {
            return null;
        }

        $integrations = BridgeIntegration::query()
            ->where('bridge_customer_id', $customerId)
            ->with(['primaryWallet', 'wallets', 'integratable'])
            ->get();

        $organizationIds = collect();

        if ($integration->integratable_type === Organization::class) {
            $organizationIds->push((int) $integration->integratable_id);
        }

        foreach ($integrations as $row) {
            if ($row->integratable_type === Organization::class) {
                $organizationIds->push((int) $row->integratable_id);
            }
            if ($row->integratable_type === User::class && $row->integratable instanceof User) {
                $organizationIds = $organizationIds->merge(
                    Organization::query()
                        ->where('user_id', $row->integratable_id)
                        ->pluck('id')
                );
            }
        }

        if ($organizationIds->isNotEmpty()) {
            $orgRows = BridgeIntegration::query()
                ->where('integratable_type', Organization::class)
                ->whereIn('integratable_id', $organizationIds->unique()->filter()->values())
                ->with(['primaryWallet', 'wallets', 'integratable'])
                ->get();
            $integrations = $integrations->merge($orgRows)->unique('id')->values();
        }

        $scoreIntegration = function (BridgeIntegration $row): int {
            $score = 0;
            if (! empty($row->bridge_customer_id)) {
                $score += 8;
            }
            if (! empty($row->bridge_wallet_id)) {
                $score += 4;
            }
            if ($row->primaryWallet?->bridge_wallet_id) {
                $score += 2;
            }

            return $score + min($row->wallets->count(), 3);
        };

        $orgIntegrations = $integrations->filter(
            fn (BridgeIntegration $row) => $row->integratable_type === Organization::class
        );

        $primary = $orgIntegrations->sortByDesc($scoreIntegration)->first()
            ?? $integrations->sortByDesc($scoreIntegration)->first()
            ?? $integration;

        if (trim((string) ($primary->bridge_customer_id ?? '')) === '') {
            $withCustomer = $integrations->first(
                fn (BridgeIntegration $row) => ! empty($row->bridge_customer_id)
            );
            if ($withCustomer !== null) {
                $primary = $withCustomer;
            }
        }

        return [
            'customer_id' => $customerId,
            'primary' => $primary,
            'integrations' => $integrations,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BridgeIntegration>  $integrations
     * @return array<int, string>
     */
    private function resolveMergedWalletIds(
        $integrations,
        ?string $primaryWalletId,
        ?BridgeIntegration $primaryIntegration = null,
        ?string $customerId = null,
    ): array {
        $primary = $primaryIntegration ?? $integrations->first();
        $ids = [];

        if ($primaryWalletId !== null && $primaryWalletId !== '' && $primary instanceof BridgeIntegration) {
            $ids = array_merge($ids, $this->resolveIntegrationWalletIds($primary, $primaryWalletId));
        }

        foreach ($integrations as $integration) {
            if ($primary instanceof BridgeIntegration && $integration->id === $primary->id) {
                continue;
            }

            $ids = array_merge($ids, $this->resolveIntegrationWalletIds($integration, null));
        }

        $customerId = trim((string) ($customerId ?? ''));
        if ($customerId !== '') {
            $wallets = $this->bridgeService->normalizeBridgeListData(
                $this->bridgeService->getWallets($customerId)
            );

            foreach ($wallets as $wallet) {
                if (! empty($wallet['id'])) {
                    $ids[] = (string) $wallet['id'];
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BridgeIntegration>  $integrations
     * @return array<int, string>
     */
    private function resolveMergedBridgeWalletIdsForHistory($integrations, ?string $primaryWalletId, string $customerId): array
    {
        $ids = [];

        if ($primaryWalletId !== null && $primaryWalletId !== '') {
            $ids[] = $primaryWalletId;
        }

        foreach ($integrations as $integration) {
            $ids = array_merge($ids, $this->resolveBridgeWalletIdsForHistory($integration, null, $customerId));
        }

        $wallets = $this->bridgeService->normalizeBridgeListData(
            $this->bridgeService->getWallets($customerId)
        );
        foreach ($wallets as $wallet) {
            if (! empty($wallet['id'])) {
                $ids[] = (string) $wallet['id'];
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $activities
     * @return array<int, string>
     */
    private function collectOnRampTransferIds(array $activities): array
    {
        $ids = [];
        foreach ($activities as $activity) {
            $source = (string) ($activity['source'] ?? '');
            if ($source !== 'bridge_virtual_account') {
                continue;
            }

            $transferId = $activity['bridge_transfer_id'] ?? null;
            if (is_string($transferId) && $transferId !== '') {
                $ids[] = $transferId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Collapse activities that describe the same Bridge deposit/transfer.
     *
     * @param  array<int, array<string, mixed>>  $activities
     * @return array<int, array<string, mixed>>
     */
    private function dedupeActivities(array $activities): array
    {
        $bestByKey = [];

        foreach ($activities as $activity) {
            $key = (string) ($activity['dedupe_key'] ?? $activity['id'] ?? '');
            if ($key === '') {
                continue;
            }

            if (! isset($bestByKey[$key])
                || $this->activityRank($activity) > $this->activityRank($bestByKey[$key])) {
                $bestByKey[$key] = $activity;
            }
        }

        $byDepositId = [];
        $standalone = [];

        foreach ($bestByKey as $activity) {
            $depositId = $activity['deposit_id'] ?? null;
            $type = (string) ($activity['type'] ?? '');

            // Only collapse bank on-ramp lifecycle rows by deposit_id — not wallet transfers.
            if (is_string($depositId) && $depositId !== '' && $type === 'deposit') {
                if (! isset($byDepositId[$depositId])
                    || $this->activityRank($activity) > $this->activityRank($byDepositId[$depositId])) {
                    $byDepositId[$depositId] = $activity;
                }

                continue;
            }

            $standalone[(string) ($activity['id'] ?? uniqid('activity_', true))] = $activity;
        }

        $deduped = [];

        foreach (array_values($byDepositId) as $activity) {
            unset(
                $activity['sort_date'],
                $activity['dedupe_key'],
            );
            $deduped[] = $activity;
        }

        foreach (array_values($standalone) as $activity) {
            unset(
                $activity['sort_date'],
                $activity['dedupe_key'],
            );
            $deduped[] = $activity;
        }

        usort($deduped, fn (array $a, array $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        return $deduped;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function depositGroupTypeBonus(array $activity): int
    {
        return match ($activity['type'] ?? '') {
            'transfer_received' => 25,
            'transfer_sent' => 15,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function activityRank(array $activity): int
    {
        $statusRank = match ($activity['status'] ?? '') {
            'completed' => 300,
            'pending' => 100,
            'failed', 'cancelled' => 50,
            default => 0,
        };

        return $statusRank + ($this->activitySourcePriority($activity) * 10);
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    private function activitySourcePriority(array $activity): int
    {
        return match ($activity['source'] ?? '') {
            'bridge_wallet_history' => 3,
            'bridge_virtual_account' => 2,
            'bridge_transfer' => 1,
            default => 0,
        };
    }

    /**
     * @param  array{
     *     virtual_account_id: string|null,
     *     by_event_id: array<string, array<string, mixed>>,
     *     best_by_deposit_id: array<string, array<string, mixed>>
     * }  $vaContext
     * @return array<int, array<string, mixed>>
     */
    private function mapWalletHistoryActivities(
        string $customerId,
        string $walletId,
        array $vaContext,
        int $maxEvents = 100,
    ): array {
        $events = $this->fetchPaginatedBridgeList(
            fn (array $query) => $this->bridgeService->getBridgeWalletHistory($customerId, $walletId, $query),
            $maxEvents,
        );
        $activities = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $type = strtolower((string) ($event['type'] ?? ''));
            $amount = round((float) ($event['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $id = (string) ($event['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $date = $this->parseBridgeTimestamp($event['created_at'] ?? $event['updated_at'] ?? null);
            $isOutgoing = in_array($type, ['withdrawal', 'withdraw', 'card_spend'], true);
            $isFailedReturn = in_array($type, ['return', 'undeliverable'], true);
            $isDeposit = in_array($type, ['deposit', 'direct_deposit', 'card_refund'], true);

            if (! $isOutgoing && ! $isDeposit && ! $isFailedReturn) {
                continue;
            }

            $paymentRoute = is_array($event['payment_route'] ?? null) ? $event['payment_route'] : [];

            // ACH/VA on-ramp lifecycle is owned by mapVirtualAccountActivities (one row per deposit_id).
            if ($isDeposit && $this->isVirtualAccountOnRamp($paymentRoute)) {
                continue;
            }

            $routeType = strtolower((string) ($paymentRoute['type'] ?? ''));
            $depositId = isset($paymentRoute['deposit_id']) ? (string) $paymentRoute['deposit_id'] : null;
            $transferId = isset($paymentRoute['transfer_id']) ? (string) $paymentRoute['transfer_id'] : null;
            $vaEventId = isset($paymentRoute['virtual_account_event_id'])
                ? (string) $paymentRoute['virtual_account_event_id']
                : null;

            if ($isDeposit && $this->isVirtualAccountOnRamp($paymentRoute)) {
                $vaEvent = $this->resolveVirtualAccountEventForPaymentRoute($paymentRoute, $vaContext);
                if ($vaEvent !== null) {
                    if (($depositId === null || $depositId === '') && ! empty($vaEvent['deposit_id'])) {
                        $depositId = (string) $vaEvent['deposit_id'];
                    }
                    if (($vaEventId === null || $vaEventId === '') && ! empty($vaEvent['id'])) {
                        $vaEventId = (string) $vaEvent['id'];
                    }
                }
            }

            $dedupeKey = $this->buildDepositDedupeKey($depositId, $vaEventId, $transferId, $id);

            $counterpartyName = $this->resolveWalletHistoryCounterpartyName($event, $customerId, $isDeposit, $vaContext);

            if ($isDeposit) {
                $depositSourceLabel = $this->resolveDepositSourceLabel($event, $paymentRoute);
                $donorName = $counterpartyName ?? $depositSourceLabel;
                $depositMessage = $this->resolveVirtualAccountDepositMessage($event, $paymentRoute, $vaContext, $donorName);
            } elseif ($isFailedReturn) {
                $donorName = $counterpartyName ?? 'Bank';
                $depositMessage = 'Returned — '.$donorName;
            } elseif ($type === 'card_spend') {
                $donorName = $counterpartyName ?? 'Card merchant';
                $depositMessage = 'Card spend';
            } else {
                $donorName = $counterpartyName ?? 'Bank account';
                $depositMessage = $routeType === 'transfer'
                    ? 'Sent to '.$donorName
                    : ucfirst(str_replace('_', ' ', $type));
            }

            $activityType = $isDeposit ? 'deposit' : ($type === 'card_spend' ? 'card_spend' : 'transfer_sent');
            $activityOutgoing = $isOutgoing || $isFailedReturn;
            if ($isDeposit && $routeType === 'transfer') {
                // Bridge wallet history: deposit + payment_route.type=transfer is an incoming transfer.
                // @see https://apidocs.bridge.xyz/api-reference/bridge-wallets/get-transaction-history-for-a-bridge-wallet
                $activityType = 'transfer_received';
                $activityOutgoing = false;
                $donorName = $counterpartyName ?? 'Sender';
                $depositMessage = 'Received from '.$donorName;
            } elseif ($type === 'card_refund') {
                $activityType = 'deposit';
                $activityOutgoing = false;
                $donorName = $counterpartyName ?? 'Card refund';
                $depositMessage = 'Card refund';
            }

            $historyStatus = $isFailedReturn ? 'failed' : 'completed';
            $bridgeTransferState = null;
            $bridgeStateLabel = null;
            $paymentMethod = $isDeposit ? $this->resolveDepositPaymentMethod($event) : null;
            $paymentMethodLabel = $paymentMethod['label'] ?? null;
            $displayLabel = null;
            $transferDetails = null;

            if ($isOutgoing && $transferId !== null && $transferId !== '') {
                $transferDetails = $this->fetchTransferDetails($transferId);
                if ($transferDetails !== null) {
                    $statusMeta = $this->resolveTransferActivityStatus($transferDetails);
                    $historyStatus = $statusMeta['status'];
                    $bridgeTransferState = $statusMeta['bridge_transfer_state'];
                    $bridgeStateLabel = $statusMeta['bridge_state_label'];

                    if ($this->isExternalAccountOfframp($transferDetails)) {
                        $activityType = 'withdrawal';
                        $externalAccountId = (string) ($transferDetails['destination']['external_account_id'] ?? '');
                        $bankLabel = $this->resolveExternalAccountDisplayLabel($customerId, $externalAccountId) ?? 'Bank account';
                        $donorName = $bankLabel;
                        $rail = strtoupper((string) ($transferDetails['destination']['payment_rail'] ?? 'ach'));
                        $paymentMethodLabel = $this->formatWithdrawalRailLabel($rail);
                        $displayLabel = $this->buildWithdrawalDisplayLabel($rail, $bankLabel);
                        $depositMessage = $displayLabel;
                    }
                }
            }

            if (! isset($displayLabel)) {
                $displayLabel = $this->buildActivityDisplayLabel(
                    $activityType,
                    $donorName,
                    $paymentMethodLabel,
                );
            }

            $recipientType = null;
            if (($activityType === 'transfer_sent' || $activityType === 'withdrawal') && $transferId !== null && $transferId !== '') {
                $transferDetails ??= $this->fetchTransferDetails($transferId);
                if ($transferDetails !== null) {
                    $recipientType = $this->resolveTransferCounterpartyMeta($transferDetails, $customerId, true)['recipient_type'];
                }
            }

            $activities[] = $this->applyGiftCardWalletTransferPresentation(
                $this->applyBelievePointsWalletTransferPresentation([
                    'id' => 'bridge_wallet_'.$id,
                    'dedupe_key' => $dedupeKey,
                    'deposit_id' => ($depositId !== null && $depositId !== '') ? $depositId : null,
                    'virtual_account_event_id' => ($vaEventId !== null && $vaEventId !== '') ? $vaEventId : null,
                    'bridge_transfer_id' => $transferId,
                    'type' => $activityType,
                    'amount' => $amount,
                    'date' => $date,
                    'status' => $historyStatus,
                    'bridge_state' => $bridgeTransferState ?? $historyStatus,
                    'bridge_transfer_state' => $bridgeTransferState,
                    'bridge_state_label' => $bridgeStateLabel,
                    'bridge_event_type' => $type,
                    'donor_name' => $donorName,
                    'donor_email' => null,
                    'display_label' => $displayLabel,
                    'payment_method' => is_array($paymentMethod)
                        ? ($paymentMethod['method'] ?? null)
                        : ($activityType === 'withdrawal' ? strtolower((string) ($paymentMethodLabel ?? '')) : null),
                    'payment_method_label' => $paymentMethodLabel,
                    'frequency' => 'one-time',
                    'message' => $displayLabel,
                    'transaction_id' => $id,
                    'is_outgoing' => $activityOutgoing,
                    'recipient_type' => $recipientType,
                    'source' => 'bridge_wallet_history',
                    'sort_date' => $date,
                ], $transferId),
                $transferId,
            );
        }

        return $activities;
    }

    /**
     * One UI row per VA deposit_id — latest lifecycle event only (funds_scheduled → payment_processed).
     *
     * @param  array{
     *     virtual_account_id: string|null,
     *     by_event_id: array<string, array<string, mixed>>,
     *     best_by_deposit_id: array<string, array<string, mixed>>
     * }  $vaContext
     * @return array<int, array<string, mixed>>
     */
    private function mapVirtualAccountActivities(array $vaContext): array
    {
        $activities = [];

        foreach ($vaContext['best_by_deposit_id'] as $depositId => $event) {
            if (! is_array($event) || $depositId === '') {
                continue;
            }

            $activityType = strtolower((string) ($event['type'] ?? $event['activity_type'] ?? ''));
            if (! in_array($activityType, [
                'funds_scheduled',
                'funds_received',
                'payment_submitted',
                'in_review',
                'payment_processed',
            ], true)) {
                continue;
            }

            $amount = round((float) ($event['amount'] ?? $event['receipt']['final_amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $id = (string) ($event['id'] ?? $event['activity_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $status = $activityType === 'payment_processed' ? 'completed' : 'pending';
            $date = $this->parseBridgeTimestamp($event['created_at'] ?? $event['updated_at'] ?? null);
            $vaSenderName = $this->formatVirtualAccountDepositLabel($event);
            $paymentMethod = $this->resolveDepositPaymentMethod($event);
            $depositSourceLabel = $this->resolveDepositSourceLabel($event, []);
            $donorName = $vaSenderName ?? $depositSourceLabel;
            $stateLabel = $this->formatBridgeStateLabel($activityType);
            $displayLabel = $this->buildActivityDisplayLabel(
                'deposit',
                $donorName,
                $paymentMethod['label'] ?? null,
            );

            $activities[] = [
                'id' => 'bridge_va_'.$depositId,
                'dedupe_key' => 'deposit:'.$depositId,
                'deposit_id' => $depositId,
                'virtual_account_event_id' => $id,
                'bridge_transfer_id' => null,
                'type' => 'deposit',
                'amount' => $amount,
                'date' => $date,
                'status' => $status,
                'bridge_state' => $status,
                'bridge_va_state' => $activityType,
                'donor_name' => $donorName,
                'donor_email' => null,
                'display_label' => $displayLabel,
                'payment_method' => $paymentMethod['method'] ?? null,
                'payment_method_label' => $paymentMethod['label'] ?? null,
                'frequency' => 'one-time',
                'message' => $status === 'pending'
                    ? $stateLabel.' — '.$donorName
                    : $displayLabel,
                'transaction_id' => $id,
                'is_outgoing' => false,
                'recipient_type' => null,
                'source' => 'bridge_virtual_account',
                'sort_date' => $date,
            ];
        }

        return $activities;
    }

    /**
     * @param  array<int, string>  $onRampTransferIds
     * @return array<int, array<string, mixed>>
     */
    private function mapTransferActivities(
        string $customerId,
        array $viewerWalletIds,
        array $onRampTransferIds = [],
        ?string $viewerWalletAddress = null,
        int $maxTransfers = 100,
    ): array {
        $transfers = $this->fetchTransfersInvolvingWallet(
            $customerId,
            $viewerWalletIds,
            $viewerWalletAddress,
            $maxTransfers,
        );

        $counterpartyWalletIds = [];
        $transferIds = [];
        foreach ($transfers as $transfer) {
            if (! is_array($transfer)) {
                continue;
            }
            $transferIds[] = (string) ($transfer['id'] ?? '');
            $sourceWalletId = (string) ($transfer['source']['bridge_wallet_id'] ?? '');
            $destWalletId = (string) ($transfer['destination']['bridge_wallet_id'] ?? '');
            if ($sourceWalletId !== '') {
                $counterpartyWalletIds[] = $sourceWalletId;
            }
            if ($destWalletId !== '') {
                $counterpartyWalletIds[] = $destWalletId;
            }
        }
        $this->warmBelievePointWalletTransfers($transferIds);
        $this->warmGiftCardsByBridgeTransferIds($transferIds);
        $this->warmWalletOwnerNames($counterpartyWalletIds);

        $viewerWalletIdSet = array_fill_keys($viewerWalletIds, true);
        $activities = [];

        foreach ($transfers as $transfer) {
            if (! is_array($transfer)) {
                continue;
            }

            $transferId = (string) ($transfer['id'] ?? '');
            if ($transferId === '') {
                continue;
            }

            if (in_array($transferId, $onRampTransferIds, true)) {
                continue;
            }

            $amount = round((float) ($transfer['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $source = is_array($transfer['source'] ?? null) ? $transfer['source'] : [];
            $destination = is_array($transfer['destination'] ?? null) ? $transfer['destination'] : [];
            $sourceWalletId = (string) ($source['bridge_wallet_id'] ?? '');
            $destWalletId = (string) ($destination['bridge_wallet_id'] ?? '');
            $destToAddress = strtolower((string) ($destination['to_address'] ?? ''));
            $viewerAddress = strtolower((string) ($viewerWalletAddress ?? ''));
            $onBehalf = (string) ($transfer['on_behalf_of'] ?? $transfer['customer_id'] ?? '');
            $destCustomer = (string) ($destination['customer_id'] ?? '');
            $sourceCustomer = (string) ($source['customer_id'] ?? '');

            $isOutgoing = ($sourceWalletId !== '' && isset($viewerWalletIdSet[$sourceWalletId]))
                || $onBehalf === $customerId
                || $sourceCustomer === $customerId;
            $isIncoming = ($destWalletId !== '' && isset($viewerWalletIdSet[$destWalletId]))
                || ($destToAddress !== '' && $viewerAddress !== '' && $destToAddress === $viewerAddress)
                || $destCustomer === $customerId;

            if ($isOutgoing && $isIncoming) {
                if ($onBehalf === $customerId) {
                    $isIncoming = false;
                } else {
                    $isOutgoing = false;
                }
            }

            if (! $isOutgoing && ! $isIncoming) {
                continue;
            }

            // VA/bank on-ramps appear as deposits in wallet history — skip duplicate rows here.
            if ($isIncoming && ! $isOutgoing && $this->isOnRampTransfer($transfer)) {
                continue;
            }

            $state = strtolower((string) ($transfer['state'] ?? $transfer['status'] ?? 'pending'));
            $status = $this->mapBridgeStateToUiStatus($state);
            $stateLabel = $this->formatBridgeStateLabel($state);

            $date = $this->parseBridgeTimestamp($transfer['updated_at'] ?? $transfer['created_at'] ?? null);
            $detailedTransfer = $this->fetchTransferDetails($transferId) ?? $transfer;
            $counterpartyMeta = $this->resolveTransferCounterpartyMeta($detailedTransfer, $customerId, $isOutgoing);
            if (($isOutgoing && $counterpartyMeta['name'] === 'Recipient') || (! $isOutgoing && $counterpartyMeta['name'] === 'Sender')) {
                $counterpartyMeta = $this->resolveTransferCounterpartyMeta($detailedTransfer, $customerId, $isOutgoing);
            }

            $counterparty = $counterpartyMeta['name'];
            $statusSuffix = $status === 'pending' ? ' ('.$stateLabel.')' : '';
            $transferType = $isOutgoing ? 'transfer_sent' : 'transfer_received';
            $paymentMethodLabel = null;

            if ($isOutgoing && $this->isExternalAccountOfframp($detailedTransfer)) {
                $transferType = 'withdrawal';
                $externalAccountId = (string) ($detailedTransfer['destination']['external_account_id'] ?? '');
                $bankLabel = $this->resolveExternalAccountDisplayLabel($customerId, $externalAccountId) ?? $counterparty;
                $counterparty = $bankLabel;
                $rail = strtoupper((string) ($detailedTransfer['destination']['payment_rail'] ?? 'ach'));
                $paymentMethodLabel = $this->formatWithdrawalRailLabel($rail);
                $displayLabel = $this->buildWithdrawalDisplayLabel($rail, $bankLabel);
            } else {
                $displayLabel = $this->buildActivityDisplayLabel($transferType, $counterparty);
            }

            $activity = [
                'id' => 'bridge_transfer_'.$transferId,
                'dedupe_key' => 'transfer:'.$transferId,
                'deposit_id' => null,
                'bridge_transfer_id' => $transferId,
                'type' => $transferType,
                'amount' => $amount,
                'date' => $date,
                'status' => $status,
                'bridge_state' => $state,
                'bridge_transfer_state' => $state,
                'bridge_state_label' => $stateLabel,
                'donor_name' => $counterparty,
                'donor_email' => null,
                'display_label' => $displayLabel,
                'payment_method_label' => $paymentMethodLabel,
                'frequency' => 'one-time',
                'message' => $displayLabel.$statusSuffix,
                'transaction_id' => $transferId,
                'is_outgoing' => $isOutgoing,
                'recipient_type' => $isOutgoing ? $counterpartyMeta['recipient_type'] : null,
                'source' => 'bridge_transfer',
                'sort_date' => $date,
            ];

            $activities[] = $this->applyGiftCardWalletTransferPresentation(
                $this->applyBelievePointsWalletTransferPresentation($activity, $transferId),
                $transferId,
            );
        }

        return $activities;
    }

    /**
     * @param  array<string, mixed>  $paymentRoute
     */
    private function isVirtualAccountOnRamp(array $paymentRoute): bool
    {
        $routeType = strtolower((string) ($paymentRoute['type'] ?? ''));

        return $routeType === 'virtual_account'
            || ! empty($paymentRoute['virtual_account_event_id'])
            || ! empty($paymentRoute['virtual_account_id']);
    }

    private function virtualAccountActivityPriority(string $activityType): int
    {
        return match ($activityType) {
            'payment_processed' => 100,
            'in_review' => 40,
            'payment_submitted' => 30,
            'funds_received' => 20,
            'funds_scheduled' => 10,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $transfer
     */
    private function isOnRampTransfer(array $transfer): bool
    {
        $source = is_array($transfer['source'] ?? null) ? $transfer['source'] : [];
        $paymentRail = strtolower((string) ($source['payment_rail'] ?? ''));
        $sourceType = strtolower((string) ($source['type'] ?? ''));

        return in_array($paymentRail, ['ach', 'wire', 'ach_push', 'ach_pull', 'sepa'], true)
            || in_array($sourceType, ['virtual_account', 'prefunded_account', 'external_bank_account'], true)
            || ! empty($source['virtual_account_id']);
    }

    private function resolveVirtualAccountId(BridgeIntegration $integration): ?string
    {
        $ids = $this->resolveAllVirtualAccountIds($integration);

        return $ids[0] ?? null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BridgeIntegration>|null  $relatedIntegrations
     * @return array<int, string>
     */
    private function resolveAllVirtualAccountIds(
        BridgeIntegration $integration,
        $relatedIntegrations = null,
        ?string $customerIdOverride = null,
    ): array {
        $integrations = collect([$integration]);
        if ($relatedIntegrations !== null) {
            $integrations = $integrations->merge($relatedIntegrations)->unique('id');
        }

        $ids = [];
        $customerId = trim((string) ($customerIdOverride ?? $integration->bridge_customer_id ?? ''));

        foreach ($integrations as $row) {
            $row->loadMissing('primaryWallet', 'wallets');

            if ($row->primaryWallet?->virtual_account_id) {
                $ids[] = (string) $row->primaryWallet->virtual_account_id;
            }

            foreach ($row->wallets as $wallet) {
                if (! empty($wallet->virtual_account_id)) {
                    $ids[] = (string) $wallet->virtual_account_id;
                }
            }

            if ($customerId === '' && ! empty($row->bridge_customer_id)) {
                $customerId = (string) $row->bridge_customer_id;
            }
        }

        if ($customerId !== '') {
            if ($this->bridgeService->customerExistsOnBridge($customerId)) {
                $accounts = $this->bridgeService->normalizeBridgeListData(
                    $this->bridgeService->getVirtualAccounts($customerId)
                );

                foreach ($accounts as $account) {
                    if (! empty($account['id'])) {
                        $ids[] = (string) $account['id'];
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Bridge wallet IDs for history API (excludes virtual account IDs).
     *
     * @return array<int, string>
     */
    private function resolveBridgeWalletIdsForHistory(
        BridgeIntegration $integration,
        ?string $primaryWalletId,
        ?string $customerIdOverride = null,
    ): array {
        $integration->loadMissing('primaryWallet', 'wallets');
        $ids = [];

        if ($primaryWalletId !== null && $primaryWalletId !== '') {
            $ids[] = $primaryWalletId;
        }

        if (! empty($integration->bridge_wallet_id)) {
            $ids[] = (string) $integration->bridge_wallet_id;
        }

        foreach ($integration->wallets as $wallet) {
            if (! empty($wallet->bridge_wallet_id)) {
                $ids[] = (string) $wallet->bridge_wallet_id;
            }
        }

        if ($integration->primaryWallet?->bridge_wallet_id) {
            $ids[] = (string) $integration->primaryWallet->bridge_wallet_id;
        }

        $customerId = trim((string) ($customerIdOverride ?? $integration->bridge_customer_id ?? ''));
        if ($customerId !== '' && $this->bridgeService->customerExistsOnBridge($customerId)) {
            $wallets = $this->bridgeService->normalizeBridgeListData(
                $this->bridgeService->getWallets($customerId)
            );

            foreach ($wallets as $wallet) {
                if (! empty($wallet['id'])) {
                    $ids[] = (string) $wallet['id'];
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function resetNameCaches(): void
    {
        $this->walletOwnerNames = [];
        $this->walletAddressOwners = [];
        $this->customerNames = [];
        $this->platformWalletIndexBuilt = false;
        $this->virtualAccountEventContext = null;
    }

    private function ensurePlatformWalletOwnerIndex(): void
    {
        if ($this->platformWalletIndexBuilt) {
            return;
        }

        $this->platformWalletIndexBuilt = true;

        $integrations = BridgeIntegration::query()
            ->whereNotNull('bridge_customer_id')
            ->with(['integratable', 'wallets', 'primaryWallet'])
            ->get();

        foreach ($integrations as $integration) {
            $name = $this->integratableDisplayName($integration);
            if ($name === '') {
                continue;
            }

            if (! empty($integration->bridge_wallet_id)) {
                $this->walletOwnerNames[(string) $integration->bridge_wallet_id] = $name;
            }

            foreach ($integration->wallets as $wallet) {
                $this->indexPlatformWallet($wallet, $name);
            }

            if ($integration->primaryWallet) {
                $this->indexPlatformWallet($integration->primaryWallet, $name);
            }
        }
    }

    /**
     * @param  \App\Models\BridgeWallet  $wallet
     */
    private function indexPlatformWallet(BridgeWallet $wallet, string $name): void
    {
        if (! empty($wallet->bridge_wallet_id)) {
            $this->walletOwnerNames[(string) $wallet->bridge_wallet_id] = $name;
        }

        if (! empty($wallet->virtual_account_id)) {
            $this->walletOwnerNames[(string) $wallet->virtual_account_id] = $name;
        }

        if (! empty($wallet->wallet_address)) {
            $this->walletAddressOwners[strtolower((string) $wallet->wallet_address)] = $name;
        }

        $vaDetails = $wallet->virtual_account_details;
        if (is_array($vaDetails)) {
            $address = $vaDetails['destination']['address'] ?? null;
            if (is_string($address) && $address !== '') {
                $this->walletAddressOwners[strtolower($address)] = $name;
            }
        }
    }

    private function buildDepositDedupeKey(
        ?string $depositId,
        ?string $vaEventId,
        ?string $transferId,
        string $fallbackId,
    ): string {
        if ($depositId !== null && $depositId !== '') {
            return 'deposit:'.$depositId;
        }

        if ($vaEventId !== null && $vaEventId !== '') {
            return 'deposit:va:'.$vaEventId;
        }

        if ($transferId !== null && $transferId !== '') {
            return 'transfer:'.$transferId;
        }

        return 'wallet:'.$fallbackId;
    }

    private function resolveWalletOwnerByAddress(?string $address): ?string
    {
        if ($address === null || $address === '') {
            return null;
        }

        $this->ensurePlatformWalletOwnerIndex();

        $name = $this->walletAddressOwners[strtolower($address)] ?? '';

        return $name !== '' ? $name : null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BridgeIntegration>|null  $relatedIntegrations
     * @return array{
     *     virtual_account_id: string|null,
     *     by_event_id: array<string, array<string, mixed>>,
     *     best_by_deposit_id: array<string, array<string, mixed>>
     * }
     */
    private function loadVirtualAccountEventContext(
        BridgeIntegration $integration,
        string $customerId,
        $relatedIntegrations = null,
    ): array {
        if ($this->virtualAccountEventContext !== null) {
            return $this->virtualAccountEventContext;
        }

        $virtualAccountIds = $this->resolveAllVirtualAccountIds($integration, $relatedIntegrations, $customerId);
        if ($virtualAccountIds === []) {
            return $this->virtualAccountEventContext = [
                'virtual_account_id' => null,
                'by_event_id' => [],
                'best_by_deposit_id' => [],
            ];
        }

        $byEventId = [];
        $bestByDepositId = [];

        foreach ($virtualAccountIds as $virtualAccountId) {
            $events = $this->fetchPaginatedBridgeList(
                fn (array $query) => $this->bridgeService->getVirtualAccountHistory($customerId, $virtualAccountId, $query),
                300,
            );

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $eventId = (string) ($event['id'] ?? $event['activity_id'] ?? '');
                if ($eventId !== '') {
                    $byEventId[$eventId] = $event;
                }

                $depositId = isset($event['deposit_id']) ? (string) $event['deposit_id'] : '';
                if ($depositId === '') {
                    continue;
                }

                $activityType = strtolower((string) ($event['type'] ?? $event['activity_type'] ?? ''));
                $priority = $this->virtualAccountActivityPriority($activityType);

                if (! isset($bestByDepositId[$depositId])
                    || $priority > $this->virtualAccountActivityPriority(
                        strtolower((string) ($bestByDepositId[$depositId]['type'] ?? $bestByDepositId[$depositId]['activity_type'] ?? '')),
                    )) {
                    $bestByDepositId[$depositId] = $event;
                }
            }
        }

        return $this->virtualAccountEventContext = [
            'virtual_account_id' => $virtualAccountIds[0],
            'by_event_id' => $byEventId,
            'best_by_deposit_id' => $bestByDepositId,
        ];
    }

    /**
     * Attach ACH/Wire sender and bank names from VA history (Bridge source object).
     *
     * @param  array<int, array<string, mixed>>  $activities
     * @param  array{
     *     virtual_account_id: string|null,
     *     by_event_id: array<string, array<string, mixed>>,
     *     best_by_deposit_id: array<string, array<string, mixed>>
     * }  $vaContext
     * @return array<int, array<string, mixed>>
     */
    private function enrichActivitiesWithVirtualAccountDetails(array $activities, array $vaContext): array
    {
        return array_map(function (array $activity) use ($vaContext) {
            $vaEvent = null;
            $eventId = (string) ($activity['virtual_account_event_id'] ?? '');
            $depositId = (string) ($activity['deposit_id'] ?? '');

            if ($eventId !== '' && isset($vaContext['by_event_id'][$eventId])) {
                $vaEvent = $vaContext['by_event_id'][$eventId];
            } elseif ($depositId !== '' && isset($vaContext['best_by_deposit_id'][$depositId])) {
                $vaEvent = $vaContext['best_by_deposit_id'][$depositId];
            }

            if ($vaEvent === null) {
                return $activity;
            }

            $label = $this->formatVirtualAccountDepositLabel($vaEvent);
            if ($label !== null) {
                $activity['donor_name'] = $label;
            }

            $paymentMethod = $this->resolveDepositPaymentMethod($vaEvent);
            if ($paymentMethod !== null) {
                $activity['payment_method'] = $paymentMethod['method'];
                $activity['payment_method_label'] = $paymentMethod['label'];
            }

            if (($activity['type'] ?? '') === 'deposit') {
                $status = (string) ($activity['status'] ?? 'completed');
                $donorName = (string) ($activity['donor_name'] ?? $this->resolveDepositSourceLabel($vaEvent, []));
                $activity['message'] = $this->buildVirtualAccountDepositMessage(
                    $vaEvent,
                    $donorName,
                    $status,
                );
                $activity['display_label'] = $this->buildActivityDisplayLabel(
                    'deposit',
                    $donorName,
                    $activity['payment_method_label'] ?? null,
                );
            }

            return $activity;
        }, $activities);
    }

    /**
     * @param  array<string, mixed>  $paymentRoute
     * @param  array{
     *     virtual_account_id: string|null,
     *     by_event_id: array<string, array<string, mixed>>,
     *     best_by_deposit_id: array<string, array<string, mixed>>
     * }  $vaContext
     * @return array<string, mixed>|null
     */
    private function resolveVirtualAccountEventForPaymentRoute(array $paymentRoute, array $vaContext): ?array
    {
        $eventId = (string) ($paymentRoute['virtual_account_event_id'] ?? '');
        if ($eventId !== '' && isset($vaContext['by_event_id'][$eventId])) {
            return $vaContext['by_event_id'][$eventId];
        }

        $depositId = (string) ($paymentRoute['deposit_id'] ?? '');
        if ($depositId !== '' && isset($vaContext['best_by_deposit_id'][$depositId])) {
            return $vaContext['best_by_deposit_id'][$depositId];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function formatVirtualAccountDepositLabel(array $event): ?string
    {
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $details = $this->extractVirtualAccountSourceDetails($source);

        $primary = $details['originator_name'] ?? $details['sender_name'];
        $bankName = $details['bank_name'];

        if ($primary !== null && $bankName !== null && strcasecmp($primary, $bankName) !== 0) {
            return "{$primary} · {$bankName}";
        }

        return $primary ?? $bankName;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{method: string, label: string}|null
     */
    private function resolveDepositPaymentMethod(array $event): ?array
    {
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $rail = strtolower(trim((string) ($source['payment_rail'] ?? $event['payment_rail'] ?? '')));

        if ($rail !== '') {
            if ($this->isCryptoPaymentRail($rail)) {
                return [
                    'method' => $this->normalizeDepositPaymentMethod($rail),
                    'label' => $this->formatPaymentRailLabel($rail),
                ];
            }

            return [
                'method' => $this->normalizeDepositPaymentMethod($rail),
                'label' => $this->formatDepositPaymentMethodLabel($rail),
            ];
        }

        $details = $this->extractVirtualAccountSourceDetails($source);
        if ($details['originator_name'] !== null || $details['bank_name'] !== null) {
            return ['method' => 'wire', 'label' => 'Wire'];
        }

        if ($details['sender_name'] !== null) {
            return ['method' => 'ach', 'label' => 'ACH'];
        }

        return null;
    }

    private function normalizeDepositPaymentMethod(string $rail): string
    {
        return match ($rail) {
            'ach_push', 'ach_pull', 'ach_same_day' => 'ach',
            'swift' => 'wire',
            default => $rail,
        };
    }

    private function formatDepositPaymentMethodLabel(string $rail): string
    {
        return match (strtolower($rail)) {
            'ach', 'ach_push', 'ach_pull', 'ach_same_day' => 'ACH',
            'wire', 'swift' => 'Wire',
            'fednow' => 'FedNow',
            'sepa' => 'SEPA',
            'pix' => 'PIX',
            'spei' => 'SPEI',
            default => strtoupper(str_replace('_', ' ', $rail)),
        };
    }

    /**
     * Human-readable deposit source from Bridge wallet history / VA events.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $paymentRoute
     */
    private function resolveDepositSourceLabel(array $event, array $paymentRoute = []): string
    {
        $type = strtolower((string) ($event['type'] ?? ''));
        $routeType = strtolower((string) ($paymentRoute['type'] ?? ''));
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $rail = strtolower(trim((string) ($source['payment_rail'] ?? $event['payment_rail'] ?? '')));
        $currency = strtoupper(trim((string) ($event['currency'] ?? $source['currency'] ?? '')));

        if ($type === 'direct_deposit' || $this->isCryptoPaymentRail($rail)) {
            $railLabel = $this->formatPaymentRailLabel($rail);
            $currencyLabel = $currency !== '' ? $currency : 'Crypto';

            return $railLabel !== ''
                ? "{$currencyLabel} on {$railLabel}"
                : 'Crypto deposit';
        }

        if (in_array($routeType, ['liquidation_address', 'drain'], true)) {
            return 'Crypto liquidation deposit';
        }

        if ($routeType === 'static_memo') {
            return 'Bank transfer (memo)';
        }

        $method = $this->resolveDepositPaymentMethod($event);
        if ($method !== null) {
            return $method['label'].' deposit';
        }

        if ($rail !== '') {
            return $this->formatDepositPaymentMethodLabel($rail).' deposit';
        }

        return match ($type) {
            'card_refund' => 'Card refund',
            'deposit' => 'Deposit',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    private function isCryptoPaymentRail(string $rail): bool
    {
        return in_array($rail, [
            'ethereum', 'eth', 'solana', 'bitcoin', 'btc', 'polygon', 'matic',
            'base', 'arbitrum', 'optimism', 'avalanche', 'avax', 'tron', 'stellar',
            'bridge_wallet', 'crypto',
        ], true);
    }

    private function formatPaymentRailLabel(string $rail): string
    {
        return match (strtolower($rail)) {
            'ethereum', 'eth' => 'Ethereum',
            'solana' => 'Solana',
            'bitcoin', 'btc' => 'Bitcoin',
            'polygon', 'matic' => 'Polygon',
            'base' => 'Base',
            'arbitrum' => 'Arbitrum',
            'optimism' => 'Optimism',
            'avalanche', 'avax' => 'Avalanche',
            'tron' => 'Tron',
            'stellar' => 'Stellar',
            'bridge_wallet' => 'Believe Cash',
            default => $rail !== '' ? ucfirst(str_replace('_', ' ', $rail)) : '',
        };
    }

    private function buildActivityDisplayLabel(
        string $activityType,
        string $subject,
        ?string $paymentMethodLabel = null,
    ): string {
        return match ($activityType) {
            'believe_points_wallet' => 'Believe Points to wallet',
            'transfer_sent' => 'Sent to '.$subject,
            'transfer_received' => 'Received from '.$subject,
            'withdrawal' => 'Withdrawal to '.$subject,
            'card_spend' => 'Card · '.$subject,
            'deposit' => $this->buildDepositDisplayLabel($subject, $paymentMethodLabel),
            default => $subject,
        };
    }

    /**
     * @param  array<int, string>  $bridgeTransferIds
     */
    private function warmBelievePointWalletTransfers(array $bridgeTransferIds): void
    {
        $missing = [];
        foreach ($bridgeTransferIds as $bridgeTransferId) {
            $bridgeTransferId = trim($bridgeTransferId);
            if ($bridgeTransferId === '' || array_key_exists($bridgeTransferId, $this->believePointWalletTransferByBridgeId)) {
                continue;
            }
            $missing[] = $bridgeTransferId;
        }

        if ($missing === []) {
            return;
        }

        BelievePointWalletTransfer::query()
            ->whereIn('bridge_transfer_id', $missing)
            ->get()
            ->each(function (BelievePointWalletTransfer $transfer): void {
                $bridgeTransferId = trim((string) ($transfer->bridge_transfer_id ?? ''));
                if ($bridgeTransferId !== '') {
                    $this->believePointWalletTransferByBridgeId[$bridgeTransferId] = $transfer;
                }
            });

        foreach ($missing as $bridgeTransferId) {
            if (! array_key_exists($bridgeTransferId, $this->believePointWalletTransferByBridgeId)) {
                $this->believePointWalletTransferByBridgeId[$bridgeTransferId] = null;
            }
        }
    }

    private function findBelievePointWalletTransfer(?string $bridgeTransferId): ?BelievePointWalletTransfer
    {
        $bridgeTransferId = trim((string) ($bridgeTransferId ?? ''));
        if ($bridgeTransferId === '') {
            return null;
        }

        if (! array_key_exists($bridgeTransferId, $this->believePointWalletTransferByBridgeId)) {
            $this->warmBelievePointWalletTransfers([$bridgeTransferId]);
        }

        return $this->believePointWalletTransferByBridgeId[$bridgeTransferId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private function applyBelievePointsWalletTransferPresentation(array $activity, ?string $bridgeTransferId): array
    {
        $transfer = $this->findBelievePointWalletTransfer($bridgeTransferId);
        if ($transfer === null) {
            return $activity;
        }

        $label = 'Believe Points to wallet';
        $activity['type'] = 'believe_points_wallet';
        $activity['display_label'] = $label;
        $activity['donor_name'] = 'Believe Points';
        $activity['payment_method_label'] = 'Believe Points';
        $activity['is_outgoing'] = false;
        $activity['recipient_type'] = null;
        $activity['message'] = $label;

        if (($activity['status'] ?? '') === 'pending') {
            $stateLabel = trim((string) ($activity['bridge_state_label'] ?? ''));
            $activity['message'] = $stateLabel !== ''
                ? $label.' ('.$stateLabel.')'
                : $label.' (processing)';
        }

        return $activity;
    }

    /**
     * @param  array<int, string>  $bridgeTransferIds
     */
    private function warmGiftCardsByBridgeTransferIds(array $bridgeTransferIds): void
    {
        $missing = [];
        foreach ($bridgeTransferIds as $bridgeTransferId) {
            $bridgeTransferId = trim($bridgeTransferId);
            if ($bridgeTransferId === '' || array_key_exists($bridgeTransferId, $this->giftCardByBridgeTransferId)) {
                continue;
            }
            $missing[] = $bridgeTransferId;
        }

        if ($missing === []) {
            return;
        }

        GiftCard::query()
            ->where('payment_method', 'bridge_wallet')
            ->whereIn('meta->bridge_transfer_id', $missing)
            ->with(['user:id,name'])
            ->get(['id', 'user_id', 'brand', 'brand_name', 'meta', 'payment_method'])
            ->each(function (GiftCard $giftCard): void {
                $bridgeTransferId = trim((string) (data_get($giftCard->meta, 'bridge_transfer_id') ?? ''));
                if ($bridgeTransferId !== '') {
                    $this->giftCardByBridgeTransferId[$bridgeTransferId] = $giftCard;
                }
            });

        foreach ($missing as $bridgeTransferId) {
            if (! array_key_exists($bridgeTransferId, $this->giftCardByBridgeTransferId)) {
                $this->giftCardByBridgeTransferId[$bridgeTransferId] = null;
            }
        }
    }

    private function findGiftCardByBridgeTransfer(?string $bridgeTransferId): ?GiftCard
    {
        $bridgeTransferId = trim((string) ($bridgeTransferId ?? ''));
        if ($bridgeTransferId === '') {
            return null;
        }

        if (! array_key_exists($bridgeTransferId, $this->giftCardByBridgeTransferId)) {
            $this->warmGiftCardsByBridgeTransferIds([$bridgeTransferId]);
        }

        return $this->giftCardByBridgeTransferId[$bridgeTransferId] ?? null;
    }

    /**
     * Label Believe Cash ↔ reserve charges that funded an open-loop gift card.
     * Member (outgoing): Gift Card Purchase. Reserve (incoming): why funds arrived.
     *
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private function applyGiftCardWalletTransferPresentation(array $activity, ?string $bridgeTransferId): array
    {
        if (($activity['type'] ?? '') === 'believe_points_wallet') {
            return $activity;
        }

        $giftCard = $this->findGiftCardByBridgeTransfer($bridgeTransferId);
        if ($giftCard === null) {
            return $activity;
        }

        $brand = trim((string) ($giftCard->brand_name ?? $giftCard->brand ?? ''));
        $buyer = trim((string) ($giftCard->user?->name ?? ''));
        $isIncoming = ! (bool) ($activity['is_outgoing'] ?? false)
            || ($activity['type'] ?? '') === 'transfer_received';

        if ($isIncoming) {
            if ($brand !== '' && $buyer !== '') {
                $label = 'Gift Card · '.$brand.' from '.$buyer;
            } elseif ($buyer !== '') {
                $label = 'Gift Card purchase from '.$buyer;
            } elseif ($brand !== '') {
                $label = 'Gift Card purchase · '.$brand;
            } else {
                $label = 'Gift Card purchase';
            }
            $activity['donor_name'] = $buyer !== '' ? $buyer : ($brand !== '' ? $brand : 'Gift Card');
        } else {
            $label = $brand !== '' ? 'Gift Card · '.$brand : 'Gift Card Purchase';
            $activity['donor_name'] = $brand !== '' ? $brand : 'Gift Card';
        }

        $activity['display_label'] = $label;
        $activity['message'] = $label;

        if (($activity['status'] ?? '') === 'pending') {
            $stateLabel = trim((string) ($activity['bridge_state_label'] ?? ''));
            $activity['message'] = $stateLabel !== ''
                ? $label.' ('.$stateLabel.')'
                : $label.' (processing)';
        }

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $transfer
     * @return array{status: string, bridge_state: string, bridge_transfer_state: string, bridge_state_label: string}
     */
    private function resolveTransferActivityStatus(array $transfer): array
    {
        $state = strtolower((string) ($transfer['state'] ?? $transfer['status'] ?? 'pending'));

        return [
            'status' => $this->mapBridgeStateToUiStatus($state),
            'bridge_state' => $state,
            'bridge_transfer_state' => $state,
            'bridge_state_label' => $this->formatBridgeStateLabel($state),
        ];
    }

    /**
     * @param  array<string, mixed>  $transfer
     */
    private function isExternalAccountOfframp(array $transfer): bool
    {
        $destination = is_array($transfer['destination'] ?? null) ? $transfer['destination'] : [];
        $rail = strtolower((string) ($destination['payment_rail'] ?? ''));

        return ! empty($destination['external_account_id'])
            && in_array($rail, ['ach', 'wire', 'ach_same_day'], true);
    }

    private function resolveExternalAccountDisplayLabel(string $customerId, ?string $externalAccountId): ?string
    {
        $externalAccountId = trim((string) $externalAccountId);
        if ($externalAccountId === '') {
            return null;
        }

        $cacheKey = $customerId.':'.$externalAccountId;
        if (isset($this->externalAccountLabels[$cacheKey])) {
            return $this->externalAccountLabels[$cacheKey];
        }

        $result = $this->bridgeService->getExternalAccount($customerId, $externalAccountId);
        if (! ($result['success'] ?? false) || ! is_array($result['data'] ?? null)) {
            return null;
        }

        $account = $result['data'];
        $bankName = trim((string) ($account['bank_name'] ?? ''));
        $last4 = (string) ($account['account']['last_4'] ?? $account['last_4'] ?? '');
        $holderName = trim((string) ($account['account_name'] ?? $account['account_owner_name'] ?? ''));

        if ($bankName !== '' && $last4 !== '') {
            $label = $bankName.' ····'.$last4;
        } elseif ($bankName !== '') {
            $label = $bankName;
        } elseif ($holderName !== '' && $last4 !== '') {
            $label = $holderName.' ····'.$last4;
        } elseif ($last4 !== '') {
            $label = 'Bank account ····'.$last4;
        } else {
            $label = 'Bank account';
        }

        $this->externalAccountLabels[$cacheKey] = $label;

        return $label;
    }

    private function formatWithdrawalRailLabel(string $rail): string
    {
        return match (strtolower(trim($rail))) {
            'wire' => 'Wire',
            'ach_same_day' => 'Same-day ACH',
            default => 'ACH',
        };
    }

    private function buildWithdrawalDisplayLabel(string $rail, string $bankLabel): string
    {
        return $this->formatWithdrawalRailLabel($rail).' to '.$bankLabel;
    }

    private function buildDepositDisplayLabel(string $subject, ?string $paymentMethodLabel = null): string
    {
        $subject = trim($subject);
        $method = trim((string) ($paymentMethodLabel ?? ''));

        if ($method !== '' && ! str_contains(strtolower($subject), strtolower($method))) {
            if (str_ends_with(strtolower($subject), 'deposit')) {
                return $subject;
            }

            if ($subject === '' || in_array(strtolower($subject), ['bank deposit', 'deposit'], true)) {
                return $method.' deposit';
            }

            return $method.' · '.$subject;
        }

        if (str_contains(strtolower($subject), 'deposit')
            || str_contains(strtolower($subject), ' on ')
            || str_contains(strtolower($subject), 'crypto')) {
            return $subject;
        }

        return 'Deposit · '.$subject;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{sender_name: ?string, bank_name: ?string, originator_name: ?string}
     */
    private function extractVirtualAccountSourceDetails(array $event): array
    {
        $sources = [];
        if (is_array($event['source'] ?? null)) {
            $sources[] = $event['source'];
        }
        if (is_array($event['receipt']['source'] ?? null)) {
            $sources[] = $event['receipt']['source'];
        }

        $senderName = $this->trimName($event['sender_name'] ?? null);
        $bankName = $this->trimName($event['bank_name'] ?? null);
        $originatorName = $this->trimName($event['originator_name'] ?? null);

        foreach ($sources as $source) {
            $senderName ??= $this->trimName($source['sender_name'] ?? null);
            $bankName ??= $this->trimName($source['bank_name'] ?? null);
            $originatorName ??= $this->trimName($source['originator_name'] ?? null);
        }

        return [
            'sender_name' => $senderName,
            'bank_name' => $bankName,
            'originator_name' => $originatorName,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function buildVirtualAccountDepositMessage(array $event, string $donorName, string $status): string
    {
        if ($status === 'pending') {
            return 'Deposit processing';
        }

        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $details = $this->extractVirtualAccountSourceDetails($source);
        $paymentRail = strtolower((string) ($source['payment_rail'] ?? $event['payment_rail'] ?? ''));

        if ($details['bank_name'] !== null && $donorName !== $details['bank_name']) {
            return 'Deposit from '.$donorName;
        }

        if (in_array($paymentRail, ['wire', 'swift'], true) && $details['bank_name'] !== null) {
            return 'Wire deposit from '.$details['bank_name'];
        }

        return 'Deposit to wallet';
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $paymentRoute
     * @param  array{
     *     virtual_account_id: string|null,
     *     by_event_id: array<string, array<string, mixed>>,
     *     best_by_deposit_id: array<string, array<string, mixed>>
     * }  $vaContext
     */
    private function resolveVirtualAccountDepositMessage(
        array $event,
        array $paymentRoute,
        array $vaContext,
        string $donorName,
    ): string {
        $vaEvent = $this->resolveVirtualAccountEventForPaymentRoute($paymentRoute, $vaContext) ?? $event;
        $source = is_array($vaEvent['source'] ?? null) ? $vaEvent['source'] : [];

        if ($source !== []) {
            return $this->buildVirtualAccountDepositMessage($vaEvent, $donorName, 'completed');
        }

        return 'Deposit to wallet';
    }

    /**
     * @param  array<int, string>  $walletIds
     */
    private function warmWalletOwnerNames(array $walletIds): void
    {
        $this->ensurePlatformWalletOwnerIndex();

        $missing = array_values(array_unique(array_filter(
            $walletIds,
            fn (string $id) => $id !== '' && ! array_key_exists($id, $this->walletOwnerNames),
        )));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $walletId) {
            $this->walletOwnerNames[$walletId] = '';
        }

        $wallets = BridgeWallet::query()
            ->whereIn('bridge_wallet_id', $missing)
            ->with(['bridgeIntegration.integratable'])
            ->get();

        foreach ($wallets as $wallet) {
            $bridgeWalletId = (string) $wallet->bridge_wallet_id;
            $name = $this->integratableDisplayName($wallet->bridgeIntegration);
            if ($name !== '') {
                $this->walletOwnerNames[$bridgeWalletId] = $name;
            }
        }

        foreach ($missing as $walletId) {
            if (($this->walletOwnerNames[$walletId] ?? '') !== '') {
                continue;
            }

            $integration = $this->findIntegrationForBridgeWalletId($walletId);
            $name = $this->integratableDisplayName($integration);
            if ($name !== '') {
                $this->walletOwnerNames[$walletId] = $name;
            }
        }
    }

    private function findIntegrationForBridgeWalletId(string $walletId): ?BridgeIntegration
    {
        if ($walletId === '') {
            return null;
        }

        $fromWalletRow = BridgeWallet::query()
            ->where('bridge_wallet_id', $walletId)
            ->with(['bridgeIntegration.integratable'])
            ->first();

        if ($fromWalletRow?->bridgeIntegration !== null) {
            return $fromWalletRow->bridgeIntegration;
        }

        $direct = BridgeIntegration::query()
            ->where('bridge_wallet_id', $walletId)
            ->with('integratable')
            ->first();

        if ($direct !== null) {
            return $direct;
        }

        return BridgeIntegration::query()
            ->where(function ($query) use ($walletId) {
                $query->whereHas('wallets', fn ($q) => $q->where('bridge_wallet_id', $walletId))
                    ->orWhereHas('primaryWallet', fn ($q) => $q->where('bridge_wallet_id', $walletId));
            })
            ->with('integratable')
            ->first();
    }

    private function resolveWalletOwnerName(?string $walletId): ?string
    {
        if ($walletId === null || $walletId === '') {
            return null;
        }

        if (! array_key_exists($walletId, $this->walletOwnerNames)) {
            $this->warmWalletOwnerNames([$walletId]);
        }

        $name = $this->walletOwnerNames[$walletId] ?? '';

        return $name !== '' ? $name : null;
    }

    private function resolveCustomerDisplayName(?string $customerId): ?string
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        if (array_key_exists($customerId, $this->customerNames)) {
            $cached = $this->customerNames[$customerId];

            return $cached !== '' ? $cached : null;
        }

        $integration = BridgeIntegration::query()
            ->where('bridge_customer_id', $customerId)
            ->with('integratable')
            ->first();

        if ($integration !== null) {
            $name = $this->integratableDisplayName($integration);
            if ($name !== '') {
                $this->customerNames[$customerId] = $name;

                return $name;
            }
        }

        $result = $this->bridgeService->getCustomer($customerId);
        $name = '';
        if (($result['success'] ?? false) && is_array($result['data'] ?? null)) {
            $name = $this->formatBridgeCustomerRecordName($result['data']);
        }

        $this->customerNames[$customerId] = $name;

        return $name !== '' ? $name : null;
    }

    private function integratableDisplayName(?BridgeIntegration $integration): string
    {
        if ($integration === null) {
            return '';
        }

        $entity = $integration->integratable;
        if ($entity instanceof User || $entity instanceof Organization) {
            return trim((string) $entity->name);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function formatBridgeCustomerRecordName(array $customer): string
    {
        $type = strtolower((string) ($customer['type'] ?? ''));
        if ($type === 'business') {
            foreach (['business_legal_name', 'business_name'] as $key) {
                $name = trim((string) ($customer[$key] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        $firstName = trim((string) ($customer['first_name'] ?? ''));
        $lastName = trim((string) ($customer['last_name'] ?? ''));
        $fullName = trim("{$firstName} {$lastName}");
        if ($fullName !== '') {
            return $fullName;
        }

        return trim((string) ($customer['email'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array{
     *     virtual_account_id: string|null,
     *     by_event_id: array<string, array<string, mixed>>,
     *     best_by_deposit_id: array<string, array<string, mixed>>
     * }  $vaContext
     */
    private function resolveWalletHistoryCounterpartyName(
        array $event,
        string $viewerCustomerId,
        bool $isDeposit,
        array $vaContext,
    ): ?string {
        $paymentRoute = is_array($event['payment_route'] ?? null) ? $event['payment_route'] : [];
        $routeType = strtolower((string) ($paymentRoute['type'] ?? ''));
        $source = is_array($event['source'] ?? null) ? $event['source'] : [];
        $destination = is_array($event['destination'] ?? null) ? $event['destination'] : [];

        if ($isDeposit) {
            if ($routeType === 'transfer') {
                $transferId = (string) ($paymentRoute['transfer_id'] ?? '');
                if ($transferId !== '') {
                    $transfer = $this->fetchTransferDetails($transferId);
                    if ($transfer !== null) {
                        $senderName = $this->resolveTransferCounterpartyName($transfer, $viewerCustomerId, false);
                        if ($senderName !== 'Sender') {
                            return $senderName;
                        }

                        $onBehalfOf = (string) ($transfer['on_behalf_of'] ?? '');
                        if ($onBehalfOf !== '' && $onBehalfOf !== $viewerCustomerId) {
                            $name = $this->resolveCustomerDisplayName($onBehalfOf);
                            if ($name !== null) {
                                return $name;
                            }
                        }
                    }
                }

                $senderCustomerId = (string) ($paymentRoute['customer_id'] ?? '');
                if ($senderCustomerId !== '' && $senderCustomerId !== $viewerCustomerId) {
                    $name = $this->resolveCustomerDisplayName($senderCustomerId);
                    if ($name !== null) {
                        return $name;
                    }
                }
            }

            if ($this->isVirtualAccountOnRamp($paymentRoute)) {
                $vaEvent = $this->resolveVirtualAccountEventForPaymentRoute($paymentRoute, $vaContext);
                if ($vaEvent !== null) {
                    $label = $this->formatVirtualAccountDepositLabel($vaEvent);
                    if ($label !== null) {
                        return $label;
                    }
                }
            }

            return $this->extractPartyNameFromEndpoint($source);
        }

        $eventType = strtolower((string) ($event['type'] ?? ''));
        $isOutgoingEvent = in_array($eventType, ['withdrawal', 'withdraw', 'card_spend'], true);
        $transferIdFromRoute = (string) ($paymentRoute['transfer_id'] ?? '');
        if ($routeType === 'transfer' && $transferIdFromRoute !== '') {
            $transfer = $this->fetchTransferDetails($transferIdFromRoute);
            if ($transfer !== null) {
                $name = $this->resolveTransferCounterpartyName($transfer, $viewerCustomerId, $isOutgoingEvent);
                $fallback = $isOutgoingEvent ? 'Recipient' : 'Sender';
                if ($name !== $fallback) {
                    return $name;
                }
            }
        }

        $counterpartyWalletId = $isOutgoingEvent
            ? (string) ($destination['bridge_wallet_id'] ?? '')
            : (string) ($source['bridge_wallet_id'] ?? '');
        $name = $this->resolvePartyNameFromBridgeWalletId(
            $counterpartyWalletId !== '' ? $counterpartyWalletId : null,
            $viewerCustomerId,
        );
        if ($name !== null) {
            return $name;
        }

        $endpoint = $isOutgoingEvent ? $destination : $source;
        $addressKey = $isOutgoingEvent ? 'to_address' : 'from_address';
        $address = (string) ($endpoint[$addressKey] ?? '');
        $name = $this->resolveWalletOwnerByAddress($address !== '' ? $address : null);
        if ($name !== null && ! $this->matchesViewerName($name, $viewerCustomerId)) {
            return $name;
        }

        $endpointName = $this->extractPartyNameFromEndpoint($endpoint);
        if ($endpointName !== null && ! $this->matchesViewerName($endpointName, $viewerCustomerId)) {
            return $endpointName;
        }

        return $isOutgoingEvent ? 'Recipient' : 'Sender';
    }

    /**
     * @param  array<string, mixed>  $transfer
     * @return array{name: string, recipient_type: string|null}
     */
    private function resolveTransferCounterpartyMeta(
        array $transfer,
        string $viewerCustomerId,
        bool $isOutgoing,
    ): array {
        return [
            'name' => $this->resolveTransferCounterpartyName($transfer, $viewerCustomerId, $isOutgoing),
            'recipient_type' => $isOutgoing ? $this->resolveTransferRecipientType($transfer, $viewerCustomerId) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $transfer
     */
    private function resolveTransferRecipientType(array $transfer, string $viewerCustomerId): ?string
    {
        $destination = is_array($transfer['destination'] ?? null) ? $transfer['destination'] : [];
        $destWalletId = (string) ($destination['bridge_wallet_id'] ?? '');
        $recipientType = $this->resolveIntegratableTypeForWalletId($destWalletId !== '' ? $destWalletId : null);
        if ($recipientType !== null) {
            return $recipientType;
        }

        $destCustomerId = (string) ($destination['customer_id'] ?? '');
        if ($destCustomerId !== '' && $destCustomerId !== $viewerCustomerId) {
            return $this->resolveIntegratableTypeForCustomerId($destCustomerId);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $transfer
     */
    private function resolveTransferCounterpartyName(
        array $transfer,
        string $viewerCustomerId,
        bool $isOutgoing,
    ): string {
        $source = is_array($transfer['source'] ?? null) ? $transfer['source'] : [];
        $destination = is_array($transfer['destination'] ?? null) ? $transfer['destination'] : [];

        if ($isOutgoing) {
            $externalAccountId = (string) ($destination['external_account_id'] ?? '');
            if ($externalAccountId !== '') {
                $bankLabel = $this->resolveExternalAccountDisplayLabel($viewerCustomerId, $externalAccountId);
                if ($bankLabel !== null) {
                    return $bankLabel;
                }
            }

            $destWalletId = (string) ($destination['bridge_wallet_id'] ?? '');
            $name = $this->resolvePartyNameFromBridgeWalletId(
                $destWalletId !== '' ? $destWalletId : null,
                $viewerCustomerId,
            );
            if ($name !== null) {
                return $name;
            }

            $toAddress = (string) ($destination['to_address'] ?? '');
            $name = $this->resolveWalletOwnerByAddress($toAddress !== '' ? $toAddress : null);
            if ($name !== null && ! $this->matchesViewerName($name, $viewerCustomerId)) {
                return $name;
            }

            $destCustomerId = (string) ($destination['customer_id'] ?? '');
            if ($destCustomerId !== '' && $destCustomerId !== $viewerCustomerId) {
                $name = $this->resolveCustomerDisplayName($destCustomerId);
                if ($name !== null && ! $this->matchesViewerName($name, $viewerCustomerId)) {
                    return $name;
                }
            }

            $name = $this->extractPartyNameFromEndpoint($destination);
            if ($name !== null && ! $this->matchesViewerName($name, $viewerCustomerId)) {
                return $name;
            }

            return 'Recipient';
        }

        $sourceWalletId = (string) ($source['bridge_wallet_id'] ?? '');
        $name = $this->resolvePartyNameFromBridgeWalletId(
            $sourceWalletId !== '' ? $sourceWalletId : null,
            $viewerCustomerId,
        );
        if ($name !== null) {
            return $name;
        }

        $fromAddress = (string) ($source['from_address'] ?? '');
        $name = $this->resolveWalletOwnerByAddress($fromAddress !== '' ? $fromAddress : null);
        if ($name !== null && ! $this->matchesViewerName($name, $viewerCustomerId)) {
            return $name;
        }

        $senderName = $this->extractPartyNameFromEndpoint($source);
        if ($senderName !== null && ! $this->matchesViewerName($senderName, $viewerCustomerId)) {
            return $senderName;
        }

        $onBehalfOf = (string) ($transfer['on_behalf_of'] ?? '');
        if ($onBehalfOf !== '' && $onBehalfOf !== $viewerCustomerId) {
            $customerName = $this->resolveCustomerDisplayName($onBehalfOf);
            if ($customerName !== null) {
                return $customerName;
            }
        }

        $sourceCustomerId = (string) ($source['customer_id'] ?? '');
        if ($sourceCustomerId !== '' && $sourceCustomerId !== $viewerCustomerId) {
            $customerName = $this->resolveCustomerDisplayName($sourceCustomerId);
            if ($customerName !== null) {
                return $customerName;
            }
        }

        return 'Sender';
    }

    private function resolvePartyNameFromBridgeWalletId(?string $walletId, string $viewerCustomerId): ?string
    {
        if ($walletId === null || $walletId === '') {
            return null;
        }

        $name = $this->resolveWalletOwnerName($walletId);
        if ($name !== null && ! $this->matchesViewerName($name, $viewerCustomerId)) {
            return $name;
        }

        $integration = $this->findIntegrationForBridgeWalletId($walletId);
        $integrationName = $this->integratableDisplayName($integration);
        if ($integrationName !== '' && ! $this->matchesViewerName($integrationName, $viewerCustomerId)) {
            return $integrationName;
        }

        return null;
    }

    private function resolveIntegratableTypeForWalletId(?string $walletId): ?string
    {
        if ($walletId === null || $walletId === '') {
            return null;
        }

        return $this->resolveIntegratableTypeForIntegration(
            $this->findIntegrationForBridgeWalletId($walletId),
        );
    }

    private function resolveIntegratableTypeForCustomerId(string $customerId): ?string
    {
        if ($customerId === '') {
            return null;
        }

        $integration = BridgeIntegration::query()
            ->where('bridge_customer_id', $customerId)
            ->with('integratable')
            ->first();

        return $this->resolveIntegratableTypeForIntegration($integration);
    }

    private function resolveIntegratableTypeForIntegration(?BridgeIntegration $integration): ?string
    {
        if ($integration === null) {
            return null;
        }

        return match ($integration->integratable_type) {
            Organization::class => 'organization',
            User::class => 'user',
            default => null,
        };
    }

    private function matchesViewerName(string $name, string $viewerCustomerId): bool
    {
        $viewerName = $this->resolveCustomerDisplayName($viewerCustomerId);
        if ($viewerName === null) {
            return false;
        }

        return strcasecmp(trim($name), trim($viewerName)) === 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTransferDetails(string $transferId): ?array
    {
        $result = $this->bridgeService->getTransfer($transferId);
        if (! ($result['success'] ?? false) || ! is_array($result['data'] ?? null)) {
            return null;
        }

        return $result['data'];
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    private function extractPartyNameFromEndpoint(array $endpoint): ?string
    {
        foreach (['sender_name', 'bank_beneficiary_name', 'originator_name', 'account_holder_name'] as $key) {
            $name = $this->trimName($endpoint[$key] ?? null);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private function trimName(mixed $value): ?string
    {
        $name = trim((string) $value);

        return $name !== '' ? $name : null;
    }

    /**
     * Merge on_behalf_of transfers with platform-wide transfers involving this wallet.
     * Incoming pending transfers use on_behalf_of of the sender, so we must scan platform list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchTransfersInvolvingWallet(
        string $customerId,
        array $walletIds,
        ?string $viewerWalletAddress,
        int $maxTransfers,
    ): array {
        $onBehalf = $this->fetchPaginatedBridgeList(
            fn (array $query) => $this->bridgeService->getCustomerTransfers($customerId, $query),
            $maxTransfers,
        );

        $platform = $this->fetchPaginatedBridgeList(
            fn (array $query) => $this->bridgeService->getTransfers($query),
            min($maxTransfers * 5, 500),
        );

        $byId = [];
        foreach (array_merge($onBehalf, $platform) as $transfer) {
            if (! is_array($transfer)) {
                continue;
            }

            $transferId = (string) ($transfer['id'] ?? '');
            if ($transferId === '') {
                continue;
            }

            if (! $this->transferInvolvesWallet($transfer, $walletIds, $viewerWalletAddress, $customerId)) {
                continue;
            }

            $byId[$transferId] = $transfer;
        }

        return array_values($byId);
    }

    /**
     * @param  array<string, mixed>  $transfer
     * @param  array<int, string>  $walletIds
     */
    private function transferInvolvesWallet(
        array $transfer,
        array $walletIds,
        ?string $viewerWalletAddress,
        ?string $customerId = null,
    ): bool {
        $source = is_array($transfer['source'] ?? null) ? $transfer['source'] : [];
        $destination = is_array($transfer['destination'] ?? null) ? $transfer['destination'] : [];
        $sourceWalletId = (string) ($source['bridge_wallet_id'] ?? '');
        $destWalletId = (string) ($destination['bridge_wallet_id'] ?? '');
        $destToAddress = strtolower((string) ($destination['to_address'] ?? ''));
        $sourceFromAddress = strtolower((string) ($source['from_address'] ?? ''));
        $viewerAddress = strtolower((string) ($viewerWalletAddress ?? ''));
        $walletIdSet = array_fill_keys($walletIds, true);

        if ($sourceWalletId !== '' && isset($walletIdSet[$sourceWalletId])) {
            return true;
        }

        if ($destWalletId !== '' && isset($walletIdSet[$destWalletId])) {
            return true;
        }

        if ($viewerAddress !== '') {
            if ($destToAddress === $viewerAddress || $sourceFromAddress === $viewerAddress) {
                return true;
            }
        }

        if ($customerId !== null && $customerId !== '') {
            $onBehalf = (string) ($transfer['on_behalf_of'] ?? $transfer['customer_id'] ?? '');
            if ($onBehalf === $customerId) {
                return true;
            }

            $destCustomer = (string) ($destination['customer_id'] ?? '');
            if ($destCustomer === $customerId) {
                return true;
            }

            $sourceCustomer = (string) ($source['customer_id'] ?? '');
            if ($sourceCustomer === $customerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * All Bridge wallet / VA identifiers for this integration (sandbox may use VA id on transfers).
     *
     * @return array<int, string>
     */
    private function resolveIntegrationWalletIds(BridgeIntegration $integration, ?string $primaryWalletId): array
    {
        $integration->loadMissing('primaryWallet', 'wallets');
        $ids = [];

        if ($primaryWalletId !== null && $primaryWalletId !== '') {
            $ids[] = $primaryWalletId;
        }

        if (! empty($integration->bridge_wallet_id)) {
            $ids[] = (string) $integration->bridge_wallet_id;
        }

        foreach ($integration->wallets as $wallet) {
            if (! empty($wallet->bridge_wallet_id)) {
                $ids[] = (string) $wallet->bridge_wallet_id;
            }
            if (! empty($wallet->virtual_account_id)) {
                $ids[] = (string) $wallet->virtual_account_id;
            }
        }

        if ($integration->primaryWallet) {
            $pw = $integration->primaryWallet;
            if (! empty($pw->bridge_wallet_id)) {
                $ids[] = (string) $pw->bridge_wallet_id;
            }
            if (! empty($pw->virtual_account_id)) {
                $ids[] = (string) $pw->virtual_account_id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function mapBridgeStateToUiStatus(string $state): string
    {
        return match (strtolower(trim($state))) {
            'payment_processed', 'completed' => 'completed',
            'failed', 'returned', 'refunded', 'error', 'undeliverable' => 'failed',
            'canceled', 'cancelled' => 'cancelled',
            default => 'pending',
        };
    }

    private function formatBridgeStateLabel(string $state): string
    {
        return match (strtolower(trim($state))) {
            'awaiting_funds' => 'Awaiting funds',
            'funds_received' => 'Funds received',
            'funds_scheduled' => 'Scheduled',
            'payment_submitted' => 'Submitted',
            'in_review' => 'In review',
            'payment_processed' => 'Completed',
            'processing' => 'Processing',
            'returned' => 'Returned',
            'refunded' => 'Refunded',
            'failed' => 'Failed',
            default => ucfirst(str_replace('_', ' ', $state)),
        };
    }

    private function resolveIntegrationWalletAddress(BridgeIntegration $integration, ?string $customerId = null): ?string
    {
        $integration->loadMissing('primaryWallet', 'wallets');

        $candidates = [];
        if ($integration->primaryWallet?->wallet_address) {
            $candidates[] = (string) $integration->primaryWallet->wallet_address;
        }

        foreach ($integration->wallets as $wallet) {
            if (! empty($wallet->wallet_address)) {
                $candidates[] = (string) $wallet->wallet_address;
            }

            $vaDetails = $wallet->virtual_account_details;
            if (is_array($vaDetails)) {
                $address = $vaDetails['destination']['address'] ?? null;
                if (is_string($address) && $address !== '') {
                    $candidates[] = $address;
                }
            }
        }

        foreach ($candidates as $address) {
            if ($address !== '') {
                return $address;
            }
        }

        $customerId = trim((string) ($customerId ?? $integration->bridge_customer_id ?? ''));
        if ($customerId === '') {
            return null;
        }

        foreach ($this->resolveAllVirtualAccountIds($integration, null, $customerId) as $virtualAccountId) {
            $result = $this->bridgeService->getVirtualAccount($customerId, $virtualAccountId);
            if (! ($result['success'] ?? false) || ! is_array($result['data'] ?? null)) {
                continue;
            }

            $address = $result['data']['destination']['address'] ?? null;
            if (is_string($address) && $address !== '') {
                return $address;
            }
        }

        return null;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $fetchPage
     * @return array<int, array<string, mixed>>
     */
    private function fetchPaginatedBridgeList(callable $fetchPage, int $maxItems = 100): array
    {
        $maxItems = max(1, min($maxItems, 500));
        $pageLimit = min(100, $maxItems);
        $collected = [];
        $startingAfter = null;

        $loggedFetchFailure = false;

        while (count($collected) < $maxItems) {
            $query = ['limit' => $pageLimit];
            if ($startingAfter !== null) {
                $query['starting_after'] = $startingAfter;
            }

            $result = $fetchPage($query);
            $page = $this->bridgeService->normalizeBridgeListData(
                is_array($result) && array_key_exists('success', $result) ? $result : ['success' => true, 'data' => $result]
            );

            if ($page === []) {
                if (! $loggedFetchFailure
                    && is_array($result)
                    && array_key_exists('success', $result)
                    && ! ($result['success'] ?? false)) {
                    Log::warning('Bridge paginated list fetch returned no data', [
                        'error' => $result['error'] ?? null,
                        'status' => $result['status'] ?? null,
                    ]);
                    $loggedFetchFailure = true;
                }

                break;
            }

            foreach ($page as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $collected[] = $item;
                if (count($collected) >= $maxItems) {
                    break 2;
                }
            }

            $last = $page[count($page) - 1];
            $lastId = (string) ($last['id'] ?? '');
            if ($lastId === '' || count($page) < $pageLimit) {
                break;
            }

            $startingAfter = $lastId;
        }

        return $collected;
    }

    /**
     * @param  array<int, array<string, mixed>>  $activities
     */
    private function activitiesIncludeSource(array $activities, string $source): bool
    {
        foreach ($activities as $activity) {
            if (($activity['source'] ?? '') === $source) {
                return true;
            }
        }

        return false;
    }

    private function parseBridgeTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return now()->toIso8601String();
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable $e) {
            Log::debug('Bridge timestamp parse failed', ['value' => $value, 'error' => $e->getMessage()]);

            return now()->toIso8601String();
        }
    }
}

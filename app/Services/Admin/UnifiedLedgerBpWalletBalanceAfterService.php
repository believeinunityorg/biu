<?php

namespace App\Services\Admin;

use App\Models\Transaction;
use App\Models\User;
use App\Support\UnifiedLedgerType;
use Illuminate\Support\Collection;

/**
 * BP Wallet balance after each BP-affecting ledger row:
 * current (available + processing) − sum(wallet deltas on newer txs for that user).
 */
final class UnifiedLedgerBpWalletBalanceAfterService
{
    /**
     * @param  Collection<int, Transaction>|iterable<Transaction>  $pageTransactions
     * @return array<int, float|null> transaction id => balance after (null when not a BP wallet line)
     */
    public static function forPage(iterable $pageTransactions): array
    {
        $rows = Collection::make($pageTransactions)->filter(
            static fn ($t) => $t instanceof Transaction
        )->values();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var array<int, list<Transaction>> $byUser */
        $byUser = [];
        foreach ($rows as $t) {
            $uid = (int) ($t->user_id ?? 0);
            if ($uid < 1) {
                continue;
            }
            $byUser[$uid][] = $t;
        }

        if ($byUser === []) {
            return array_fill_keys($rows->pluck('id')->all(), null);
        }

        $users = User::query()
            ->whereIn('id', array_keys($byUser))
            ->get(['id', 'believe_points', 'processing_believe_points'])
            ->keyBy('id');

        $out = [];
        foreach ($rows as $t) {
            $out[(int) $t->id] = null;
        }

        foreach ($byUser as $userId => $userRows) {
            $user = $users->get($userId);
            if (! $user) {
                continue;
            }

            $current = round(
                (float) ($user->believe_points ?? 0) + (float) ($user->processing_believe_points ?? 0),
                2
            );

            $minId = min(array_map(static fn (Transaction $t) => (int) $t->id, $userRows));

            $newer = Transaction::query()
                ->where('user_id', $userId)
                ->where('id', '>', $minId)
                ->orderBy('id')
                ->get();

            /** @var list<array{0: int, 1: float}> $deltas */
            $deltas = [];
            foreach ($newer as $tx) {
                $delta = self::bpWalletDelta($tx);
                if ($delta !== null) {
                    $deltas[] = [(int) $tx->id, $delta];
                }
            }

            foreach ($userRows as $pageTx) {
                $pageId = (int) $pageTx->id;
                if (self::bpWalletDelta($pageTx) === null) {
                    $out[$pageId] = null;

                    continue;
                }

                $sumAfter = 0.0;
                foreach ($deltas as [$id, $amount]) {
                    if ($id > $pageId) {
                        $sumAfter += $amount;
                    }
                }

                $out[$pageId] = round($current - $sumAfter, 2);
            }
        }

        return $out;
    }

    private static function bpWalletDelta(Transaction $transaction): ?float
    {
        $delta = UnifiedLedgerWalletAmountResolver::resolve($transaction);
        if ($delta === null) {
            return null;
        }

        $classified = UnifiedLedgerClassificationService::classify($transaction);
        $ledgerType = $classified['ledger_type'] ?? null;

        // BRP activity is not BP Wallet; money-only rows already return null from the resolver.
        if ($ledgerType === UnifiedLedgerType::BRP) {
            return null;
        }

        return round((float) $delta, 2);
    }
}

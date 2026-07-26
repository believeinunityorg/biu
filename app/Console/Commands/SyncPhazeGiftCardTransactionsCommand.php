<?php

namespace App\Console\Commands;

use App\Models\GiftCard;
use App\Services\GiftCardService;
use App\Support\PhazeGiftCardPayload;
use Illuminate\Console\Command;

class SyncPhazeGiftCardTransactionsCommand extends Command
{
    protected $signature = 'gift-cards:sync-phaze-transactions
        {--limit=50 : Max gift cards to refresh}
        {--id= : Sync a single gift card id}';

    protected $description = 'Refresh Phaze transaction status and voucher credentials via GET /transaction/{orderId}';

    public function handle(GiftCardService $giftCardService): int
    {
        $query = GiftCard::query()
            ->whereNotNull('purchased_at')
            ->orderByDesc('id');

        if ($id = $this->option('id')) {
            $query->whereKey($id);
        } else {
            $query->where(function ($q) {
                $q->whereNull('meta->phaze_status')
                    ->orWhere('meta->phaze_status', 'pending')
                    ->orWhereNull('pin')
                    ->orWhereNull('voucher');
            });
        }

        $cards = $query->limit((int) $this->option('limit'))->get();
        $this->info('Refreshing '.$cards->count().' gift card(s)...');

        $ok = 0;
        $fail = 0;

        foreach ($cards as $card) {
            $orderId = $card->meta['orderId'] ?? null;
            if (! is_string($orderId) || $orderId === '') {
                $this->warn("#{$card->id}: missing orderId — skipped");
                $fail++;

                continue;
            }

            $payload = $giftCardService->lookupTransactionByOrderId($orderId);
            if (! $payload) {
                $this->warn("#{$card->id}: Phaze lookup failed for {$orderId}");
                $fail++;

                continue;
            }

            $card->update(PhazeGiftCardPayload::buildCredentialUpdates($card, $payload, [
                'phaze_status' => PhazeGiftCardPayload::normalizeProviderStatus($payload['status'] ?? null),
                'phaze_refreshed_at' => now()->toIso8601String(),
            ]));
            $card->refresh();

            $this->line(sprintf(
                '#%d %s phaze=%s pin=%s voucher=%s',
                $card->id,
                $card->brand_name ?? 'Gift Card',
                $card->meta['phaze_status'] ?? '?',
                $card->pin ?? '—',
                $card->voucher ?? '—'
            ));
            $ok++;
        }

        $this->info("Done. synced={$ok} failed={$fail}");

        return self::SUCCESS;
    }
}

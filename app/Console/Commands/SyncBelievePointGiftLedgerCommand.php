<?php

namespace App\Console\Commands;

use App\Models\BelievePointGiftInvite;
use App\Models\SupporterBelievePointGift;
use App\Services\BelievePointGiftAdminLedgerService;
use Illuminate\Console\Command;

class SyncBelievePointGiftLedgerCommand extends Command
{
    protected $signature = 'believe-points:sync-gift-ledger
                            {--dry-run : Count gifts that would be synced without writing}';

    protected $description = 'Upsert one admin Transaction ledger row per Gift BP (invites + legacy completed gifts)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $invites = 0;
        $legacy = 0;

        BelievePointGiftInvite::query()
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$invites, $dryRun) {
                foreach ($chunk as $invite) {
                    if (! $dryRun) {
                        BelievePointGiftAdminLedgerService::syncFromInvite($invite);
                    }
                    $invites++;
                }
            });

        $linkedGiftIds = BelievePointGiftInvite::query()
            ->whereNotNull('supporter_believe_point_gift_id')
            ->pluck('supporter_believe_point_gift_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        SupporterBelievePointGift::query()
            ->when($linkedGiftIds !== [], fn ($q) => $q->whereNotIn('id', $linkedGiftIds))
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$legacy, $dryRun) {
                foreach ($chunk as $gift) {
                    if (! $dryRun) {
                        BelievePointGiftAdminLedgerService::syncFromLegacyGift($gift);
                    }
                    $legacy++;
                }
            });

        if ($dryRun) {
            $this->info("Dry run: {$invites} invite(s) + {$legacy} legacy gift(s) would be synced.");
        } else {
            $this->info("Synced {$invites} invite(s) + {$legacy} legacy gift(s) to the transactions ledger (one BP Gift row each).");
        }

        return self::SUCCESS;
    }
}

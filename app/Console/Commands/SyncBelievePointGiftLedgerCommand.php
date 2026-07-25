<?php

namespace App\Console\Commands;

use App\Models\BelievePointGiftInvite;
use App\Services\BelievePointGiftAdminLedgerService;
use Illuminate\Console\Command;

class SyncBelievePointGiftLedgerCommand extends Command
{
    protected $signature = 'believe-points:sync-gift-ledger
                            {--dry-run : Count invites that would be synced without writing}';

    protected $description = 'Upsert admin Transaction ledger rows for Believe Point gift invites (hold/claim/refund)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        BelievePointGiftInvite::query()
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$count, $dryRun) {
                foreach ($chunk as $invite) {
                    if (! $dryRun) {
                        BelievePointGiftAdminLedgerService::syncFromInvite($invite);
                    }
                    $count++;
                }
            });

        if ($dryRun) {
            $this->info("Dry run: {$count} gift invite(s) would be synced to the transactions ledger.");
        } else {
            $this->info("Synced {$count} gift invite(s) to the transactions ledger.");
        }

        return self::SUCCESS;
    }
}

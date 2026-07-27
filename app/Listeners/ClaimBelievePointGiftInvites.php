<?php

namespace App\Listeners;

use App\Services\BelievePointGiftInviteService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

/**
 * When a supporter registers with an email that has pending Gift BP invites,
 * release Holding BP into their Available BP and increase Gift BP reporting.
 * Also awards cancellation goodwill BRP when applicable.
 */
class ClaimBelievePointGiftInvites
{
    public function __construct(
        private readonly BelievePointGiftInviteService $giftService,
    ) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;
        if (! $user || ($user->role ?? null) !== 'user') {
            return;
        }

        try {
            $claimed = $this->giftService->claimPendingForUser($user);
            if ($claimed !== []) {
                Log::info('Claimed Believe Point gift invites on registration', [
                    'user_id' => $user->id,
                    'count' => count($claimed),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ClaimBelievePointGiftInvites failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $brp = $this->giftService->awardCancellationGoodwillForUser($user);
            if ($brp > 0) {
                Log::info('Awarded gift-invite cancellation goodwill BRP', [
                    'user_id' => $user->id,
                    'brp' => $brp,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Gift invite cancellation goodwill award failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Listeners;

use App\Services\FamilyReunion\FamilyReunionService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

class ClaimFamilyMemberRecords
{
    public function __construct(
        private readonly FamilyReunionService $familyReunionService,
    ) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;
        if (! $user) {
            return;
        }

        try {
            $claimed = $this->familyReunionService->claimPendingForUser($user);
            if ($claimed !== []) {
                Log::info('Claimed family member records on registration', [
                    'user_id' => $user->id,
                    'count' => count($claimed),
                    'member_ids' => $claimed,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ClaimFamilyMemberRecords failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

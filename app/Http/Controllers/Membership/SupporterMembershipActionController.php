<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\SupporterMembership;
use App\Services\MembershipAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupporterMembershipActionController extends Controller
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function approve(Request $request, SupporterMembership $supporterMembership): JsonResponse
    {
        $membership = $this->membershipAccountService->approveMembership(
            $request->user(),
            $supporterMembership,
        );

        return response()->json([
            'success' => true,
            'message' => 'Membership approved.',
            'membership' => $this->serializeMembership($membership),
        ]);
    }

    public function decline(Request $request, SupporterMembership $supporterMembership): JsonResponse
    {
        $membership = $this->membershipAccountService->declineMembership(
            $request->user(),
            $supporterMembership,
        );

        return response()->json([
            'success' => true,
            'message' => 'Membership request declined.',
            'membership' => $this->serializeMembership($membership),
        ]);
    }

    public function revoke(Request $request, SupporterMembership $supporterMembership): JsonResponse
    {
        $membership = $this->membershipAccountService->revokeMembership(
            $request->user(),
            $supporterMembership,
        );

        return response()->json([
            'success' => true,
            'message' => 'Member removed from membership.',
            'membership' => $this->serializeMembership($membership),
        ]);
    }

    public function destroy(Request $request, SupporterMembership $supporterMembership): JsonResponse
    {
        $membership = $this->membershipAccountService->cancelBySupporter(
            $request->user(),
            $supporterMembership,
        );

        return response()->json([
            'success' => true,
            'message' => $membership->status->value === 'expired'
                ? 'You have left this membership.'
                : 'Membership request cancelled.',
            'membership' => $this->serializeMembership($membership),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMembership(SupporterMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'status' => $membership->status->value,
            'status_label' => $membership->status->label(),
            'requested_at' => $membership->requested_at?->toISOString(),
            'approved_at' => $membership->approved_at?->toISOString(),
        ];
    }
}

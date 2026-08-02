<?php

namespace App\Http\Controllers\Membership;

use App\Enums\MembershipAccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\SupporterMembershipRequestStoreRequest;
use App\Services\MembershipAccountService;
use Illuminate\Http\JsonResponse;

class SupporterMembershipRequestController extends Controller
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function store(SupporterMembershipRequestStoreRequest $request): JsonResponse
    {
        $accountType = MembershipAccountType::from($request->input('account_type'));
        $account = $accountType->modelClass()::query()->findOrFail($request->integer('account_id'));

        $result = $this->membershipAccountService->initiateSupporterMembership(
            $request->user(),
            $account,
        );

        $membership = $result['membership'];

        return response()->json([
            'success' => true,
            'message' => $result['requires_payment']
                ? 'Continue to payment to complete your membership request.'
                : ($membership->status->value === 'verified'
                    ? 'Membership activated.'
                    : 'Membership request submitted and pending review.'),
            'membership' => [
                'id' => $membership->id,
                'status' => $membership->status->value,
                'payment_status' => $membership->payment_status?->value,
                'requested_at' => $membership->requested_at?->toISOString(),
            ],
            'checkout_url' => $result['checkout_url'],
            'requires_payment' => $result['requires_payment'],
        ], 201);
    }
}

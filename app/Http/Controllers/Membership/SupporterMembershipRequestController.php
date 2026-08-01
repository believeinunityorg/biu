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

        $membership = $this->membershipAccountService->createSupporterRequest(
            $request->user(),
            $account,
        );

        return response()->json([
            'success' => true,
            'membership' => [
                'id' => $membership->id,
                'status' => $membership->status->value,
                'requested_at' => $membership->requested_at?->toISOString(),
            ],
        ], 201);
    }
}

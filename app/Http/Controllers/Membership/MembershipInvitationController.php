<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\MembershipInvitationStoreRequest;
use App\Models\MembershipInvitation;
use App\Services\MembershipAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipInvitationController extends Controller
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function store(MembershipInvitationStoreRequest $request): JsonResponse
    {
        $accountType = \App\Enums\MembershipAccountType::from($request->input('account_type'));
        $account = $accountType->modelClass()::query()->findOrFail($request->integer('account_id'));

        $invitation = $this->membershipAccountService->sendInvitation(
            $request->user(),
            $account,
            $request->string('email')->toString(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Membership invitation sent.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'status' => $invitation->status->value,
                'expires_at' => $invitation->expires_at?->toISOString(),
            ],
        ], 201);
    }

    public function destroy(Request $request, MembershipInvitation $membershipInvitation): JsonResponse
    {
        $this->membershipAccountService->cancelInvitation($request->user(), $membershipInvitation);

        return response()->json([
            'success' => true,
            'message' => 'Invitation cancelled.',
        ]);
    }

    public function accept(Request $request, MembershipInvitation $membershipInvitation): JsonResponse
    {
        $result = $this->membershipAccountService->acceptInvitation(
            $request->user(),
            $membershipInvitation,
        );

        return response()->json([
            'success' => true,
            'message' => $result['requires_payment']
                ? 'Invitation accepted. Complete payment to activate membership.'
                : 'Invitation accepted.',
            'membership' => [
                'id' => $result['membership']->id,
                'status' => $result['membership']->status->value,
            ],
            'checkout_url' => $result['checkout_url'],
            'requires_payment' => $result['requires_payment'],
        ]);
    }
}

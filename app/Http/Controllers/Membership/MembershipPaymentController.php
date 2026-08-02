<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\SupporterMembership;
use App\Services\MembershipAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MembershipPaymentController extends Controller
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function success(Request $request, SupporterMembership $membership): RedirectResponse
    {
        abort_unless($request->user() !== null, 403);
        abort_unless((int) $request->user()->id === (int) $membership->supporter_id, 403);

        $sessionId = (string) $request->query('session_id', '');
        if ($sessionId === '') {
            return redirect()->route('profile.memberships')->with('error', 'Missing payment session.');
        }

        $this->membershipAccountService->completeMembershipPayment($membership, $sessionId);

        return redirect()
            ->route('profile.memberships')
            ->with('success', 'Membership payment completed successfully.');
    }

    public function checkout(Request $request, SupporterMembership $membership): RedirectResponse
    {
        abort_unless($request->user() !== null, 403);
        abort_unless((int) $request->user()->id === (int) $membership->supporter_id, 403);

        $checkoutUrl = $this->membershipAccountService->createMembershipCheckoutSession(
            $request->user(),
            $membership,
        );

        return redirect()->away($checkoutUrl);
    }
}

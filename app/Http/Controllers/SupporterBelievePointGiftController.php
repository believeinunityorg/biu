<?php

namespace App\Http\Controllers;

use App\Models\GiftOccasion;
use App\Models\User;
use App\Services\BelievePointGiftInviteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SupporterBelievePointGiftController extends Controller
{
    public function __construct(
        private readonly BelievePointGiftInviteService $giftService,
    ) {}

    public function showGift(User $recipient)
    {
        $sender = Auth::user();
        if (! $sender instanceof User) {
            return redirect()->guest(route('login'));
        }

        try {
            $this->giftService->assertCanSend($sender);
        } catch (\Illuminate\Validation\ValidationException) {
            return redirect()->route('home')->withErrors([
                'error' => 'Gifts are available to supporter and nonprofit accounts.',
            ]);
        }

        if ($recipient->id === $sender->id) {
            return redirect()->back()->withErrors(['error' => 'You cannot send a gift to yourself.']);
        }

        $sender->refresh();

        return Inertia::render('Supporters/BirthdayGift', [
            'recipient' => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'slug' => $recipient->slug,
                'image' => $recipient->image ? '/storage/'.$recipient->image : null,
            ],
            'senderBalances' => [
                'available_believe_points' => round((float) ($sender->believe_points ?? 0), 2),
                'purchased_believe_points' => $sender->purchasedBelievePointsBalance(),
                'gifted_believe_points' => round((float) ($sender->gifted_believe_points ?? 0), 2),
                'holding_believe_points' => round((float) ($sender->holding_believe_points ?? 0), 2),
            ],
            'giftOccasions' => GiftOccasion::query()
                ->orderBy('category')
                ->orderBy('occasion')
                ->get(['id', 'occasion', 'icon', 'category']),
        ]);
    }

    public function showBirthdayGift(User $celebrant)
    {
        return $this->showGift($celebrant);
    }

    public function sendGift(Request $request, User $recipient)
    {
        $sender = Auth::user();
        if (! $sender instanceof User) {
            return redirect()->guest(route('login'));
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:10000',
            'gift_occasion_id' => 'required|integer|exists:gift_occasions,id',
            'message' => 'nullable|string|max:500',
            'livestream_kind' => 'nullable|in:user,organization',
            'livestream_id' => 'nullable|integer|min:1',
        ]);

        $occasion = GiftOccasion::query()->findOrFail((int) $validated['gift_occasion_id']);
        $invite = $this->giftService->sendToExistingUser(
            $sender,
            $recipient,
            (float) $validated['amount'],
            $occasion,
            $validated['message'] ?? null,
        );

        $amt = BelievePointGiftInviteService::formatAmount((float) $invite->amount);
        $success = "Gift of {$amt} BP sent to {$recipient->name}. Added to their Available BP (Gift BP reporting updated).";

        if ($request->header('X-Inertia')) {
            return back()->with('success', $success);
        }

        return redirect()->route('find-supporters.index')->with('success', $success);
    }

    public function sendBirthdayGift(Request $request, User $celebrant)
    {
        return $this->sendGift($request, $celebrant);
    }
}

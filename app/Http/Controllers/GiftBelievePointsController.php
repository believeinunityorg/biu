<?php

namespace App\Http\Controllers;

use App\Models\BelievePointGiftInvite;
use App\Models\GiftOccasion;
use App\Models\SupporterBelievePointGift;
use App\Models\User;
use App\Services\BelievePointGiftInviteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class GiftBelievePointsController extends Controller
{
    public function __construct(
        private readonly BelievePointGiftInviteService $giftService,
    ) {}

    public function index(Request $request)
    {
        $sender = Auth::user();
        if (! $sender instanceof User) {
            return redirect()->guest(route('login'));
        }

        try {
            $this->giftService->assertCanSend($sender);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->route('gift-cards.index')->withErrors([
                'error' => $e->errors()['amount'][0] ?? 'Gifts are not available for this account.',
            ]);
        }

        $sender->refresh();

        $statusFilter = strtolower(trim((string) $request->query('status', 'all')));
        $allowedStatuses = ['all', 'pending', 'claimed', 'cancelled', 'expired'];
        if (! in_array($statusFilter, $allowedStatuses, true)) {
            $statusFilter = 'all';
        }

        $inviteQuery = BelievePointGiftInvite::query()
            ->with(['recipient:id,name,email,image'])
            ->where('sender_id', $sender->id)
            ->orderByDesc('created_at');

        if ($statusFilter !== 'all') {
            $inviteQuery->where('status', $statusFilter);
        }

        $invites = $inviteQuery
            ->limit(50)
            ->get();

        $inviteGiftIds = $invites
            ->pluck('supporter_believe_point_gift_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $legacyGifts = collect();
        if (in_array($statusFilter, ['all', 'claimed'], true)) {
            $legacyGifts = SupporterBelievePointGift::query()
                ->with(['recipient:id,name,email,image'])
                ->where('sender_id', $sender->id)
                ->when($inviteGiftIds !== [], fn ($q) => $q->whereNotIn('id', $inviteGiftIds))
                ->orderByDesc('sent_at')
                ->limit(50)
                ->get();
        }

        $sentGifts = $invites
            ->map(fn (BelievePointGiftInvite $invite) => $this->mapSentInvite($invite))
            ->concat($legacyGifts->map(fn (SupporterBelievePointGift $gift) => $this->mapLegacyGift($gift)))
            ->sortByDesc(fn (array $row) => $row['created_at'] ?? '')
            ->values()
            ->take(50)
            ->values();

        $giftsToCollect = BelievePointGiftInvite::query()
            ->with(['sender:id,name,email,image'])
            ->where('status', BelievePointGiftInvite::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->where(function ($q) use ($sender) {
                $q->where('recipient_id', $sender->id);
                $email = Str::lower(trim((string) $sender->email));
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(recipient_email) = ?', [$email]);
                }
            })
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (BelievePointGiftInvite $invite) => [
                'id' => $invite->id,
                'amount' => round((float) $invite->amount, 2),
                'occasion' => $invite->occasion,
                'message' => $invite->message,
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'created_at' => $invite->created_at?->toIso8601String(),
                'sender' => $invite->sender ? [
                    'id' => $invite->sender->id,
                    'name' => $invite->sender->name,
                    'email' => $invite->sender->email,
                    'image' => $invite->sender->image ? '/storage/'.$invite->sender->image : null,
                ] : null,
            ]);

        $preselectedRecipient = null;
        $recipientId = (int) $request->query('recipient', 0);
        if ($recipientId > 0 && $recipientId !== (int) $sender->id) {
            $user = User::query()
                ->whereKey($recipientId)
                ->where('role', 'user')
                ->whereNotNull('email_verified_at')
                ->first(['id', 'name', 'email', 'slug', 'image']);
            if ($user) {
                $preselectedRecipient = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'slug' => $user->slug,
                    'image' => $user->image ? '/storage/'.$user->image : null,
                    'display_name' => $user->name.($user->email ? ' ('.$user->email.')' : ''),
                ];
            }
        }

        return Inertia::render('GiftCards/GiftBp', [
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
            'holdDays' => BelievePointGiftInviteService::holdDays(),
            'sentGifts' => $sentGifts,
            'giftsToCollect' => $giftsToCollect,
            'statusFilter' => $statusFilter,
            'preselectedRecipient' => $preselectedRecipient,
            'viewerRole' => $sender->role,
        ]);
    }

    public function search(Request $request)
    {
        $sender = Auth::user();
        if (! $sender instanceof User) {
            return response()->json(['results' => [], 'invite_email' => null, 'notice' => null], 401);
        }

        $q = trim((string) $request->query('q', ''));
        $resolved = $this->giftService->resolveRecipientSearch($sender, $q);

        return response()->json($resolved);
    }

    public function send(Request $request)
    {
        $sender = Auth::user();
        if (! $sender instanceof User) {
            return redirect()->guest(route('login'));
        }

        $validated = $request->validate([
            'mode' => 'required|in:user,invite',
            'recipient_id' => 'exclude_if:mode,invite|required|integer|exists:users,id',
            'email' => 'exclude_if:mode,user|required|email|max:255',
            'amount' => 'required|numeric|min:0.01|max:10000',
            'gift_occasion_id' => 'required|integer|exists:gift_occasions,id',
            'message' => 'nullable|string|max:500',
        ]);

        $occasion = GiftOccasion::query()->findOrFail((int) $validated['gift_occasion_id']);
        $amount = (float) $validated['amount'];
        $message = $validated['message'] ?? null;
        $amtLabel = BelievePointGiftInviteService::formatAmount($amount);
        $days = BelievePointGiftInviteService::holdDays();

        if ($validated['mode'] === 'user') {
            $recipient = User::query()->findOrFail((int) $validated['recipient_id']);
            $invite = $this->giftService->sendToExistingUser($sender, $recipient, $amount, $occasion, $message);

            return back()->with(
                'success',
                "Gift of {$amtLabel} BP sent to {$recipient->name}. Added to their Available BP (Gift BP reporting updated)."
            );
        }

        $invite = $this->giftService->sendInvite(
            $sender,
            (string) $validated['email'],
            $amount,
            $occasion,
            $message,
        );

        return back()->with(
            'success',
            'Invitation sent to '.$invite->recipient_email.'. '.$amtLabel.' BP is holding until they register ('.$days.' days).'
        );
    }

    public function claim(Request $request, BelievePointGiftInvite $invite)
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return redirect()->guest(route('login'));
        }

        $claimed = $this->giftService->claimAsRecipient($user, $invite);
        $amt = BelievePointGiftInviteService::formatAmount((float) $claimed->amount);

        return back()->with(
            'success',
            "You collected {$amt} BP. It was added to your Available BP (Gift BP reporting updated)."
        );
    }

    public function cancel(Request $request, BelievePointGiftInvite $invite)
    {
        $sender = Auth::user();
        if (! $sender instanceof User) {
            return redirect()->guest(route('login'));
        }

        $this->giftService->cancelInvite($sender, $invite);

        return back()->with(
            'success',
            'Gift cancelled. '.BelievePointGiftInviteService::formatAmount((float) $invite->amount).' BP was returned to your Available balance.'
        );
    }

    public function resend(Request $request, BelievePointGiftInvite $invite)
    {
        $sender = Auth::user();
        if (! $sender instanceof User) {
            return redirect()->guest(route('login'));
        }

        $this->giftService->resendInvite($sender, $invite);

        $fresh = $invite->fresh(['recipient']);
        $label = $fresh?->recipient?->name ?: $fresh?->recipient_email;

        return back()->with(
            'success',
            'Gift reminder sent to '.$label.'.'
        );
    }

    public function updateEmail(Request $request, BelievePointGiftInvite $invite)
    {
        $sender = Auth::user();
        if (! $sender instanceof User) {
            return redirect()->guest(route('login'));
        }

        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $updated = $this->giftService->changeInviteEmail($sender, $invite, (string) $validated['email']);

        return back()->with(
            'success',
            'Invitation email updated to '.$updated->recipient_email.'. Holding BP is unchanged.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSentInvite(BelievePointGiftInvite $invite): array
    {
        $recipientName = $invite->recipient?->name;
        $recipientEmail = $invite->recipient_email;
        $canChangeEmail = $invite->isPending() && (int) ($invite->recipient_id ?? 0) < 1;

        return [
            'id' => 'invite-'.$invite->id,
            'invite_id' => $invite->id,
            'legacy_gift_id' => null,
            'recipient_name' => $recipientName,
            'recipient_email' => $recipientEmail,
            'recipient_label' => $recipientName ? "{$recipientName} ({$recipientEmail})" : $recipientEmail,
            'amount' => round((float) $invite->amount, 2),
            'status' => $invite->status,
            'occasion' => $invite->occasion,
            'claimed_at' => $invite->claimed_at?->toIso8601String(),
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'created_at' => $invite->created_at?->toIso8601String(),
            'is_registered_recipient' => (int) ($invite->recipient_id ?? 0) > 0,
            'can_cancel' => $invite->isPending(),
            'can_resend' => $invite->isPending(),
            'can_change_email' => $canChangeEmail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLegacyGift(SupporterBelievePointGift $gift): array
    {
        $name = $gift->recipient?->name;
        $email = $gift->recipient?->email;

        return [
            'id' => 'legacy-'.$gift->id,
            'invite_id' => null,
            'legacy_gift_id' => $gift->id,
            'recipient_name' => $name,
            'recipient_email' => $email,
            'recipient_label' => $name ? ($email ? "{$name} ({$email})" : $name) : ($email ?: 'Recipient'),
            'amount' => round((float) $gift->amount, 2),
            'status' => 'claimed',
            'occasion' => $gift->occasion,
            'claimed_at' => ($gift->sent_at ?? $gift->created_at)?->toIso8601String(),
            'expires_at' => null,
            'created_at' => ($gift->sent_at ?? $gift->created_at)?->toIso8601String(),
            'is_registered_recipient' => true,
            'can_cancel' => false,
            'can_resend' => false,
            'can_change_email' => false,
        ];
    }
}

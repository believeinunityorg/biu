<?php

namespace App\Services;

use App\Mail\BelievePointGiftAwaitingClaimMail;
use App\Mail\BelievePointGiftClaimedMail;
use App\Mail\BelievePointGiftInviteCancelledMail;
use App\Mail\BelievePointGiftInviteCancelledSenderMail;
use App\Mail\BelievePointGiftInviteEmailChangedMail;
use App\Mail\BelievePointGiftInviteMail;
use App\Mail\BelievePointGiftInvitePendingMail;
use App\Mail\BelievePointGiftReceivedMail;
use App\Mail\BelievePointGiftRefundedMail;
use App\Models\BelievePointGiftInvite;
use App\Models\BelievePointGiftInviteGoodwill;
use App\Models\GiftOccasion;
use App\Models\SupporterBelievePointGift;
use App\Models\User;
use App\Notifications\BelievePointGiftClaimedNotification;
use App\Notifications\BelievePointGiftInviteCancelledNotification;
use App\Notifications\BelievePointGiftInviteEmailChangedNotification;
use App\Notifications\BelievePointGiftInvitePendingNotification;
use App\Notifications\BelievePointGiftInviteResentNotification;
use App\Notifications\BelievePointGiftRefundedNotification;
use App\Notifications\SupporterBelievePointGiftReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gift Believe Points:
 * - Existing supporter: Available → recipient Available + Gift BP reporting (no Holding).
 * - Unregistered email: Available → Holding → Pending until register/claim → Available + Gift reporting.
 *
 * Gift BP (`gifted_believe_points`) is reporting of gift-received Available BP.
 * Outside Gift Cards, spend uses purchased only (`Available − Gift`). Gift Cards: closed-loop uses
 * full Available; Visa/MC uses purchased only.
 */
class BelievePointGiftInviteService
{
    /**
     * @return list<string>
     */
    public static function allowedSenderRoles(): array
    {
        return ['user', 'organization', 'organization_pending', 'care_alliance'];
    }

    public static function holdDays(): int
    {
        return max(1, (int) config('believe_points.gift_invite_hold_days', 14));
    }

    public static function cancellationBrpAmount(): float
    {
        return max(0, round((float) config('believe_points.gift_invite_cancellation_brp', 10), 2));
    }

    public static function resendCooldownMinutes(): int
    {
        return max(1, (int) config('believe_points.gift_invite_resend_cooldown_minutes', 2));
    }

    public function assertCanSend(?User $sender): void
    {
        if (! $sender || ! in_array($sender->role, self::allowedSenderRoles(), true)) {
            throw ValidationException::withMessages([
                'amount' => 'Gifts are available to supporter and nonprofit accounts.',
            ]);
        }
    }

    /**
     * @return list<array{id: int, name: string, email: string, slug: string|null, image: string|null, display_name: string}>
     */
    public function searchRecipients(User $sender, string $query, int $limit = 12): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $like = '%'.$query.'%';

        return User::query()
            ->where('role', 'user')
            ->whereNotNull('email_verified_at')
            ->where('id', '!=', $sender->id)
            ->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)
                    ->orWhere('email', 'LIKE', $like)
                    ->orWhere('slug', 'LIKE', $like);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'slug', 'image'])
            ->map(fn (User $u) => $this->mapSearchResult($u))
            ->all();
    }

    /**
     * Resolve Gift BP recipient search, including exact-email invite / match behavior.
     *
     * @return array{results: list<array<string, mixed>>, invite_email: string|null, notice: string|null}
     */
    public function resolveRecipientSearch(User $sender, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return ['results' => [], 'invite_email' => null, 'notice' => null];
        }

        $results = $this->searchRecipients($sender, $query);
        $inviteEmail = null;
        $notice = null;

        if (filter_var($query, FILTER_VALIDATE_EMAIL)) {
            $email = Str::lower($query);

            if (Str::lower(trim((string) $sender->email)) === $email) {
                return [
                    'results' => [],
                    'invite_email' => null,
                    'notice' => 'You cannot send a gift to yourself.',
                ];
            }

            /** @var User|null $exact */
            $exact = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($exact) {
                if ($exact->role === 'user' && filled($exact->email_verified_at)) {
                    // Exact eligible supporter — always surface them even if LIKE search missed.
                    $results = [$this->mapSearchResult($exact)];
                } elseif ($exact->role === 'user') {
                    $results = [];
                    $notice = 'This email belongs to a supporter who has not verified their email yet.';
                } else {
                    $results = [];
                    $notice = 'This email belongs to an account that cannot receive Believe Points gifts.';
                }
            } elseif ($results === []) {
                $inviteEmail = $email;
            }
        }

        return [
            'results' => $results,
            'invite_email' => $inviteEmail,
            'notice' => $notice,
        ];
    }

    /**
     * @return array{id: int, name: string, email: string|null, slug: string|null, image: string|null, display_name: string}
     */
    protected function mapSearchResult(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'slug' => $u->slug,
            'image' => $u->image ? '/storage/'.$u->image : null,
            'display_name' => $u->name.($u->email ? ' ('.$u->email.')' : ''),
        ];
    }

    /**
     * Gift an existing verified supporter immediately:
     * sender Available −amount → recipient Available +amount and Gift BP reporting +amount.
     * Holding is not used.
     */
    public function sendToExistingUser(
        User $sender,
        User $recipient,
        float $amount,
        GiftOccasion $occasion,
        ?string $message = null,
    ): BelievePointGiftInvite {
        $this->assertCanSend($sender);

        if ($recipient->id === $sender->id) {
            throw ValidationException::withMessages([
                'recipient' => 'You cannot send a gift to yourself.',
            ]);
        }

        if ($recipient->role !== 'user') {
            throw ValidationException::withMessages([
                'recipient' => 'Believe Points gifts can only be sent to supporter accounts.',
            ]);
        }

        if (blank($recipient->email_verified_at)) {
            throw ValidationException::withMessages([
                'recipient' => 'This supporter has not verified their email yet.',
            ]);
        }

        $email = Str::lower(trim((string) $recipient->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'recipient' => 'This supporter does not have a valid email on file.',
            ]);
        }

        $alreadyPending = BelievePointGiftInvite::query()
            ->where('sender_id', $sender->id)
            ->where('status', BelievePointGiftInvite::STATUS_PENDING)
            ->where(function ($q) use ($recipient, $email) {
                $q->where('recipient_id', $recipient->id)
                    ->orWhereRaw('LOWER(recipient_email) = ?', [$email]);
            })
            ->exists();
        if ($alreadyPending) {
            throw ValidationException::withMessages([
                'recipient' => 'You already have a pending gift for this supporter. Cancel it first, or wait until they accept.',
            ]);
        }

        $amount = round($amount, 2);
        $message = filled($message) ? trim((string) $message) : null;

        $invite = DB::transaction(function () use ($sender, $recipient, $email, $amount, $message, $occasion) {
            /** @var User $lockedSender */
            $lockedSender = User::query()->whereKey($sender->id)->lockForUpdate()->firstOrFail();
            /** @var User $lockedRecipient */
            $lockedRecipient = User::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();

            $purchased = $lockedSender->purchasedBelievePointsBalance();
            if ($purchased < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'You only have '.$purchased.' purchased Believe Points available to gift. Gift BP can only be spent on gift cards.',
                ]);
            }

            $lockedSender->decrement('believe_points', $amount);
            $lockedSender->clampGiftBelievePointsReporting();
            $lockedRecipient->addGiftedBelievePoints($amount);

            $gift = SupporterBelievePointGift::create([
                'sender_id' => $lockedSender->id,
                'recipient_id' => $lockedRecipient->id,
                'gift_occasion_id' => $occasion->id,
                'amount' => $amount,
                'occasion' => $occasion->occasion,
                'message' => $message,
                'sent_at' => now(),
            ]);

            $invite = BelievePointGiftInvite::create([
                'sender_id' => $lockedSender->id,
                'recipient_email' => $email,
                'recipient_id' => $lockedRecipient->id,
                'gift_occasion_id' => $occasion->id,
                'amount' => $amount,
                'occasion' => $occasion->occasion,
                'message' => $message,
                'token' => Str::random(48),
                'status' => BelievePointGiftInvite::STATUS_CLAIMED,
                'expires_at' => now(),
                'claimed_at' => now(),
                'supporter_believe_point_gift_id' => $gift->id,
            ]);

            BelievePointsWalletLedgerService::recordGiftSent($gift);
            BelievePointsWalletLedgerService::recordGiftReceived($gift);
            BelievePointGiftAdminLedgerService::recordImmediateGift($gift);

            return $invite->fresh(['sender', 'recipient', 'gift']);
        });

        $this->notifyInviteClaimed($invite);

        return $invite;
    }

    /**
     * Recipient Accept / Collect for a pending gift addressed to them.
     */
    public function claimAsRecipient(User $recipient, BelievePointGiftInvite $invite): BelievePointGiftInvite
    {
        if ($recipient->role !== 'user') {
            throw ValidationException::withMessages([
                'invite' => 'Only supporter accounts can collect Believe Points gifts.',
            ]);
        }

        if (! $invite->isPending()) {
            throw ValidationException::withMessages([
                'invite' => 'This gift is no longer available to collect.',
            ]);
        }

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'invite' => 'This gift has expired.',
            ]);
        }

        $email = Str::lower(trim((string) $recipient->email));
        $matchesId = (int) ($invite->recipient_id ?? 0) === (int) $recipient->id;
        $matchesEmail = $email !== '' && Str::lower($invite->recipient_email) === $email;
        if (! $matchesId && ! $matchesEmail) {
            throw ValidationException::withMessages([
                'invite' => 'This gift was not sent to your account.',
            ]);
        }

        $claimed = $this->claimInvite($invite, $recipient);
        if ($claimed === null) {
            throw ValidationException::withMessages([
                'invite' => 'Could not collect this gift. It may have already been claimed or cancelled.',
            ]);
        }

        return $claimed;
    }

    /**
     * Hold BP and email an invite when the recipient is not registered.
     */
    public function sendInvite(
        User $sender,
        string $email,
        float $amount,
        GiftOccasion $occasion,
        ?string $message = null,
    ): BelievePointGiftInvite {
        $this->assertCanSend($sender);

        $email = Str::lower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Enter a valid email address.',
            ]);
        }

        if (Str::lower((string) $sender->email) === $email) {
            throw ValidationException::withMessages([
                'email' => 'You cannot send a gift to yourself.',
            ]);
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($existing) {
            if ($existing->role === 'user') {
                throw ValidationException::withMessages([
                    'email' => 'This email already belongs to a supporter. Search by name or email and gift them directly.',
                ]);
            }

            throw ValidationException::withMessages([
                'email' => 'This email is already registered on the platform, but gifts can only go to supporter accounts.',
            ]);
        }

        $alreadyPending = BelievePointGiftInvite::query()
            ->where('sender_id', $sender->id)
            ->where('status', BelievePointGiftInvite::STATUS_PENDING)
            ->whereRaw('LOWER(recipient_email) = ?', [$email])
            ->exists();
        if ($alreadyPending) {
            throw ValidationException::withMessages([
                'email' => 'You already have a pending invitation for this email. Resend, change the email, or cancel it from Pending invitations.',
            ]);
        }

        $amount = round($amount, 2);
        $message = filled($message) ? trim((string) $message) : null;
        $holdDays = self::holdDays();

        $invite = DB::transaction(function () use ($sender, $email, $amount, $message, $occasion, $holdDays) {
            /** @var User $lockedSender */
            $lockedSender = User::query()->whereKey($sender->id)->lockForUpdate()->firstOrFail();

            $purchased = $lockedSender->purchasedBelievePointsBalance();
            if ($purchased < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'You only have '.$purchased.' purchased Believe Points available to gift. Gift BP can only be spent on gift cards.',
                ]);
            }

            $lockedSender->decrement('believe_points', $amount);
            $lockedSender->increment('holding_believe_points', $amount);
            $lockedSender->clampGiftBelievePointsReporting();

            $invite = BelievePointGiftInvite::create([
                'sender_id' => $lockedSender->id,
                'recipient_email' => $email,
                'gift_occasion_id' => $occasion->id,
                'amount' => $amount,
                'occasion' => $occasion->occasion,
                'message' => $message,
                'token' => Str::random(48),
                'status' => BelievePointGiftInvite::STATUS_PENDING,
                'expires_at' => now()->addDays($holdDays),
            ]);

            BelievePointsWalletLedgerService::recordGiftHold($invite);

            return $invite;
        });

        $invite->load('sender');
        BelievePointGiftAdminLedgerService::recordHold($invite);
        $this->notifyInviteCreated($invite);

        return $invite;
    }

    /**
     * Claim all pending invites for a newly registered supporter email.
     *
     * @return list<BelievePointGiftInvite>
     */
    public function claimPendingForUser(User $user): array
    {
        if ($user->role !== 'user' || blank($user->email)) {
            return [];
        }

        $email = Str::lower(trim((string) $user->email));
        $invites = BelievePointGiftInvite::query()
            ->where('status', BelievePointGiftInvite::STATUS_PENDING)
            ->whereRaw('LOWER(recipient_email) = ?', [$email])
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->get();

        $claimed = [];
        foreach ($invites as $invite) {
            try {
                $result = $this->claimInvite($invite, $user);
                if ($result) {
                    $claimed[] = $result;
                }
            } catch (\Throwable $e) {
                Log::error('Believe Point gift invite claim failed', [
                    'invite_id' => $invite->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $claimed;
    }

    public function claimInvite(BelievePointGiftInvite $invite, User $recipient): ?BelievePointGiftInvite
    {
        if ($recipient->role !== 'user') {
            return null;
        }

        $claimed = DB::transaction(function () use ($invite, $recipient) {
            /** @var BelievePointGiftInvite|null $lockedInvite */
            $lockedInvite = BelievePointGiftInvite::query()->whereKey($invite->id)->lockForUpdate()->first();
            if (! $lockedInvite || ! $lockedInvite->isPending()) {
                return null;
            }

            if ($lockedInvite->expires_at->isPast()) {
                return null;
            }

            if (Str::lower($lockedInvite->recipient_email) !== Str::lower((string) $recipient->email)) {
                return null;
            }

            /** @var User $lockedSender */
            $lockedSender = User::query()->whereKey($lockedInvite->sender_id)->lockForUpdate()->firstOrFail();
            /** @var User $lockedRecipient */
            $lockedRecipient = User::query()->whereKey($recipient->id)->lockForUpdate()->firstOrFail();

            $amount = round((float) $lockedInvite->amount, 2);
            $holding = round((float) ($lockedSender->holding_believe_points ?? 0), 2);
            if ($holding + 0.000001 < $amount) {
                Log::error('Gift invite claim missing holding balance', [
                    'invite_id' => $lockedInvite->id,
                    'holding' => $holding,
                    'amount' => $amount,
                ]);
                throw new \RuntimeException('Insufficient holding Believe Points for invite claim.');
            }

            $lockedSender->decrement('holding_believe_points', $amount);
            $lockedRecipient->addGiftedBelievePoints($amount);

            $gift = SupporterBelievePointGift::create([
                'sender_id' => $lockedSender->id,
                'recipient_id' => $lockedRecipient->id,
                'gift_occasion_id' => $lockedInvite->gift_occasion_id,
                'amount' => $amount,
                'occasion' => $lockedInvite->occasion ?? 'Gift',
                'message' => $lockedInvite->message,
                'sent_at' => now(),
            ]);

            $lockedInvite->update([
                'status' => BelievePointGiftInvite::STATUS_CLAIMED,
                'recipient_id' => $lockedRecipient->id,
                'claimed_at' => now(),
                'supporter_believe_point_gift_id' => $gift->id,
            ]);

            // Sender Available was already reduced at hold time (gift_hold ledger).
            // Credit recipient Available + Gift BP reporting.
            BelievePointsWalletLedgerService::recordGiftReceived($gift);
            BelievePointGiftAdminLedgerService::recordClaim($lockedInvite->fresh(['sender', 'recipient', 'gift']));

            return $lockedInvite->fresh(['sender', 'recipient', 'gift']);
        });

        if ($claimed) {
            $this->notifyInviteClaimed($claimed);
        }

        return $claimed;
    }

    /**
     * Refund expired pending invites back to sender Available BP.
     *
     * @return int Number of invites expired
     */
    public function expireDueInvites(int $limit = 200): int
    {
        $ids = BelievePointGiftInvite::query()
            ->where('status', BelievePointGiftInvite::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            try {
                if ($this->expireInviteById((int) $id)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error('Gift invite expiry failed', [
                    'invite_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function expireInviteById(int $inviteId): bool
    {
        $invite = DB::transaction(function () use ($inviteId) {
            /** @var BelievePointGiftInvite|null $lockedInvite */
            $lockedInvite = BelievePointGiftInvite::query()->whereKey($inviteId)->lockForUpdate()->first();
            if (! $lockedInvite || ! $lockedInvite->isPending()) {
                return null;
            }

            if ($lockedInvite->expires_at->isFuture()) {
                return null;
            }

            /** @var User $lockedSender */
            $lockedSender = User::query()->whereKey($lockedInvite->sender_id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $lockedInvite->amount, 2);
            $holding = round((float) ($lockedSender->holding_believe_points ?? 0), 2);
            $refund = min($amount, $holding);

            if ($refund > 0) {
                $lockedSender->decrement('holding_believe_points', $refund);
                $lockedSender->increment('believe_points', $refund);
            }

            $lockedInvite->update([
                'status' => BelievePointGiftInvite::STATUS_EXPIRED,
                'refunded_at' => now(),
            ]);

            BelievePointsWalletLedgerService::recordGiftHoldRefund($lockedInvite->fresh(), $refund);

            return $lockedInvite->fresh(['sender']);
        });

        if ($invite) {
            BelievePointGiftAdminLedgerService::recordHoldRefund($invite, round((float) $invite->amount, 2), 'expired');
            $this->notifyInviteExpired($invite);

            return true;
        }

        return false;
    }

    /**
     * Cancel a pending invite: return Holding BP to Available and mark cancelled.
     */
    public function cancelInvite(User $sender, BelievePointGiftInvite $invite): BelievePointGiftInvite
    {
        $this->assertCanSend($sender);
        $this->assertOwnsPendingInvite($sender, $invite);

        $cancelled = DB::transaction(function () use ($sender, $invite) {
            /** @var BelievePointGiftInvite|null $lockedInvite */
            $lockedInvite = BelievePointGiftInvite::query()->whereKey($invite->id)->lockForUpdate()->first();
            if (! $lockedInvite || ! $lockedInvite->isPending()) {
                throw ValidationException::withMessages([
                    'invite' => 'This invitation can no longer be cancelled.',
                ]);
            }

            if ((int) $lockedInvite->sender_id !== (int) $sender->id) {
                throw ValidationException::withMessages([
                    'invite' => 'You can only cancel invitations you sent.',
                ]);
            }

            /** @var User $lockedSender */
            $lockedSender = User::query()->whereKey($lockedInvite->sender_id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $lockedInvite->amount, 2);
            $holding = round((float) ($lockedSender->holding_believe_points ?? 0), 2);
            $refund = min($amount, $holding);

            if ($refund > 0) {
                $lockedSender->decrement('holding_believe_points', $refund);
                $lockedSender->increment('believe_points', $refund);
            }

            $lockedInvite->update([
                'status' => BelievePointGiftInvite::STATUS_CANCELLED,
                'refunded_at' => now(),
                'cancelled_at' => now(),
            ]);

            BelievePointsWalletLedgerService::recordGiftHoldRefund($lockedInvite->fresh(), $refund);

            $this->createGoodwillRecord(
                $lockedInvite,
                $lockedSender,
                $lockedInvite->recipient_email,
                BelievePointGiftInviteGoodwill::REASON_CANCELLED,
            );

            return $lockedInvite->fresh(['sender', 'recipient']);
        });

        BelievePointGiftAdminLedgerService::recordHoldRefund(
            $cancelled,
            round((float) $cancelled->amount, 2),
            'cancelled_by_sender'
        );
        $this->notifyInviteCancelled($cancelled);

        return $cancelled;
    }

    /**
     * Resend the invite email for a pending invite (cooldown applies).
     */
    public function resendInvite(User $sender, BelievePointGiftInvite $invite): BelievePointGiftInvite
    {
        $this->assertCanSend($sender);
        $this->assertOwnsPendingInvite($sender, $invite);

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'invite' => 'This invitation has expired. Cancel it to return Holding BP, or wait for automatic expiry.',
            ]);
        }

        $cooldown = self::resendCooldownMinutes();
        if ($invite->last_resent_at && $invite->last_resent_at->gt(now()->subMinutes($cooldown))) {
            throw ValidationException::withMessages([
                'invite' => "Please wait {$cooldown} minute(s) before resending this invitation.",
            ]);
        }

        $invite->update(['last_resent_at' => now()]);
        $invite->loadMissing('sender', 'recipient');

        try {
            if ((int) ($invite->recipient_id ?? 0) > 0 && $invite->recipient) {
                Mail::to($invite->recipient->email)->send(new BelievePointGiftAwaitingClaimMail($invite));
            } else {
                Mail::to($invite->recipient_email)->send(new BelievePointGiftInviteMail($invite));
            }
        } catch (\Throwable $e) {
            Log::warning('Gift invite resend email failed', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'invite' => 'Could not resend the invitation email. Please try again shortly.',
            ]);
        }

        try {
            $sender->notify(new BelievePointGiftInviteResentNotification($invite));
        } catch (\Throwable $e) {
            Log::error('Gift invite resent notification failed', ['error' => $e->getMessage()]);
        }

        if ((int) ($invite->recipient_id ?? 0) > 0) {
            $amt = self::formatAmount((float) $invite->amount);
            $this->pushToUser(
                (int) $invite->recipient_id,
                'Gift reminder',
                ($invite->sender?->name ?: 'Someone')." reminded you to accept {$amt} BP.",
                [
                    'type' => 'gift_awaiting_claim',
                    'invite_id' => (string) $invite->id,
                    'url' => route('gift-bp.index', [], true),
                ]
            );
        }

        $amt = self::formatAmount((float) $invite->amount);
        $this->pushToUser(
            $sender->id,
            'Gift invitation resent',
            "Invitation for {$amt} BP was resent to {$invite->recipient_email}.",
            [
                'type' => 'gift_invite_resent',
                'invite_id' => (string) $invite->id,
                'url' => route('gift-bp.index', [], true),
            ]
        );

        return $invite->fresh(['sender', 'recipient']);
    }

    /**
     * Change the recipient email on a pending invite (Holding BP unchanged).
     */
    public function changeInviteEmail(User $sender, BelievePointGiftInvite $invite, string $newEmail): BelievePointGiftInvite
    {
        $this->assertCanSend($sender);
        $this->assertOwnsPendingInvite($sender, $invite);

        if ((int) ($invite->recipient_id ?? 0) > 0) {
            throw ValidationException::withMessages([
                'email' => 'This gift is already addressed to a registered supporter. Cancel it and send a new gift if needed.',
            ]);
        }

        $newEmail = Str::lower(trim($newEmail));
        if (! filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Enter a valid email address.',
            ]);
        }

        if (Str::lower((string) $sender->email) === $newEmail) {
            throw ValidationException::withMessages([
                'email' => 'You cannot send a gift to yourself.',
            ]);
        }

        if (Str::lower($invite->recipient_email) === $newEmail) {
            throw ValidationException::withMessages([
                'email' => 'That is already the invitation email.',
            ]);
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$newEmail])->first();
        if ($existing) {
            if ($existing->role === 'user') {
                throw ValidationException::withMessages([
                    'email' => 'This email already belongs to a supporter. Cancel this invite to reclaim Holding BP, then gift them directly from search.',
                ]);
            }

            throw ValidationException::withMessages([
                'email' => 'This email is already registered on the platform, but gifts can only go to supporter accounts.',
            ]);
        }

        $pendingElsewhere = BelievePointGiftInvite::query()
            ->where('status', BelievePointGiftInvite::STATUS_PENDING)
            ->whereRaw('LOWER(recipient_email) = ?', [$newEmail])
            ->where('id', '!=', $invite->id)
            ->exists();
        if ($pendingElsewhere) {
            throw ValidationException::withMessages([
                'email' => 'There is already a pending gift invitation for this email address.',
            ]);
        }

        $previousEmail = $invite->recipient_email;

        $updated = DB::transaction(function () use ($sender, $invite, $newEmail, $previousEmail) {
            /** @var BelievePointGiftInvite|null $lockedInvite */
            $lockedInvite = BelievePointGiftInvite::query()->whereKey($invite->id)->lockForUpdate()->first();
            if (! $lockedInvite || ! $lockedInvite->isPending()) {
                throw ValidationException::withMessages([
                    'invite' => 'This invitation can no longer be edited.',
                ]);
            }

            if ((int) $lockedInvite->sender_id !== (int) $sender->id) {
                throw ValidationException::withMessages([
                    'invite' => 'You can only edit invitations you sent.',
                ]);
            }

            /** @var User $lockedSender */
            $lockedSender = User::query()->whereKey($lockedInvite->sender_id)->lockForUpdate()->firstOrFail();

            $lockedInvite->update([
                'recipient_email' => $newEmail,
                'token' => Str::random(48),
                'last_resent_at' => null,
            ]);

            $this->createGoodwillRecord(
                $lockedInvite,
                $lockedSender,
                $previousEmail,
                BelievePointGiftInviteGoodwill::REASON_EMAIL_CHANGED,
            );

            return $lockedInvite->fresh(['sender']);
        });

        BelievePointGiftAdminLedgerService::recordEmailChanged($updated, $previousEmail);
        $this->notifyInviteEmailChanged($updated, $previousEmail);

        return $updated;
    }

    /**
     * Credit cancellation goodwill BRP when a former invitee registers.
     *
     * @return float Total BRP awarded
     */
    public function awardCancellationGoodwillForUser(User $user): float
    {
        if ($user->role !== 'user' || blank($user->email)) {
            return 0.0;
        }

        $email = Str::lower(trim((string) $user->email));
        $goodwills = BelievePointGiftInviteGoodwill::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('awarded_at')
            ->orderBy('id')
            ->get();

        if ($goodwills->isEmpty()) {
            return 0.0;
        }

        $total = 0.0;

        DB::transaction(function () use ($user, $goodwills, &$total) {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            foreach ($goodwills as $goodwill) {
                /** @var BelievePointGiftInviteGoodwill|null $locked */
                $locked = BelievePointGiftInviteGoodwill::query()->whereKey($goodwill->id)->lockForUpdate()->first();
                if (! $locked || $locked->awarded_at !== null) {
                    continue;
                }

                $amount = round((float) $locked->brp_amount, 2);
                if ($amount <= 0) {
                    $locked->update([
                        'awarded_at' => now(),
                        'awarded_user_id' => $lockedUser->id,
                    ]);

                    continue;
                }

                $lockedUser->addRewardPoints(
                    $amount,
                    'gift_invite_cancellation_goodwill',
                    $locked->invite_id,
                    'Thank-you BRP after a cancelled Believe Points gift invitation',
                    [
                        'goodwill_id' => $locked->id,
                        'invite_id' => $locked->invite_id,
                        'sender_id' => $locked->sender_id,
                        'reason' => $locked->reason,
                    ],
                );

                $locked->update([
                    'awarded_at' => now(),
                    'awarded_user_id' => $lockedUser->id,
                ]);

                $total += $amount;
            }
        });

        if ($total > 0) {
            $label = self::formatAmount($total);
            $this->pushToUser(
                $user->id,
                'Welcome gift: Reward Points',
                "{$label} BRP was added to your account as a thank-you for your understanding.",
                [
                    'type' => 'gift_invite_cancellation_brp',
                    'url' => route('profile.reward-points-ledger', [], true),
                ]
            );
        }

        return $total;
    }

    private function assertOwnsPendingInvite(User $sender, BelievePointGiftInvite $invite): void
    {
        if ((int) $invite->sender_id !== (int) $sender->id) {
            throw ValidationException::withMessages([
                'invite' => 'You can only manage invitations you sent.',
            ]);
        }

        if (! $invite->isPending()) {
            throw ValidationException::withMessages([
                'invite' => 'Only pending invitations can be managed. Once claimed, ownership has transferred.',
            ]);
        }
    }

    private function createGoodwillRecord(
        BelievePointGiftInvite $invite,
        User $sender,
        string $email,
        string $reason,
    ): void {
        $brp = self::cancellationBrpAmount();
        if ($brp <= 0) {
            return;
        }

        $email = Str::lower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // One unawarded goodwill per email is enough (avoid stacking BRP for repeated edits).
        $exists = BelievePointGiftInviteGoodwill::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('awarded_at')
            ->exists();
        if ($exists) {
            return;
        }

        BelievePointGiftInviteGoodwill::create([
            'invite_id' => $invite->id,
            'sender_id' => $sender->id,
            'email' => $email,
            'sender_name' => $sender->name,
            'brp_amount' => $brp,
            'reason' => $reason,
        ]);
    }

    private function notifyInviteCancelled(BelievePointGiftInvite $invite): void
    {
        $sender = $invite->sender;
        if (! $sender) {
            return;
        }

        $senderName = $sender->name ?: 'A sender';
        $brp = self::cancellationBrpAmount();

        try {
            $sender->notify(new BelievePointGiftInviteCancelledNotification($invite));
        } catch (\Throwable $e) {
            Log::error('Gift invite cancelled notification failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($invite->recipient_email)->send(new BelievePointGiftInviteCancelledMail(
                $invite->recipient_email,
                $senderName,
                $brp,
                $invite,
                BelievePointGiftInviteGoodwill::REASON_CANCELLED,
            ));
        } catch (\Throwable $e) {
            Log::warning('Gift invite cancelled email to recipient failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($sender->email)->send(new BelievePointGiftInviteCancelledSenderMail($invite));
        } catch (\Throwable $e) {
            Log::warning('Gift invite cancelled email to sender failed', ['error' => $e->getMessage()]);
        }

        $amt = self::formatAmount((float) $invite->amount);
        $this->pushToUser(
            $sender->id,
            'Gift invitation cancelled',
            "{$amt} BP was returned to your Available balance. Invitation to {$invite->recipient_email} was cancelled.",
            [
                'type' => 'gift_invite_cancelled',
                'invite_id' => (string) $invite->id,
                'url' => route('gift-bp.index', [], true),
            ]
        );

        if ($invite->recipient_id) {
            $this->pushToUser(
                (int) $invite->recipient_id,
                'Gift rescinded',
                "{$senderName} cancelled a {$amt} BP gift before you accepted it.",
                [
                    'type' => 'gift_invite_cancelled_recipient',
                    'invite_id' => (string) $invite->id,
                    'url' => route('gift-bp.index', [], true),
                ]
            );
        }
    }

    private function notifyInviteEmailChanged(BelievePointGiftInvite $invite, string $previousEmail): void
    {
        $sender = $invite->sender;
        if (! $sender) {
            return;
        }

        $senderName = $sender->name ?: 'A sender';
        $brp = self::cancellationBrpAmount();

        try {
            $sender->notify(new BelievePointGiftInviteEmailChangedNotification($invite, $previousEmail));
        } catch (\Throwable $e) {
            Log::error('Gift invite email-changed notification failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($previousEmail)->send(new BelievePointGiftInviteCancelledMail(
                $previousEmail,
                $senderName,
                $brp,
                $invite,
                BelievePointGiftInviteGoodwill::REASON_EMAIL_CHANGED,
            ));
        } catch (\Throwable $e) {
            Log::warning('Gift invite previous-email cancellation notice failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($invite->recipient_email)->send(new BelievePointGiftInviteMail($invite));
        } catch (\Throwable $e) {
            Log::warning('Gift invite new-email invite failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($sender->email)->send(new BelievePointGiftInviteEmailChangedMail($invite, $previousEmail));
        } catch (\Throwable $e) {
            Log::warning('Gift invite email-changed sender email failed', ['error' => $e->getMessage()]);
        }

        $amt = self::formatAmount((float) $invite->amount);
        $this->pushToUser(
            $sender->id,
            'Gift invitation email updated',
            "Your {$amt} BP invite was moved from {$previousEmail} to {$invite->recipient_email}.",
            [
                'type' => 'gift_invite_email_changed',
                'invite_id' => (string) $invite->id,
                'url' => route('gift-bp.index', [], true),
            ]
        );
    }

    private function notifyInviteCreated(BelievePointGiftInvite $invite): void
    {
        $sender = $invite->sender;
        if (! $sender) {
            return;
        }

        try {
            $sender->notify(new BelievePointGiftInvitePendingNotification($invite));
        } catch (\Throwable $e) {
            Log::error('Gift invite pending notification failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($invite->recipient_email)->send(new BelievePointGiftInviteMail($invite));
        } catch (\Throwable $e) {
            Log::warning('Gift invite email to recipient failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($sender->email)->send(new BelievePointGiftInvitePendingMail($invite));
        } catch (\Throwable $e) {
            Log::warning('Gift invite pending email to sender failed', ['error' => $e->getMessage()]);
        }

        $this->pushToUser(
            $sender->id,
            'Gift invite sent',
            self::formatAmount((float) $invite->amount).' BP is holding for '.$invite->recipient_email.' until they register.',
            [
                'type' => 'gift_invite_pending',
                'invite_id' => (string) $invite->id,
                'url' => route('gift-bp.index', [], true),
            ]
        );
    }

    private function notifyInviteClaimed(BelievePointGiftInvite $invite): void
    {
        $sender = $invite->sender;
        $recipient = $invite->recipient;
        $gift = $invite->gift;
        if (! $sender || ! $recipient || ! $gift) {
            return;
        }

        try {
            $recipient->notify(new SupporterBelievePointGiftReceivedNotification(
                $sender,
                $gift,
                (float) $gift->amount,
                $gift->message,
                (string) ($gift->occasion ?? 'Gift'),
            ));
        } catch (\Throwable $e) {
            Log::error('Claimed gift received notification failed', ['error' => $e->getMessage()]);
        }

        try {
            $sender->notify(new BelievePointGiftClaimedNotification($invite));
        } catch (\Throwable $e) {
            Log::error('Claimed gift sender notification failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($recipient->email)->send(new BelievePointGiftReceivedMail($sender, $recipient, $gift));
        } catch (\Throwable $e) {
            Log::warning('Claimed gift recipient email failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($sender->email)->send(new BelievePointGiftClaimedMail($invite));
        } catch (\Throwable $e) {
            Log::warning('Claimed gift sender email failed', ['error' => $e->getMessage()]);
        }

        $amt = self::formatAmount((float) $gift->amount);
        $this->pushToUser($recipient->id, 'You received a gift', "{$sender->name} sent you {$amt} BP.", [
            'type' => 'gift_received',
            'gift_id' => (string) $gift->id,
            'url' => route('believe-points.index', [], true),
        ]);

        $immediate = $invite->created_at
            && $invite->claimed_at
            && $invite->created_at->diffInSeconds($invite->claimed_at) < 2;
        $this->pushToUser(
            $sender->id,
            $immediate ? 'Gift delivered' : 'Gift claimed',
            $immediate
                ? "{$recipient->name} received your {$amt} BP gift."
                : "{$recipient->name} collected your {$amt} BP gift.",
            [
                'type' => 'gift_invite_claimed',
                'invite_id' => (string) $invite->id,
                'url' => route('gift-bp.index', [], true),
            ]
        );
    }

    private function notifyInviteExpired(BelievePointGiftInvite $invite): void
    {
        $sender = $invite->sender;
        if (! $sender) {
            return;
        }

        try {
            $sender->notify(new BelievePointGiftRefundedNotification($invite));
        } catch (\Throwable $e) {
            Log::error('Gift invite refund notification failed', ['error' => $e->getMessage()]);
        }

        try {
            Mail::to($sender->email)->send(new BelievePointGiftRefundedMail($invite));
        } catch (\Throwable $e) {
            Log::warning('Gift invite refund email failed', ['error' => $e->getMessage()]);
        }

        $amt = self::formatAmount((float) $invite->amount);
        $this->pushToUser(
            $sender->id,
            'Gift invite expired',
            "{$amt} BP was returned to your Available balance. {$invite->recipient_email} did not register in time.",
            [
                'type' => 'gift_invite_expired',
                'invite_id' => (string) $invite->id,
                'url' => route('gift-bp.index', [], true),
            ]
        );
    }

    /**
     * @param  array<string, string>  $data
     */
    private function pushToUser(int $userId, string $title, string $body, array $data): void
    {
        try {
            $payload = array_merge($data, [
                'click_action' => $data['url'] ?? route('believe-points.index', [], true),
            ]);
            app(FirebaseService::class)->sendToUser($userId, $title, $body, $payload);
        } catch (\Throwable $e) {
            Log::warning('Gift BP FCM push failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2), '0'), '.');
    }
}

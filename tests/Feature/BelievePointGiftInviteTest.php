<?php

use App\Models\BelievePointGiftInvite;
use App\Models\User;
use App\Services\BelievePointGiftInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    DB::table('gift_occasions')->insert([
        'id' => 1,
        'occasion' => 'Birthday',
        'icon' => '🎂',
        'category' => 'Celebration',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('holds believe points for an existing supporter until they claim', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 100,
        'gifted_believe_points' => 0,
        'holding_believe_points' => 0,
    ]);

    $recipient = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
        'believe_points' => 0,
        'gifted_believe_points' => 5,
    ]);

    $response = $this->actingAs($sender)->post('/gift-bp/send', [
        'mode' => 'user',
        'recipient_id' => $recipient->id,
        'amount' => 40,
        'gift_occasion_id' => 1,
        'message' => 'Enjoy!',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $sender->refresh();
    $recipient->refresh();

    expect((float) $sender->believe_points)->toBe(60.0);
    expect((float) $sender->holding_believe_points)->toBe(40.0);
    expect((float) $recipient->gifted_believe_points)->toBe(5.0);

    $this->assertDatabaseHas('believe_point_gift_invites', [
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'amount' => 40.00,
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('transactions', [
        'type' => 'bp_gift_hold',
        'user_id' => $sender->id,
        'currency' => 'BP',
    ]);
});

it('lets a registered recipient claim a pending gift into gifted bp', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 50,
        'holding_believe_points' => 40,
    ]);

    $recipient = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
        'gifted_believe_points' => 5,
    ]);

    $invite = BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => $recipient->email,
        'recipient_id' => $recipient->id,
        'gift_occasion_id' => 1,
        'amount' => 40,
        'occasion' => 'Birthday',
        'message' => 'Enjoy!',
        'token' => 'test-token-claim-registered-123456789012',
        'status' => BelievePointGiftInvite::STATUS_PENDING,
        'expires_at' => now()->addDays(14),
    ]);

    $response = $this->actingAs($recipient)->post(route('gift-bp.gifts.claim', $invite));
    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $sender->refresh();
    $recipient->refresh();
    $invite->refresh();

    expect((float) $sender->holding_believe_points)->toBe(0.0);
    expect((float) $recipient->gifted_believe_points)->toBe(45.0);
    expect($invite->status)->toBe(BelievePointGiftInvite::STATUS_CLAIMED);

    $this->assertDatabaseHas('transactions', [
        'type' => 'bp_gift_claim',
        'user_id' => $recipient->id,
    ]);
});

it('holds believe points and creates an invite for an unregistered email', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 80,
        'holding_believe_points' => 0,
    ]);

    $response = $this->actingAs($sender)->post('/gift-bp/send', [
        'mode' => 'invite',
        'email' => 'new.friend@example.com',
        'amount' => 25,
        'gift_occasion_id' => 1,
        'message' => 'Join us!',
    ]);

    $response->assertSessionHasNoErrors();

    $sender->refresh();
    expect((float) $sender->believe_points)->toBe(55.0);
    expect((float) $sender->holding_believe_points)->toBe(25.0);

    $this->assertDatabaseHas('believe_point_gift_invites', [
        'sender_id' => $sender->id,
        'recipient_email' => 'new.friend@example.com',
        'amount' => 25.00,
        'status' => 'pending',
    ]);
});

it('claims holding believe points when the invitee registers', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 50,
        'holding_believe_points' => 30,
    ]);

    $invite = BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => 'claim.me@example.com',
        'gift_occasion_id' => 1,
        'amount' => 30,
        'occasion' => 'Birthday',
        'message' => 'Welcome',
        'token' => 'test-token-claim-123456789012345678901234',
        'status' => BelievePointGiftInvite::STATUS_PENDING,
        'expires_at' => now()->addDays(14),
    ]);

    $recipient = User::factory()->create([
        'role' => 'user',
        'email' => 'claim.me@example.com',
        'gifted_believe_points' => 0,
    ]);

    app(BelievePointGiftInviteService::class)->claimPendingForUser($recipient);

    $sender->refresh();
    $recipient->refresh();
    $invite->refresh();

    expect((float) $sender->holding_believe_points)->toBe(0.0);
    expect((float) $recipient->gifted_believe_points)->toBe(30.0);
    expect($invite->status)->toBe(BelievePointGiftInvite::STATUS_CLAIMED);
    expect($invite->recipient_id)->toBe($recipient->id);
});

it('refunds holding believe points when an invite expires', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 10,
        'holding_believe_points' => 20,
    ]);

    BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => 'late@example.com',
        'gift_occasion_id' => 1,
        'amount' => 20,
        'occasion' => 'Birthday',
        'token' => 'test-token-expire-12345678901234567890123',
        'status' => BelievePointGiftInvite::STATUS_PENDING,
        'expires_at' => now()->subMinute(),
    ]);

    $count = app(BelievePointGiftInviteService::class)->expireDueInvites();

    expect($count)->toBe(1);

    $sender->refresh();
    expect((float) $sender->believe_points)->toBe(30.0);
    expect((float) $sender->holding_believe_points)->toBe(0.0);

    $this->assertDatabaseHas('believe_point_gift_invites', [
        'recipient_email' => 'late@example.com',
        'status' => 'expired',
    ]);
});

it('cancels a pending invite and returns holding believe points immediately', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 10,
        'holding_believe_points' => 25,
    ]);

    $invite = BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => 'wrong.address@example.com',
        'gift_occasion_id' => 1,
        'amount' => 25,
        'occasion' => 'Birthday',
        'token' => 'test-token-cancel-12345678901234567890123',
        'status' => BelievePointGiftInvite::STATUS_PENDING,
        'expires_at' => now()->addDays(14),
    ]);

    $response = $this->actingAs($sender)->post(route('gift-bp.invites.cancel', $invite));

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $sender->refresh();
    $invite->refresh();

    expect((float) $sender->believe_points)->toBe(35.0);
    expect((float) $sender->holding_believe_points)->toBe(0.0);
    expect($invite->status)->toBe(BelievePointGiftInvite::STATUS_CANCELLED);
    expect($invite->cancelled_at)->not->toBeNull();

    Mail::assertSent(\App\Mail\BelievePointGiftInviteCancelledMail::class);
    Mail::assertSent(\App\Mail\BelievePointGiftInviteCancelledSenderMail::class);

    $this->assertDatabaseHas('transactions', [
        'type' => 'bp_gift_hold_refund',
        'user_id' => $sender->id,
    ]);

    $this->assertDatabaseHas('believe_point_gift_invite_goodwills', [
        'email' => 'wrong.address@example.com',
        'sender_id' => $sender->id,
    ]);
});

it('does not allow claiming a cancelled invite', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 50,
        'holding_believe_points' => 0,
    ]);

    $invite = BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => 'cancelled.claim@example.com',
        'gift_occasion_id' => 1,
        'amount' => 20,
        'occasion' => 'Birthday',
        'token' => 'test-token-cancelled-claim-123456789012',
        'status' => BelievePointGiftInvite::STATUS_CANCELLED,
        'expires_at' => now()->addDays(14),
        'cancelled_at' => now(),
        'refunded_at' => now(),
    ]);

    $recipient = User::factory()->create([
        'role' => 'user',
        'email' => 'cancelled.claim@example.com',
        'gifted_believe_points' => 0,
    ]);

    $claimed = app(BelievePointGiftInviteService::class)->claimPendingForUser($recipient);

    expect($claimed)->toBe([]);
    $invite->refresh();
    expect($invite->status)->toBe(BelievePointGiftInvite::STATUS_CANCELLED);
    expect((float) $recipient->fresh()->gifted_believe_points)->toBe(0.0);
});

it('changes pending invite email without moving holding balance', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 40,
        'holding_believe_points' => 15,
    ]);

    $invite = BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => 'old.email@example.com',
        'gift_occasion_id' => 1,
        'amount' => 15,
        'occasion' => 'Birthday',
        'token' => 'test-token-change-email-12345678901234',
        'status' => BelievePointGiftInvite::STATUS_PENDING,
        'expires_at' => now()->addDays(14),
    ]);

    $response = $this->actingAs($sender)->post(route('gift-bp.invites.email', $invite), [
        'email' => 'corrected@example.com',
    ]);

    $response->assertSessionHasNoErrors();

    $sender->refresh();
    $invite->refresh();

    expect((float) $sender->holding_believe_points)->toBe(15.0);
    expect((float) $sender->believe_points)->toBe(40.0);
    expect($invite->recipient_email)->toBe('corrected@example.com');
    expect($invite->status)->toBe(BelievePointGiftInvite::STATUS_PENDING);

    Mail::assertSent(\App\Mail\BelievePointGiftInviteCancelledMail::class);
    Mail::assertSent(\App\Mail\BelievePointGiftInviteMail::class);
    Mail::assertSent(\App\Mail\BelievePointGiftInviteEmailChangedMail::class);
});

it('resends a pending invite email', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 40,
        'holding_believe_points' => 10,
    ]);

    $invite = BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => 'resend.me@example.com',
        'gift_occasion_id' => 1,
        'amount' => 10,
        'occasion' => 'Birthday',
        'token' => 'test-token-resend-123456789012345678901',
        'status' => BelievePointGiftInvite::STATUS_PENDING,
        'expires_at' => now()->addDays(14),
    ]);

    $response = $this->actingAs($sender)->post(route('gift-bp.invites.resend', $invite));

    $response->assertSessionHasNoErrors();
    $invite->refresh();
    expect($invite->last_resent_at)->not->toBeNull();
    Mail::assertSent(\App\Mail\BelievePointGiftInviteMail::class);
});

it('awards cancellation goodwill brp when the former invitee registers', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 50,
        'holding_believe_points' => 0,
    ]);

    $invite = BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => 'goodwill@example.com',
        'gift_occasion_id' => 1,
        'amount' => 12,
        'occasion' => 'Birthday',
        'token' => 'test-token-goodwill-123456789012345678',
        'status' => BelievePointGiftInvite::STATUS_CANCELLED,
        'expires_at' => now()->addDays(14),
        'cancelled_at' => now(),
        'refunded_at' => now(),
    ]);

    \App\Models\BelievePointGiftInviteGoodwill::create([
        'invite_id' => $invite->id,
        'sender_id' => $sender->id,
        'email' => 'goodwill@example.com',
        'sender_name' => $sender->name,
        'brp_amount' => 10,
        'reason' => 'cancelled',
    ]);

    $recipient = User::factory()->create([
        'role' => 'user',
        'email' => 'goodwill@example.com',
        'reward_points' => 0,
    ]);

    $awarded = app(BelievePointGiftInviteService::class)->awardCancellationGoodwillForUser($recipient);

    expect($awarded)->toBe(10.0);
    expect((float) $recipient->fresh()->reward_points)->toBe(10.0);
    $this->assertDatabaseHas('believe_point_gift_invite_goodwills', [
        'email' => 'goodwill@example.com',
        'awarded_user_id' => $recipient->id,
    ]);
});

it('blocks cancelling an invite after it is claimed', function () {
    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 10,
        'holding_believe_points' => 0,
    ]);

    $invite = BelievePointGiftInvite::create([
        'sender_id' => $sender->id,
        'recipient_email' => 'already.claimed@example.com',
        'gift_occasion_id' => 1,
        'amount' => 10,
        'occasion' => 'Birthday',
        'token' => 'test-token-claimed-block-123456789012',
        'status' => BelievePointGiftInvite::STATUS_CLAIMED,
        'expires_at' => now()->addDays(14),
        'claimed_at' => now(),
    ]);

    $response = $this->actingAs($sender)->post(route('gift-bp.invites.cancel', $invite));

    $response->assertSessionHasErrors('invite');
});

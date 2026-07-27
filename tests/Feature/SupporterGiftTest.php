<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

it('sends a gift immediately by debiting sender available and crediting recipient available plus gift reporting', function () {
    DB::table('gift_occasions')->insert([
        'id' => 1,
        'occasion' => 'Birthday',
        'icon' => '🎂',
        'category' => 'Celebration',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 250,
        'gifted_believe_points' => 0,
        'holding_believe_points' => 0,
    ]);

    $recipient = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
        'believe_points' => 0,
        'gifted_believe_points' => 10,
    ]);

    $response = $this->actingAs($sender)->post("/supporters/gift/{$recipient->id}", [
        'amount' => 200,
        'gift_occasion_id' => 1,
        'message' => 'Happy Birthday. Hope you have a blessed day.',
    ]);

    $response->assertRedirect(route('find-supporters.index'));
    $response->assertSessionHasNoErrors();

    $sender->refresh();
    $recipient->refresh();

    expect((float) $sender->believe_points)->toBe(50.0);
    expect((float) $sender->holding_believe_points)->toBe(0.0);
    expect((float) $recipient->believe_points)->toBe(200.0);
    expect((float) $recipient->gifted_believe_points)->toBe(210.0);

    $this->assertDatabaseHas('supporter_believe_point_gifts', [
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'gift_occasion_id' => 1,
        'amount' => 200.00,
        'occasion' => 'Birthday',
        'message' => 'Happy Birthday. Hope you have a blessed day.',
    ]);

    expect($recipient->notifications()->count())->toBe(1);
    expect($recipient->notifications()->first()->data['type'] ?? null)->toBe('gift_received');
});

it('rejects a gift when the sender does not have enough believe points', function () {
    DB::table('gift_occasions')->insert([
        'id' => 1,
        'occasion' => 'Birthday',
        'icon' => '🎂',
        'category' => 'Celebration',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sender = User::factory()->create([
        'role' => 'user',
        'believe_points' => 25,
    ]);

    $recipient = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
    ]);

    $response = $this->from('/find-supporters')->actingAs($sender)->post("/supporters/gift/{$recipient->id}", [
        'amount' => 200,
        'gift_occasion_id' => 1,
    ]);

    $response->assertRedirect('/find-supporters');
    $response->assertSessionHasErrors('amount');

    $sender->refresh();
    $recipient->refresh();

    expect((float) $sender->believe_points)->toBe(25.0);
    expect((float) $recipient->gifted_believe_points)->toBe(0.0);
    $this->assertDatabaseCount('supporter_believe_point_gifts', 0);
});

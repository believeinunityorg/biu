<?php

use App\Models\User;
use App\Services\GiftCardGiftedPointsPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('closed-loop gift cards use full available and reduce gift reporting', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'believe_points' => 30,
        'gifted_believe_points' => 10,
    ]);

    $result = $user->deductAvailableBelievePointsForGiftCard(5, isClosedLoop: true);

    expect($result)->not->toBeNull()
        ->and($result['from_gifted'])->toBe(5.0);

    $user->refresh();
    expect((float) $user->believe_points)->toBe(25.0)
        ->and((float) $user->gifted_believe_points)->toBe(5.0);
});

it('blocks visa mastercard when only gift bp would cover the cost', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'believe_points' => 30,
        'gifted_believe_points' => 30,
    ]);

    expect($user->purchasedBelievePointsBalance())->toBe(0.0)
        ->and($user->deductAvailableBelievePointsForGiftCard(5, isClosedLoop: false))->toBeNull();

    $user->refresh();
    expect((float) $user->believe_points)->toBe(30.0)
        ->and((float) $user->gifted_believe_points)->toBe(30.0);
});

it('allows visa mastercard with purchased bp without reducing gift reporting', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'believe_points' => 30,
        'gifted_believe_points' => 10,
    ]);

    $result = $user->deductAvailableBelievePointsForGiftCard(5, isClosedLoop: false);

    expect($result)->not->toBeNull()
        ->and($result['from_gifted'])->toBe(0.0);

    $user->refresh();
    expect((float) $user->believe_points)->toBe(25.0)
        ->and((float) $user->gifted_believe_points)->toBe(10.0);
});

it('other modules spend purchased only', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'believe_points' => 30,
        'gifted_believe_points' => 25,
    ]);

    expect($user->deductBelievePoints(10))->toBeFalse()
        ->and($user->deductBelievePoints(5))->toBeTrue();

    $user->refresh();
    expect((float) $user->believe_points)->toBe(25.0)
        ->and((float) $user->gifted_believe_points)->toBe(25.0);
});

it('classifies visa and mastercard as open-loop', function () {
    expect(GiftCardGiftedPointsPolicy::isClosedLoop('Amazon'))->toBeTrue()
        ->and(GiftCardGiftedPointsPolicy::isClosedLoop('Visa Prepaid'))->toBeFalse()
        ->and(GiftCardGiftedPointsPolicy::isOpenLoop('Mastercard Gift'))->toBeTrue();
});

<?php

namespace App\Services;

use App\Models\User;

/**
 * Determines whether a supporter profile meets completion criteria for a one-time BRP award.
 */
final class SupporterProfileCompletionService
{
    /**
     * Minimum fields required on /profile/edit for a normal supporter (role user).
     * Used to force first-time profile setup after registration / login.
     */
    public static function hasRequiredEditFields(User $user): bool
    {
        if (($user->role ?? null) !== 'user') {
            return true;
        }

        return filled($user->city)
            && filled($user->state)
            && filled($user->dob);
    }

    /**
     * True when a normal supporter still needs to finish onboarding or required profile fields.
     */
    public static function needsProfileSetup(User $user): bool
    {
        if (($user->role ?? null) !== 'user') {
            return false;
        }

        if ($user->onboarding_completed_at === null) {
            return true;
        }

        return ! self::hasRequiredEditFields($user);
    }

    public static function needsOnboarding(User $user): bool
    {
        return ($user->role ?? null) === 'user' && $user->onboarding_completed_at === null;
    }

    public static function isComplete(User $user): bool
    {
        if (($user->role ?? null) !== 'user') {
            return false;
        }

        if (! filled($user->city) || ! filled($user->state)) {
            return false;
        }

        if (! filled($user->contact_number)) {
            return false;
        }

        if (! filled($user->registered_user_image) && ! filled($user->image)) {
            return false;
        }

        if ($user->supporterPositions()->count() < 1) {
            return false;
        }

        if ($user->supporterInterestCategories()->count() < 1) {
            return false;
        }

        if (! filled($user->volunteer_interest_statement) || strlen(trim((string) $user->volunteer_interest_statement)) < 10) {
            return false;
        }

        return true;
    }

    /**
     * @return array{percent: int, completed: int, total: int, complete: bool}
     */
    public static function progress(User $user): array
    {
        $checks = [
            filled($user->city) && filled($user->state),
            filled($user->contact_number),
            filled($user->registered_user_image) || filled($user->image),
            $user->supporterPositions()->count() >= 1,
            $user->supporterInterestCategories()->count() >= 1,
            filled($user->volunteer_interest_statement) && strlen(trim((string) $user->volunteer_interest_statement)) >= 10,
        ];

        $completed = count(array_filter($checks));
        $total = count($checks);

        return [
            'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
            'completed' => $completed,
            'total' => $total,
            'complete' => $completed === $total,
        ];
    }
}

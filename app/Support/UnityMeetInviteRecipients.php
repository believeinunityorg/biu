<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Search verified supporters for Unity Meet invite pickers.
 */
final class UnityMeetInviteRecipients
{
    /**
     * @return list<array{id: int, name: string, email: string, image: string|null}>
     */
    public static function search(User $host, string $query, int $limit = 12): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $like = '%'.$query.'%';
        $hostEmail = Str::lower(trim((string) $host->email));

        return User::query()
            ->where('role', 'user')
            ->whereNotNull('email_verified_at')
            ->whereNotNull('email')
            ->where('id', '!=', $host->id)
            ->when($hostEmail !== '', fn ($q) => $q->whereRaw('LOWER(email) != ?', [$hostEmail]))
            ->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)
                    ->orWhere('email', 'LIKE', $like)
                    ->orWhere('slug', 'LIKE', $like);
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'image'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => (string) $u->name,
                'email' => Str::lower(trim((string) $u->email)),
                'image' => $u->image ? '/storage/'.$u->image : null,
            ])
            ->filter(fn (array $row) => $row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }
}

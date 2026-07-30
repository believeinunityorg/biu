<?php

namespace App\Support;

/**
 * First-party avatar URLs for VDO.Ninja &avatar= (camera-off tile).
 * Avoids ui-avatars.com, which returns duplicate Access-Control-Allow-Origin values
 * and breaks when VDO loads the image from https://vdo.ninja.
 */
final class VdoMeetingAvatar
{
    public static function url(string $displayName, int $size = 256): string
    {
        $name = trim($displayName) !== '' ? trim($displayName) : 'Guest';
        $size = max(64, min(512, $size));

        return url('/meet/vdo-avatar').'?'.http_build_query([
            'name' => $name,
            'size' => $size,
        ]);
    }
}

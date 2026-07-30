<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * CORS-safe initials avatar for VDO.Ninja (&avatar=).
 * Served from our domain so Chromium does not hit ui-avatars.com's broken double CORS header.
 */
class VdoMeetingAvatarController extends Controller
{
    public function __invoke(Request $request): SymfonyResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders($this->corsHeaders());
        }

        $name = trim((string) $request->query('name', 'Guest'));
        if ($name === '') {
            $name = 'Guest';
        }
        $name = mb_substr($name, 0, 80);

        $size = (int) $request->query('size', 256);
        $size = max(64, min(512, $size));
        $initials = htmlspecialchars($this->initials($name), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $fontSize = (int) max(24, $size * 0.38);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#9333ea"/>
      <stop offset="100%" stop-color="#2563eb"/>
    </linearGradient>
  </defs>
  <rect width="{$size}" height="{$size}" fill="url(#g)"/>
  <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" fill="#ffffff" font-family="system-ui,-apple-system,Segoe UI,sans-serif" font-size="{$fontSize}" font-weight="700">{$initials}</text>
</svg>
SVG;

        return response($svg, 200, array_merge($this->corsHeaders(), [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(): array
    {
        // Single value only — duplicate * breaks Chromium CORS checks from vdo.ninja.
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Origin, Accept, Content-Type',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return 'G';
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[1], 0, 1));
    }
}

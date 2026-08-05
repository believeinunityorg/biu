<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Create / update Better Stack uptime monitors for this app's health URLs.
 * Production Roadmap: Server Monitoring → Better Stack + Laravel Horizon.
 */
class SyncBetterStackMonitorsCommand extends Command
{
    protected $signature = 'betterstack:sync-monitors
        {--dry-run : Print planned monitors without calling the API}';

    protected $description = 'Sync Better Stack uptime monitors for /up and /api/health';

    public function handle(): int
    {
        $token = (string) config('services.betterstack.uptime_api_token', '');
        $base = rtrim((string) config('app.url'), '/');
        $name = (string) config('app.name', 'BIU');

        $monitors = [
            [
                'pronounceable_name' => "{$name} liveness (/up)",
                'url' => "{$base}/up",
                'monitor_type' => 'status',
                'check_frequency' => 180, // Better Stack free tier: 3-minute interval
                'request_timeout' => 30,
                'recovery_period' => 0,
                'confirmation_period' => 0,
            ],
            [
                'pronounceable_name' => "{$name} readiness (/api/health)",
                'url' => "{$base}/api/health",
                'monitor_type' => 'status',
                'check_frequency' => 180, // Better Stack free tier: 3-minute interval
                'request_timeout' => 30,
                'expected_status_codes' => [200],
                'recovery_period' => 0,
                'confirmation_period' => 0,
            ],
        ];

        foreach ($monitors as $monitor) {
            $this->line(($this->option('dry-run') ? '[dry-run] ' : '').$monitor['pronounceable_name'].' → '.$monitor['url']);
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if ($token === '') {
            $this->error('Set BETTER_STACK_UPTIME_API_TOKEN in .env (Better Stack → Uptime → API tokens).');

            return self::FAILURE;
        }

        $existing = Http::withToken($token)
            ->acceptJson()
            ->get('https://uptime.betterstack.com/api/v2/monitors');

        if (! $existing->successful()) {
            $this->error('Failed listing monitors: '.$existing->status().' '.$existing->body());

            return self::FAILURE;
        }

        $byUrl = [];
        foreach ($existing->json('data') ?? [] as $row) {
            $url = $row['attributes']['url'] ?? null;
            if (is_string($url) && $url !== '') {
                $byUrl[$url] = $row['id'];
            }
        }

        foreach ($monitors as $payload) {
            $url = $payload['url'];
            if (isset($byUrl[$url])) {
                $res = Http::withToken($token)
                    ->acceptJson()
                    ->patch('https://uptime.betterstack.com/api/v2/monitors/'.$byUrl[$url], $payload);
                $this->info($res->successful()
                    ? "Updated monitor {$url}"
                    : "Update failed {$url}: {$res->status()} {$res->body()}");

                continue;
            }

            $res = Http::withToken($token)
                ->acceptJson()
                ->post('https://uptime.betterstack.com/api/v2/monitors', $payload);
            $this->info($res->successful()
                ? "Created monitor {$url}"
                : "Create failed {$url}: {$res->status()} {$res->body()}");
        }

        return self::SUCCESS;
    }
}

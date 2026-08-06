<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CleanLogFile extends Command
{
    protected $signature = 'log:clean
        {--size=10 : Maximum file size in MB before truncation}
        {--days= : Also delete daily log files older than this many days (default: LOG_DAILY_DAYS)}';

    protected $description = 'Truncate oversized Laravel logs and prune old daily log files';

    public function handle(): int
    {
        $maxSizeMB = (float) $this->option('size');
        $maxSizeBytes = (int) ($maxSizeMB * 1024 * 1024);
        $days = (int) ($this->option('days') ?: config('logging.channels.daily.days', 14));
        $logDir = storage_path('logs');

        if (! File::isDirectory($logDir)) {
            $this->info('Log directory does not exist. Nothing to clean.');

            return self::SUCCESS;
        }

        $cleaned = 0;

        foreach (File::files($logDir) as $file) {
            $name = $file->getFilename();
            if (! preg_match('/^laravel(-.*)?\.log$/', $name) && $name !== 'laravel.log') {
                continue;
            }

            $size = $file->getSize();
            if ($size >= $maxSizeBytes) {
                File::put($file->getPathname(), '');
                $cleaned++;
                $this->info(sprintf(
                    'Truncated %s (was %.2f MB)',
                    $name,
                    $size / 1024 / 1024
                ));
            }
        }

        $cutoff = now()->subDays(max(1, $days))->startOfDay();
        foreach (File::files($logDir) as $file) {
            $name = $file->getFilename();
            // laravel-2026-08-06.log
            if (! preg_match('/^laravel-(\d{4}-\d{2}-\d{2})\.log$/', $name, $m)) {
                continue;
            }
            try {
                $fileDate = \Carbon\Carbon::createFromFormat('Y-m-d', $m[1])->startOfDay();
            } catch (\Throwable) {
                continue;
            }
            if ($fileDate->lt($cutoff)) {
                File::delete($file->getPathname());
                $cleaned++;
                $this->info("Deleted old daily log {$name}");
            }
        }

        if ($cleaned > 0) {
            Log::channel('daily')->info('Log cleanup completed.', [
                'actions' => $cleaned,
                'max_mb' => $maxSizeMB,
                'retain_days' => $days,
            ]);
        } else {
            $this->info('No log cleanup needed.');
        }

        return self::SUCCESS;
    }
}

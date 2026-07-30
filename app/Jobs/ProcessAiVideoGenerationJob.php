<?php

namespace App\Jobs;

use App\Models\AiVideo;
use App\Models\User;
use App\Services\AiMediaStudioDropboxService;
use App\Services\AiMediaStudioVideoWatermarkService;
use App\Services\FalVideoService;
use App\Services\OpenAiService;
use App\Support\AiMediaStudioResolution;
use App\Support\AiMediaStudioTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async pipeline: OpenAI (script + fal-ready prompt) → fal.ai queue (video URL) → Dropbox (optional) → ready.
 *
 * Run workers with a timeout at least as large as {@see self::$timeout}, for example:
 * `php artisan queue:work --timeout=900` (default 60s will kill long fal + Dropbox work).
 */
class ProcessAiVideoGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds before Laravel marks the job as timed out (fal + Dropbox can exceed a few minutes). */
    public int $timeout = 900;

    public function __construct(public int $aiVideoId) {}

    public function handle(
        OpenAiService $openAi,
        FalVideoService $fal,
        AiMediaStudioDropboxService $dropboxService,
        AiMediaStudioVideoWatermarkService $watermarkService,
    ): void {
        $mem = config('services.ai_media_studio.queue_worker_memory_limit');
        if (is_string($mem) && $mem !== '' && function_exists('ini_set')) {
            @ini_set('memory_limit', $mem);
        }

        $video = AiVideo::query()->find($this->aiVideoId);
        if (! $video) {
            return;
        }

        try {
            $video->update(['status' => AiVideo::STATUS_BUILDING_PROMPT]);

            $min = (int) config('services.ai_media_studio.video_duration_min', 5);
            $max = (int) config('services.ai_media_studio.video_duration_max', 10);
            if ($max < $min) {
                $max = $min;
            }
            $durationSeconds = (int) ($video->duration_seconds ?? $max);
            $durationSeconds = in_array($durationSeconds, [5, 10], true) ? $durationSeconds : 5;
            $durationSeconds = max($min, min($max, $durationSeconds));

            $templateLabel = null;
            if (is_string($video->template_key) && $video->template_key !== '') {
                $templates = AiMediaStudioTemplates::all();
                foreach ($templates as $t) {
                    if (($t['key'] ?? '') === $video->template_key) {
                        $templateLabel = $t['label'] ?? null;
                        break;
                    }
                }
            }

            $package = $openAi->generateAiMediaStudioPackage([
                'title' => $video->title,
                'user_prompt' => $video->prompt,
                'template_key' => $video->template_key,
                'template_label' => $templateLabel,
                'template_inputs' => is_array($video->template_inputs) ? $video->template_inputs : [],
                'orientation' => $video->orientation,
                'duration_seconds' => $durationSeconds,
            ]);

            $tokens = (int) ($package['total_tokens'] ?? 0);
            if ($tokens > 0) {
                $owner = User::query()->whereKey($video->user_id)->first();
                $owner?->consumeAiTokens($tokens);
            }

            $falVideoPrompt = (string) ($package['fal_video_prompt'] ?? '');

            $video->update([
                'ai_script' => $package['ai_script'],
                'fal_prompt' => $package['fal_video_prompt'],
                'caption' => $package['caption'] ?: null,
                'hashtags' => $package['hashtags'] ?: null,
            ]);
            unset($package);

            $video->update(['status' => AiVideo::STATUS_RENDERING_VIDEO]);

            $modelId = is_string($video->fal_model) && trim($video->fal_model) !== ''
                ? trim($video->fal_model, '/')
                : $fal->defaultModelId();

            $video->update([
                'fal_provider' => 'fal',
                'fal_model' => $modelId,
            ]);

            $tier = is_string($video->resolution_tier) ? strtolower(trim($video->resolution_tier)) : '';
            if ($tier === '') {
                $tier = AiMediaStudioResolution::inferTierFromResolutionString($video->resolution, $video->orientation);
            }
            if ($tier === '') {
                $tier = AiMediaStudioResolution::defaultTier();
            }

            $falOverrides = AiMediaStudioResolution::falQueueSizePayload($video->orientation, $tier);

            $queueInput = $fal->buildQueueInput($falVideoPrompt, $durationSeconds, $falOverrides);
            $falOut = $fal->generateVideoUrl($modelId, $queueInput);

            $falUrl = $falOut['video_url'];
            $falRequestId = $falOut['request_id'];
            unset($falOut);

            $video->update([
                'fal_job_id' => $falRequestId,
                'fal_cdn_url' => $falUrl,
                'status' => AiVideo::STATUS_VIDEO_GENERATED,
            ]);

            $branded = null;
            try {
                $branded = $watermarkService->downloadAndBrand($video, $falUrl);
            } catch (\Throwable $brandEx) {
                Log::warning('ai_media_studio.watermark_failed', [
                    'ai_video_id' => $video->id,
                    'error' => $brandEx->getMessage(),
                ]);
            }

            $playbackUrl = is_array($branded) ? ($branded['public_url'] ?? $falUrl) : $falUrl;
            $video->update([
                'video_source_url' => $playbackUrl,
            ]);

            $video->update(['status' => AiVideo::STATUS_UPLOADING_TO_DROPBOX]);

            try {
                $localBranded = is_array($branded) ? ($branded['local_path'] ?? null) : null;
                if (is_string($localBranded) && is_file($localBranded)) {
                    $dropboxService->mirrorLocalVideoToDropbox($video, $localBranded);
                } else {
                    $dropboxService->mirrorFalVideoToDropbox($video, $falUrl);
                }
            } catch (\Throwable $dropEx) {
                Log::warning('ai_media_studio.dropbox_mirror_failed', [
                    'ai_video_id' => $video->id,
                    'error' => $dropEx->getMessage(),
                ]);
            }

            $video->refresh();
            $video->update(['status' => AiVideo::STATUS_READY_FOR_REVIEW]);
            gc_collect_cycles();
        } catch (\Throwable $e) {
            Log::error('ai_video.pipeline.failed', [
                'ai_video_id' => $video->id,
                'error' => $e->getMessage(),
            ]);

            $video->update([
                'status' => AiVideo::STATUS_FAILED,
                'failure_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Refund AI Media Studio credits if the pipeline ultimately failed (after retries).
     */
    public function failed(?\Throwable $e): void
    {
        $video = AiVideo::query()->find($this->aiVideoId);
        if (! $video || $video->media_studio_credits_refunded_at) {
            return;
        }

        $charged = (float) ($video->media_studio_credits_charged ?? 0);
        if ($charged < 0.005) {
            return;
        }

        User::query()->whereKey($video->user_id)->increment('ai_media_studio_credits', $charged);
        $video->forceFill(['media_studio_credits_refunded_at' => now()])->save();
    }
}

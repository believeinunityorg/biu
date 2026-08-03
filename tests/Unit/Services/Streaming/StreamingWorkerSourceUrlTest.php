<?php

namespace Tests\Unit\Services\Streaming;

use App\Models\UserLivestream;
use App\Support\StreamingWorkerSourceUrl;
use Tests\TestCase;

/**
 * The worker pulls `<path>_aac` (H.264/AAC, produced by the bridge's runOnReady
 * transcoder), never the raw `<path>` (VP8/Opus WHIP ingest, which RTMP cannot
 * serve). Sending the raw path fails in under a second with "Input/output
 * error" and looks exactly like "the host never went live" — which cost a full
 * debugging session on 2026-07-30/31.
 */
class StreamingWorkerSourceUrlTest extends TestCase
{
    private function livestream(int $id = 90): UserLivestream
    {
        $livestream = new UserLivestream;
        $livestream->forceFill(['id' => $id]);

        return $livestream;
    }

    public function test_appends_aac_suffix_to_the_rtmp_pull_base(): void
    {
        config([
            'streaming.worker_source_url_template' => '',
            'streaming.worker_rtmp_pull_base' => 'rtmp://stream.believeinunity.org:1935',
        ]);

        $this->assertSame(
            'rtmp://stream.believeinunity.org:1935/ls_90_aac',
            StreamingWorkerSourceUrl::resolve($this->livestream()),
        );
    }

    public function test_does_not_double_suffix_a_template_that_already_ends_in_aac(): void
    {
        config([
            'streaming.worker_source_url_template' => 'rtmp://stream.believeinunity.org:1935/{mediamtx_path}_aac',
        ]);

        $this->assertSame(
            'rtmp://stream.believeinunity.org:1935/ls_90_aac',
            StreamingWorkerSourceUrl::resolve($this->livestream()),
        );
    }

    public function test_collapses_an_existing_double_suffix(): void
    {
        config([
            'streaming.worker_source_url_template' => 'rtmp://stream.believeinunity.org:1935/{mediamtx_path}_aac_aac',
        ]);

        $this->assertSame(
            'rtmp://stream.believeinunity.org:1935/ls_90_aac',
            StreamingWorkerSourceUrl::resolve($this->livestream()),
        );
    }

    public function test_stream_path_stays_raw_for_whip_publishing(): void
    {
        // streamPath() is the WHIP publish identity (whipUrl, push, seat paths).
        // The publisher must keep publishing to the un-suffixed path.
        $this->assertSame('ls_90', StreamingWorkerSourceUrl::streamPath($this->livestream()));
    }
}

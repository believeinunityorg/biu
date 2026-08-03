<?php

return [
    'region' => env('AWS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    'aws_key' => env('AWS_ACCESS_KEY_ID'),
    'aws_secret' => env('AWS_SECRET_ACCESS_KEY'),
    'queue_url' => env('SQS_STREAMING_QUEUE_URL'),
    'callback_token' => env('LARAVEL_CALLBACK_TOKEN'),
    // Delay the worker pull so the browser publisher has time to connect to MediaMTX.
    // AWS SQS supports DelaySeconds from 0 to 900. Default 0 — no artificial wait before the worker runs.
    'sqs_delay_seconds' => max(0, min(900, (int) env('STREAMING_SQS_DELAY_SECONDS', 0))),

    /*
     * Public base URL AWS workers must use to POST /api/streaming/status.
     * Leave empty to use APP_URL (route(...)) — localhost will NOT receive callbacks from AWS.
     *
     * Local + real worker: expose this app via ngrok/Cloudflare Tunnel and set e.g.
     *   STREAMING_CALLBACK_BASE_URL=https://abc123.ngrok-free.app
     * (same host where Laravel answers; bearer token unchanged)
     */
    'callback_base_url' => env('STREAMING_CALLBACK_BASE_URL'),
    'max_duration_minutes' => (int) env('STREAMING_MAX_DURATION_MINUTES', 120),

    /*
     * Application-side lifecycle (do not rely only on AWS worker callbacks).
     * A scheduled reconcile command and per-request checks clear stuck jobs.
     */
    'lifecycle' => [
        // queued → failed if worker never reports starting
        'queued_timeout_seconds' => max(60, (int) env('STREAMING_QUEUED_TIMEOUT_SECONDS', 300)),
        // starting / preparing → failed if startup exceeds limit (client: ~2 min max)
        'starting_timeout_seconds' => max(60, (int) env('STREAMING_STARTING_TIMEOUT_SECONDS', 120)),
        // live/starting with no heartbeat (worker polls ~10s when running)
        'heartbeat_stale_seconds' => max(30, (int) env('STREAMING_HEARTBEAT_STALE_SECONDS', 120)),
        // after End Stream, force local job stop if worker never callbacks
        'stop_requested_grace_seconds' => max(15, (int) env('STREAMING_STOP_REQUESTED_GRACE_SECONDS', 45)),
        // extra minutes after max_duration before auto-stop
        'max_duration_grace_minutes' => max(0, (int) env('STREAMING_MAX_DURATION_GRACE_MINUTES', 5)),
    ],

    /*
     * ECS Fargate worker monitoring (DescribeTasks / StopTask).
     * Laravel producer IAM user needs ecs:DescribeTasks + ecs:StopTask on the worker
     * cluster (in addition to sqs:SendMessage). Worker should POST task_arn on callbacks.
     */
    'ecs' => [
        'enabled' => filter_var(env('STREAMING_ECS_MONITOR_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'cluster' => env('STREAMING_ECS_CLUSTER', 'biu-stream-prod-cluster'),
        'region' => env('STREAMING_ECS_REGION', env('AWS_REGION', 'us-east-1')),
    ],

    // FFmpeg source URL template for worker jobs.
    // Placeholders: {room} {room_slug} {livestream_id} {user_id} {organization_id} {mediamtx_path}
    //
    // Do not add an `_aac` suffix here. StreamingWorkerSourceUrl::resolve()
    // appends exactly one to every rtmp:// result (and collapses a duplicate),
    // because only the bridge's transcoded `<path>_aac` is RTMP-readable —
    // {mediamtx_path} on its own is the raw VP8 WHIP ingest.
    'worker_source_url_template' => env('STREAMING_WORKER_SOURCE_URL_TEMPLATE', ''),
    // RTMP base URL worker can pull from when no template is provided.
    // If empty, falls back to MEDIAMTX_RTMP_PUBLIC from config/services.php.
    'worker_rtmp_pull_base' => env('STREAMING_WORKER_RTMP_PULL_BASE', ''),

    /*
     * SQS uses the AWS SDK (Guzzle/cURL). On some Windows PHP installs you get
     * "cURL error 60: unable to get local issuer certificate".
     *
     * Preferred: download https://curl.se/ca/cacert.pem and set either env:
     *   AWS_CA_BUNDLE=C:\path\to\cacert.pem
     *   STREAMING_AWS_CA_BUNDLE=C:\path\to\cacert.pem
     *
     * Local-only fallback (insecure — do not use in production):
     *   STREAMING_HTTP_VERIFY=false
     */
    'aws_ca_bundle' => env('AWS_CA_BUNDLE', env('STREAMING_AWS_CA_BUNDLE')),
    'http_verify' => filter_var(
        env('STREAMING_HTTP_VERIFY', true),
        FILTER_VALIDATE_BOOLEAN
    ),
    'billing' => [
        'free_minutes_per_month' => (int) env('STREAMING_FREE_MINUTES_PER_MONTH', 1800),
        'rate_cents_per_hour' => (int) env('STREAMING_RATE_CENTS_PER_HOUR', 8),
    ],

    /*
     * Pre-launch gates for queueStreamRelayJob (INTEGRATION.pdf steps 3, 4, 7).
     * Every gate defaults to off so existing flows are unchanged.
     *
     *   STREAMING_REQUIRE_SUBSCRIPTION=true       — blocks if Cashier subscription is inactive
     *   STREAMING_SUBSCRIPTION_TYPE=default       — Cashier subscription name to check
     *   STREAMING_PERMISSION_NAME=livestream.start — Spatie/Gate ability checked on the host user
     *   STREAMING_HARD_QUOTA_MINUTES=3000          — blocks once a calendar-month total hits this cap
     */
    'gates' => [
        'require_subscription' => filter_var(
            env('STREAMING_REQUIRE_SUBSCRIPTION', false),
            FILTER_VALIDATE_BOOLEAN
        ),
        'subscription_type' => env('STREAMING_SUBSCRIPTION_TYPE', 'default'),
        'permission_name' => env('STREAMING_PERMISSION_NAME', ''),
        'hard_quota_minutes' => (int) env('STREAMING_HARD_QUOTA_MINUTES', 0),
    ],

    /*
     * Without a real ECS/Lambda/etc. worker that polls SQS and POSTs /api/streaming/status,
     * meetings stay queued forever and never show "live".
     *
     * Local / staging only — skips SQS and applies starting → live in-process:
     *   STREAMING_SIMULATE_WORKER=true
     * Ignored in production unless:
     *   STREAMING_SIMULATE_WORKER_FORCE=true
     */
    'simulate_worker' => filter_var(
        env('STREAMING_SIMULATE_WORKER', false),
        FILTER_VALIDATE_BOOLEAN
    ),
    'simulate_worker_force' => filter_var(
        env('STREAMING_SIMULATE_WORKER_FORCE', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
     * MediaMTX bridge — VDO.Ninja publishes through &mediamtx=<host>, and Laravel sends
     * the resulting RTMP path to the AWS worker as source_url.
     * Example host: stream.501c3ers.com:443
     *
     * VDO.Ninja cannot send auth headers with &mediamtx, so /whip must be open or relaxed
     * on the MediaMTX side.
     */
    'bridge' => [
        'host' => env('STREAMING_BRIDGE_HOST', ''),
        'whip_port' => (int) env('STREAMING_BRIDGE_WHIP_PORT', 8889),
        'publish_user' => env('STREAMING_BRIDGE_PUBLISH_USER', 'publisher'),
        'publish_pass' => env('STREAMING_BRIDGE_PUBLISH_PASS', ''),
        // Master switch: browser VDO &mediamtx= WHIP to the bridge (all environments).
        'browser_push_enabled' => filter_var(
            env('STREAMING_BRIDGE_BROWSER_PUSH_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        // Extra gate for APP_ENV=local|development only (default off — avoids "WHIP out failed" locally).
        'push_on_local' => filter_var(env('STREAMING_BRIDGE_PUSH_ON_LOCAL', false), FILTER_VALIDATE_BOOLEAN),
    ],
];

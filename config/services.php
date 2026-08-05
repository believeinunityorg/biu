<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'live_key' => env('STRIPE_LIVE_KEY'),
        'live_secret' => env('STRIPE_LIVE_SECRET'),
        /**
         * Stripe Tax: when true, Checkout Sessions pass automatic_tax.enabled (gift cards, donations, etc.).
         * You must complete Stripe Dashboard → Tax first, including a valid head office / origin address.
         * Test mode: https://dashboard.stripe.com/test/settings/tax — without this, Stripe returns
         * "You must have a valid head office address to enable automatic tax calculation in test mode."
         * Set STRIPE_AUTOMATIC_TAX=false until Tax settings are done, then turn it back on.
         */
        'automatic_tax' => filter_var(env('STRIPE_AUTOMATIC_TAX', false), FILTER_VALIDATE_BOOLEAN),
        /** Default Stripe product tax codes (see Stripe Tax settings). */
        'tax_code_physical' => env('STRIPE_TAX_CODE_PHYSICAL', 'txcd_99999999'),
        'tax_code_digital' => env('STRIPE_TAX_CODE_DIGITAL', 'txcd_10000000'),
        /** Course enrollment Checkout line items (Stripe Tax product tax codes). */
        'tax_code_course_digital' => env('STRIPE_TAX_CODE_COURSE_DIGITAL', env('STRIPE_TAX_CODE_DIGITAL', 'txcd_10000000')),
        'tax_code_course_physical' => env('STRIPE_TAX_CODE_COURSE_PHYSICAL', env('STRIPE_TAX_CODE_PHYSICAL', 'txcd_99999999')),
        /** BIU: delivery + content mapping (Stripe Tax product tax codes). */
        'tax_code_live_virtual_training' => env('STRIPE_TAX_CODE_LIVE_VIRTUAL_TRAINING', 'txcd_20060045'),
        'tax_code_self_study_web' => env('STRIPE_TAX_CODE_SELF_STUDY_WEB', 'txcd_20060058'),
        'tax_code_on_demand_written' => env('STRIPE_TAX_CODE_ON_DEMAND_WRITTEN', 'txcd_20060358'),
        'tax_code_streamed_prerecorded' => env('STRIPE_TAX_CODE_STREAMED_PRERECORDED', 'txcd_20060158'),
        'tax_code_streamed_downloadable' => env('STRIPE_TAX_CODE_STREAMED_DOWNLOADABLE', 'txcd_20060258'),
        'tax_code_tangible_goods' => env('STRIPE_TAX_CODE_TANGIBLE_GOODS', 'txcd_99999999'),
        /**
         * When course tax_classification is partial_taxable, split the gross charge (after processing-fee gross-up)
         * across two line items (digital instruction vs materials) for Stripe Tax. Replace with explicit
         * materials/course fee fields on the course when you want exact amounts from course managers.
         */
        'course_partial_materials_ratio' => (float) env('STRIPE_COURSE_PARTIAL_MATERIALS_RATIO', 0.35),
        'tax_code_shipping' => env('STRIPE_TAX_CODE_SHIPPING', 'txcd_92010001'),
        /**
         * When true, card/ACH checkout amounts are grossed up so estimated net after Stripe
         * processing fees matches the product/service total (customer pays the fee).
         * Donations keep their own "donor covers fees" toggle; Believe Points checkout already grosses up.
         */
        'customer_pays_processing_fee' => filter_var(env('STRIPE_CUSTOMER_PAYS_PROCESSING_FEE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'plaid' => [
        'client_id' => env('PLAID_CLIENT_ID'),
        'secret' => env('PLAID_SECRET'),
        'environment' => env('PLAID_ENVIRONMENT', 'sandbox'), // sandbox, development, production
        'webhook_url' => env('PLAID_WEBHOOK_URL'),
    ],

    'twilio' => [
        // SID / token / From come from admin Settings → Twilio (DB) via TwilioConfigService — not .env
        'sid' => null,
        'token' => null,
        'whatsapp_from' => null,
        'sms_from' => null,
        'sms_messaging_service_sid' => null,
        /**
         * Force account mode in errors/logs: trial, full, or auto (fetch from Twilio API).
         */
        'account_mode' => env('TWILIO_ACCOUNT_MODE', 'auto'),
        'verify_ssl' => filter_var(env('TWILIO_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'cafile' => env('TWILIO_CAFILE'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', env('FCM_PROJECT_ID', env('NEXT_PUBLIC_FIREBASE_PROJECT_ID', 'believe-in-unity-d8adc'))),
        'credentials' => env('FIREBASE_CREDENTIALS', env('FCM_CREDENTIALS', 'app/firebase/firebase-credentials.json')),
        'vapid_key' => env('FIREBASE_VAPID_KEY', env('FCM_VAPID_KEY', env('NEXT_PUBLIC_FIREBASE_VAPID_KEY'))),
        'api_key' => env('FIREBASE_API_KEY', env('NEXT_PUBLIC_FIREBASE_API_KEY', 'AIzaSyD2t6nAHQzhfWWso5nqV0mClX7R-Q6HjlA')),
        'auth_domain' => env('FIREBASE_AUTH_DOMAIN', env('NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN', 'believe-in-unity-d8adc.firebaseapp.com')),
        'storage_bucket' => env('FIREBASE_STORAGE_BUCKET', env('NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET', 'believe-in-unity-d8adc.firebasestorage.app')),
        'messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID', env('NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID', '829146180648')),
        'app_id' => env('FIREBASE_APP_ID', env('NEXT_PUBLIC_FIREBASE_APP_ID', '1:829146180648:web:8ba15be8d65c2c54d5991d')),
        /**
         * OAuth + FCM use Guzzle/cURL. On Windows, "SSL certificate problem: unable to get local issuer certificate"
         * is common until php.ini curl.cainfo is set — or set FIREBASE_CAFILE to https://curl.se/ca/cacert.pem path.
         * For local dev only you may set FIREBASE_VERIFY_SSL=false (never in production).
         */
        'verify_ssl' => filter_var(env('FIREBASE_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'cafile' => env('FIREBASE_CAFILE'),
        /**
         * Push alert jobs default to sync so delivery + admin logs do not depend on
         * `php artisan queue:work`. Set PUSH_QUEUE_CONNECTION=database on servers
         * that run a dedicated queue worker for push.
         */
        'queue_connection' => env('PUSH_QUEUE_CONNECTION', 'sync'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        // Set to false only on local/dev if you get "SSL certificate problem: unable to get local issuer certificate"
        'verify_ssl' => env('OPENAI_VERIFY_SSL', true),
        /** Default chat/completions model (AI Chat, campaigns, kiosk, Challenge Hub quiz, etc.). */
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
    ],

    /*
    | fal.ai — short-form video generation (BIU AI Media Studio). Used from queued jobs only.
    */
    'fal' => [
        'api_key' => env('FAL_API_KEY'),
        /** Full queue path, e.g. fal-ai/minimax/video-01 (see model API page on fal.ai). */
        'default_model' => env('FAL_VIDEO_MODEL', ''),
        'verify_ssl' => filter_var(env('FAL_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        /** Merged into fal queue POST body after `prompt` (model-specific keys). JSON object string in .env. */
        'video_input_extras' => env('FAL_VIDEO_INPUT_EXTRAS'),
    ],

    /*
    | BIU AI Media Studio — OpenAI script + fal.ai video (queue worker).
    */
    'ai_media_studio' => [
        'openai_model' => env('AI_MEDIA_STUDIO_OPENAI_MODEL', 'gpt-4o-mini'),
        /** Text-to-video length (seconds): fixed 5–10 everywhere (create form, validation, fal, OpenAI). */
        'video_duration_min' => 5,
        'video_duration_max' => 10,
        /**
         * Comma-separated tiers offered in the UI (intersected with 480p,720p,1080p).
         * Example: AI_MEDIA_STUDIO_RESOLUTIONS=720p,1080p
         */
        'video_resolution_tiers' => array_values(array_filter(array_map(static function (string $s): string {
            return strtolower(trim($s));
        }, explode(',', (string) env('AI_MEDIA_STUDIO_RESOLUTIONS', '480p,720p,1080p'))))),
        /**
         * fal queue: send tier string (e.g. 480p) under this key. Set empty to skip (e.g. width/height only models).
         */
        'fal_resolution_param' => env('AI_MEDIA_STUDIO_FAL_RESOLUTION_PARAM', 'resolution'),
        /** Sent for resolution key: "tier" (480p) or "pixels" (720x1280). */
        'fal_resolution_value_format' => strtolower(trim((string) env('AI_MEDIA_STUDIO_FAL_RESOLUTION_VALUE_FORMAT', 'tier'))),
        /**
         * When value format is "tier": how to spell the tier in JSON.
         * "lowercase" (480p) — WAN 2.5 / fal-ai/wan-25-preview (enum: 480p, 720p, 1080p).
         * "suffix_upper" (480P) — only if your model docs require uppercase P (some MiniMax paths).
         */
        'fal_resolution_tier_output' => strtolower(trim((string) env('AI_MEDIA_STUDIO_FAL_RESOLUTION_TIER_OUTPUT', 'lowercase'))),
        /** Merge resolution tier (480p/720p/1080p) into fal JSON when the param name is non-empty. */
        'fal_send_resolution_tier' => filter_var(env('AI_MEDIA_STUDIO_FAL_SEND_RESOLUTION_TIER', true), FILTER_VALIDATE_BOOLEAN),
        /** Merge explicit width / height (from orientation + tier) into fal JSON. */
        'fal_send_dimensions' => filter_var(env('AI_MEDIA_STUDIO_FAL_SEND_DIMENSIONS', true), FILTER_VALIDATE_BOOLEAN),
        /** Merge aspect ratio (9:16 or 16:9) — many fal video models expect this with resolution. */
        'fal_send_aspect_ratio' => filter_var(env('AI_MEDIA_STUDIO_FAL_SEND_ASPECT_RATIO', true), FILTER_VALIDATE_BOOLEAN),
        'fal_aspect_ratio_param' => env('AI_MEDIA_STUDIO_FAL_ASPECT_RATIO_PARAM', 'aspect_ratio'),
        /** Merged into fal queue body after `prompt`. Set empty to omit (model-specific). */
        'fal_duration_param' => env('AI_MEDIA_STUDIO_FAL_DURATION_PARAM', 'duration'),
        'fal_duration_as_string' => filter_var(env('AI_MEDIA_STUDIO_FAL_DURATION_AS_STRING', false), FILTER_VALIDATE_BOOLEAN),
        'fal_poll_interval_seconds' => max(1, (int) env('AI_MEDIA_STUDIO_FAL_POLL_INTERVAL', 3)),
        'fal_max_wait_seconds' => max(30, (int) env('AI_MEDIA_STUDIO_FAL_MAX_WAIT', 900)),
        /** Subfolder under the account’s Dropbox recordings folder for AI Studio MP4s. */
        'dropbox_subfolder' => env('AI_MEDIA_STUDIO_DROPBOX_SUBFOLDER', 'AI Video Studio'),
        /** `memory_limit` while ProcessAiVideoGenerationJob runs (OpenAI + fal + Dropbox). */
        'queue_worker_memory_limit' => env('AI_MEDIA_STUDIO_QUEUE_MEMORY', '1024M'),
        /** Burn Believe In Unity logo (top-right) into finished MP4s via FFmpeg. */
        'watermark_enabled' => filter_var(env('AI_MEDIA_STUDIO_WATERMARK', true), FILTER_VALIDATE_BOOLEAN),
        /** Absolute path to ffmpeg binary; falls back to storage/app/bin/ffmpeg then system PATH. */
        'ffmpeg_path' => env('AI_MEDIA_STUDIO_FFMPEG'),
        'watermark_logo_path' => env('AI_MEDIA_STUDIO_WATERMARK_LOGO'),
        'watermark_margin_px' => max(8, (int) env('AI_MEDIA_STUDIO_WATERMARK_MARGIN', 24)),
        'watermark_width_fraction' => (float) env('AI_MEDIA_STUDIO_WATERMARK_WIDTH_FRACTION', 0.14),
        'watermark_x264_preset' => env('AI_MEDIA_STUDIO_WATERMARK_X264_PRESET', 'fast'),
        'watermark_crf' => max(18, min(28, (int) env('AI_MEDIA_STUDIO_WATERMARK_CRF', 23))),
        'watermark_timeout_seconds' => max(60, (int) env('AI_MEDIA_STUDIO_WATERMARK_TIMEOUT', 600)),
        /**
         * Optional override for retail credit prices (1 credit = US$1.00). Keys: tier => seconds => USD.
         * Omitted keys fall back to BIU suggested retail defaults.
         *
         * @var array<string, array<int, float>>
         */
        'retail_credit_prices_usd' => [],
        /**
         * Credits added to AI Media Studio balance on every pricing-plan subscribe (checkout success) and on each
         * subscription renewal (Stripe `invoice.payment_succeeded`, billing_reason `subscription_cycle`).
         * Plan custom field `ai_media_studio_credits` can grant more than this minimum. Env: PLAN_SUBSCRIPTION_AI_MEDIA_STUDIO_CREDITS
         */
        'plan_subscription_ai_media_studio_credits' => max(0, (int) env('PLAN_SUBSCRIPTION_AI_MEDIA_STUDIO_CREDITS', 5)),
        /**
         * Supporter Stripe packs: id => [usd, credits]. Parity: 1 credit = US$1.00 on the AI Media Studio balance
         * (video generation then debits fractional credits by resolution × length).
         */
        'supporter_packs' => [
            'media_studio_5' => ['usd' => (float) env('AI_MEDIA_PACK_5_USD', 5), 'credits' => (int) env('AI_MEDIA_PACK_5_CREDITS', 5)],
            'media_studio_10' => ['usd' => (float) env('AI_MEDIA_PACK_10_USD', 10), 'credits' => (int) env('AI_MEDIA_PACK_10_CREDITS', 10)],
        ],
    ],

    /*
    | One-time welcome allowance when an organization first subscribes to a pricing plan.
    | Defaults: $5 AI pack (25,000 tokens) + $1 email (1,000 emails). Not granted on renewals.
    */
    'plan_subscription' => [
        'first_month_ai_tokens' => max(0, (int) env('PLAN_FIRST_MONTH_AI_TOKENS', 25_000)),
        'first_month_emails' => max(0, (int) env('PLAN_FIRST_MONTH_EMAILS', 1_000)),
        /** Unity Membership intro rate duration before standard monthly price (Stripe schedule). */
        'intro_period_months' => max(1, (int) env('PLAN_INTRO_PERIOD_MONTHS', 12)),
    ],

    /*
    | Newsletter / template AI HTML generation (OpenAI JSON mode).
    | Default model is gpt-4o-mini (good layout quality vs cost). Override with NEWSLETTER_AI_MODEL in .env.
    | Keep max_output_tokens <= 4096 unless your model supports higher (otherwise OpenAI returns HTTP 400).
    */
    'newsletter_ai' => [
        'model' => env('NEWSLETTER_AI_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('NEWSLETTER_AI_TEMPERATURE', 0.74),
        'max_output_tokens' => (int) env('NEWSLETTER_AI_MAX_TOKENS', 4096),
    ],

    /*
    | AI ingest of local kiosk providers when supporters save city/state on profile.
    */
    'kiosk_provider_ingest' => [
        'enabled' => env('KIOSK_PROVIDER_INGEST_ENABLED', true),
        'cache_ttl_days' => (int) env('KIOSK_GEO_CACHE_TTL_DAYS', 30),
        'model' => env('KIOSK_PROVIDER_INGEST_MODEL', 'gpt-4o-mini'),
        /** Max completion tokens (raise if JSON is truncated; model caps still apply). */
        'max_output_tokens' => (int) env('KIOSK_PROVIDER_INGEST_MAX_TOKENS', 4096),
        /** When true, `kiosk:refresh-provider-ingest` runs from the scheduler (monthly). */
        'monthly_refresh_enabled' => env('KIOSK_PROVIDER_MONTHLY_REFRESH_ENABLED', true),
    ],

    /*
    | Level Up / Challenge Hub quiz (tuning lives here — no long list of CHALLENGE_QUIZ_* in .env).
    | Needs OPENAI_API_KEY in .env for stem refiner and full OpenAI question batches.
    | Billing: see `laravel.log` (prompt_tokens, completion_tokens, total_tokens on OpenAI lines).
    */
    'challenge_quiz' => [
        'scripture_mcq_enabled' => true,
        'scripture_same_book_distractors' => true,
        /**
         * When true: refill uses OpenAI with GROUNDING from this DB (scripture + admin passages) first — full
         * professional questions with natural language options, like the old "direct OpenAI" path. Scripture "reference" MCQ is only a fallback. Needs OPENAI_API_KEY.
         */
        'prefer_openai_grounded_refill' => true,
        /** If prefer_openai_grounded_refill is false: script runs first, then this can run OpenAI after (legacy). */
        'openai_after_scripture' => false,
        /** Batched ref-only stem polish (rarely needed if prefer_openai_grounded_refill is on). */
        'scripture_stem_refiner_enabled' => false,
        'scripture_stem_refiner_model' => env('CHALLENGE_QUIZ_STEM_REFINER_MODEL', 'gpt-4o-mini'),
        'scripture_stem_refiner_max_output_tokens' => 2200,
        'scripture_stem_refiner_excerpt_max_chars' => 320,
        'model' => env('CHALLENGE_QUIZ_MODEL', 'gpt-4o-mini'),
        'max_output_tokens' => 4096,
        'points_per_correct' => 10.0,
        'points_per_incorrect' => 10.0,
        'max_openai_batches_per_user_category_per_day' => 20,
        'openai_batch_size' => 8,
        'grounding' => [
            'enabled' => true,
            'passage_limit' => 10,
            'max_chars_per_passage' => 900,
            'max_total_chars' => 10_000,
        ],
    ],

    /*
    | Challenge Hub: admin-generated cover art (OpenAI Images API, stored on public disk).
    | Default dall-e-2 + 512x512 keeps cost low; resized to image_max_width for smaller files.
    */
    'challenge_hub' => [
        'image_model' => env('CHALLENGE_HUB_IMAGE_MODEL', 'dall-e-2'),
        'image_size' => env('CHALLENGE_HUB_IMAGE_SIZE', '512x512'),
        'image_max_width' => (int) env('CHALLENGE_HUB_IMAGE_MAX_WIDTH', 384),
        'image_prompt_suffix' => env('CHALLENGE_HUB_IMAGE_PROMPT_SUFFIX', 'Isolated subject, centered, simple illustration, generous empty space around the subject, no text, no border, crisp edges, dark purple / gold palette, suitable for overlay on a dark UI (not a full scene).'),
    ],

    /**
     * `scripture:import-remote` HTTP client. Honoured only when APP_ENV is not production; production always verifies TLS.
     * Local Windows: set curl.cainfo, or SCRIPTURE_IMPORT_CAFILE, or SCRIPTURE_IMPORT_VERIFY_SSL=false (omit on server).
     */
    'scripture_import' => [
        'verify_ssl' => filter_var(env('SCRIPTURE_IMPORT_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'cafile' => env('SCRIPTURE_IMPORT_CAFILE'),
    ],

    'gmail' => [
        'client_id' => env('GMAIL_CLIENT_ID'),
        'client_secret' => env('GMAIL_CLIENT_SECRET'),
        'redirect_uri' => env('GMAIL_REDIRECT_URI', env('APP_URL').'/email-invite/callback'),
    ],

    'outlook' => [
        'client_id' => env('OUTLOOK_CLIENT_ID'),
        'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
        'redirect_uri' => env('OUTLOOK_REDIRECT_URI', env('APP_URL').'/email-invite/callback'),
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'redirect_uri' => env('FACEBOOK_REDIRECT_URI', env('APP_URL').'/facebook/callback'),
        /**
         * Optional Facebook Login for Business configuration ID from Meta App Dashboard.
         * When set, OAuth dialog includes config_id (recommended for Pages / tech-provider apps).
         * Does not replace platform authentication.
         */
        'config_id' => env('FACEBOOK_CONFIG_ID'),
        /** @see config/facebook.php scopes and api_version for App Review */
        'default_graph_version' => env('FACEBOOK_API_VERSION', 'v21.0'),
    ],

    'bridge' => [
        'api_key' => env('BRIDGE_API_KEY'),
        'environment' => env('BRIDGE_ENVIRONMENT', 'production'), // sandbox or production
        'base_url' => env('BRIDGE_BASE_URL'), // Will be set in BridgeService based on environment
        'webhook_secret' => env('BRIDGE_WEBHOOK_SECRET'),
        'redirect_uri' => env('BRIDGE_REDIRECT_URI'), // Public URL for TOS callback (e.g., ngrok for local dev)
        // Local Windows dev: set false if cURL error 60 (missing CA bundle). Never disable in production.
        'verify_ssl' => filter_var(env('BRIDGE_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
    ],

    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY', env('VITE_YOUTUBE_API_KEY')),
        'client_id' => env('YOUTUBE_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
        'redirect_uri' => env('YOUTUBE_REDIRECT_URI', env('APP_URL').'/integrations/youtube/callback'),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY', env('TURNSTILE_SECRET')),
    ],

    'phaze' => [
        'api_key' => env('PHAZE_API_KEY'),
        'api_secret' => env('PHAZE_API_SECRET'),
        'base_url' => env('PHAZE_BASE_URL', 'https://api.phaze.io'),
        'environment' => env('PHAZE_ENVIRONMENT', 'sandbox'), // sandbox or production
        // BIU share of Phaze provider commission (not added to gift card face price). Legacy env: GIFT_CARD_PLATFORM_COMMISSION_PERCENTAGE.
        'gift_card_biu_revenue_share_percentage' => env('GIFT_CARD_BIU_REVENUE_SHARE_PERCENTAGE', env('GIFT_CARD_PLATFORM_COMMISSION_PERCENTAGE', 10)),
        'gift_card_platform_commission_percentage' => env('GIFT_CARD_PLATFORM_COMMISSION_PERCENTAGE', env('GIFT_CARD_BIU_REVENUE_SHARE_PERCENTAGE', 10)),
        // Note: webhook_api_key is now stored in database (phaze_webhooks table)
        // No need to set PHAZE_WEBHOOK_API_KEY in .env anymore
    ],

    'gift_cards' => [
        /** Hours to wait before Phaze fulfillment for Believe Points redemptions. */
        'fulfillment_delay_hours' => (int) env('GIFT_CARD_FULFILLMENT_DELAY_HOURS', 72),
    ],

    'mediamtx' => [
        // Public URL where users open the browser publish page (e.g. https://stream.yourapp.com). Required for "Go Live from browser" (no OBS).
        'publish_url' => env('MEDIAMTX_PUBLISH_URL', ''),
        // Internal RTMP URL for FFmpeg to pull from (e.g. rtmp://127.0.0.1:1935). Same server as MediaMTX.
        'rtmp_internal' => env('MEDIAMTX_RTMP_INTERNAL', 'rtmp://127.0.0.1:1935'),
        // Public RTMP base reachable from AWS workers (e.g. rtmp://stream.yourapp.com:1935).
        'rtmp_public' => env('MEDIAMTX_RTMP_PUBLIC', ''),
    ],

    'dropbox' => [
        'client_id' => env('DROPBOX_CLIENT_ID'),
        'client_secret' => env('DROPBOX_CLIENT_SECRET'),
        'redirect_uri' => env('DROPBOX_REDIRECT_URI', env('APP_URL').'/integrations/dropbox/callback'),
        'access_token' => env('DROPBOX_ACCESS_TOKEN'), // optional: app-level token for legacy use
        // SSL: set DROPBOX_SSL_VERIFY=false on Windows/local if you get "cURL error 60: unable to get local issuer certificate"
        'verify' => filter_var(env('DROPBOX_SSL_VERIFY', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | Optional: nonprofit org pool listings (organization adopts merchant SKUs). Hub checkout no longer requires this.
    */
    'marketplace' => [
        'pool_listing_organization_id' => env('MARKETPLACE_POOL_LISTING_ORGANIZATION_ID'),
    ],

    'digital_products' => [
        'max_upload_kb' => (int) env('DIGITAL_PRODUCT_MAX_UPLOAD_KB', 51200),
    ],

    'shippo' => [
        'api_key' => env('SHIPPO_API_KEY'),
        'api_base' => env('SHIPPO_API_BASE', 'https://api.goshippo.com'),
        // When true, Shippo sets extra.bypass_address_validation on shipments (USPS/UPS/LaserShip) so
        // real customer addresses that fail strict CASS "Address not found" can still get rates/labels.
        'bypass_address_validation' => filter_var(env('SHIPPO_BYPASS_ADDRESS_VALIDATION', 'true'), FILTER_VALIDATE_BOOLEAN),
        // USPS requires non-empty seller (ship-from) email AND phone. Used when org/user data is incomplete.
        'fallback_seller_email' => env('SHIPPO_FALLBACK_SELLER_EMAIL', env('MAIL_FROM_ADDRESS')),
        // Last-resort US digits only if nothing else is set (override in production via SHIPPO_FALLBACK_SELLER_PHONE).
        'fallback_seller_phone' => env('SHIPPO_FALLBACK_SELLER_PHONE', '5555555555'),
        // Webhook URL: {APP_URL}/api/webhooks/shippo — optional security (Shippo docs: HMAC or ?token=)
        'webhook_hmac_secret' => env('SHIPPO_WEBHOOK_HMAC_SECRET'),
        'webhook_query_token' => env('SHIPPO_WEBHOOK_TOKEN'),
    ],

    'irs' => [
        'download_timeout' => (int) env('IRS_DOWNLOAD_TIMEOUT', 900), // seconds per ZIP (15 min default)
        'download_retries' => (int) env('IRS_DOWNLOAD_RETRIES', 3),
        // Job timeout: large ZIPs may need 2–4 hours. Set IRS_JOB_TIMEOUT=14400 for 4 hours.
        'job_timeout' => (int) env('IRS_JOB_TIMEOUT', 7200), // seconds per ProcessIrsZipJob (2 hours default)
        // ProcessIrsBmfSource can run up to 3h per CSV; mark stuck uploads failed after this many hours.
        'bmf_stale_import_hours' => (int) env('IRS_BMF_STALE_IMPORT_HOURS', 6),
        // SSL: set IRS_SSL_VERIFY=false on Windows/local if you get "cURL error 60: unable to get local issuer certificate"
        'ssl_verify' => env('IRS_SSL_VERIFY', true),
        'cafile' => env('IRS_CAFILE', null), // optional: path to cacert.pem (e.g. from https://curl.se/ca/cacert.pem)
    ],

    'betterstack' => [
        'uptime_api_token' => env('BETTER_STACK_UPTIME_API_TOKEN'),
        'alert_email' => env('BETTER_STACK_ALERT_EMAIL'),
        'source_token' => env('BETTER_STACK_SOURCE_TOKEN'),
        'source_endpoint' => env('BETTER_STACK_SOURCE_ENDPOINT'),
        'log_level' => env('BETTER_STACK_LOG_LEVEL', 'warning'),
    ],

];

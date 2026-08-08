<?php

/**
 * Facebook Page integration (App Review + runtime).
 *
 * Scopes must match what you submit in Meta App Review. Do not request permissions
 * that are not used in the UI flows below /facebook/connect and /facebook/posts.
 *
 * Platform auth is separate — Facebook OAuth here only connects Pages for logged-in users.
 */
return [
    'api_version' => env('FACEBOOK_API_VERSION', env('FACEBOOK_DEFAULT_GRAPH_VERSION', 'v21.0')),

    /**
     * Permissions requested during OAuth (comma-separated in dialog).
     * public_profile is included automatically by Facebook Login; listed for in-app disclosure.
     */
    // App Review scopes: list Pages, publish/schedule posts created in BIU.
    'scopes' => [
        'pages_show_list',
        'pages_manage_posts',
    ],

    'oauth' => [
        'session_key' => 'facebook_oauth_pending',
        'state_session_key' => 'facebook_oauth_state',
        'session_ttl_minutes' => 30,
        /**
         * TEMPORARY: verbose OAuth/Page-connection logging (tokens, /me/accounts, scopes).
         * Set FACEBOOK_OAUTH_DEBUG=false after Meta Testing / App Review debugging.
         */
        'debug' => filter_var(env('FACEBOOK_OAUTH_DEBUG', true), FILTER_VALIDATE_BOOL),
        'debug_channel' => env('FACEBOOK_OAUTH_DEBUG_CHANNEL', 'stack'),
        /**
         * When FACEBOOK_CONFIG_ID is set (Login for Business), Meta recommends omitting scope.
         * Keep true so scope-based dashboards still work if config_id is incomplete.
         */
        'include_scopes_with_config_id' => filter_var(
            env('FACEBOOK_INCLUDE_SCOPES_WITH_CONFIG_ID', true),
            FILTER_VALIDATE_BOOL
        ),
    ],

    /** Meta Developer → Basic → User data deletion → Data deletion instructions URL */
    'data_deletion_url' => env('FACEBOOK_DATA_DELETION_URL', env('APP_URL', 'http://127.0.0.1:8000').'/data-deletion'),
];

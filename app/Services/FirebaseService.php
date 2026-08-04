<?php

namespace App\Services;

use App\Enums\PushNotificationModule;
use App\Services\PushNotifications\OrganizationLogoResolver;
use App\Models\User;
use App\Models\UserPushToken;
use App\Models\PushNotificationLog;
use App\Services\PushNotificationLogger;
use App\Support\PushNotificationLogMetadata;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Auth\Credentials\ServiceAccountCredentials as GoogleServiceAccountCredentials;
use GuzzleHttp\Client;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseService
{
    private $projectId;
    private $credentialsPath;
    private $accessToken;

    public function __construct()
    {
        $this->projectId = config('services.firebase.project_id');
        $this->credentialsPath = storage_path(config('services.firebase.credentials'));
    }

    /**
     * Resolve CA bundle path (relative .env paths break under Herd/php-fpm when cwd is not project root).
     */
    private function resolveTlsCafile(): ?string
    {
        $configured = config('services.firebase.cafile');
        $candidates = array_filter([
            is_string($configured) && $configured !== '' ? $configured : null,
            storage_path('app/cacert.pem'),
        ]);

        foreach ($candidates as $path) {
            $normalized = str_replace('\\', '/', $path);
            if (is_file($path)) {
                return $path;
            }
            if (str_starts_with($normalized, 'storage/')) {
                $fromStorage = storage_path(substr($normalized, strlen('storage/')));
                if (is_file($fromStorage)) {
                    return $fromStorage;
                }
            }
            $fromBase = base_path($path);
            if (is_file($fromBase)) {
                return $fromBase;
            }
        }

        return null;
    }

    /**
     * Guzzle TLS options for Google OAuth + FCM (fixes Windows cURL error 60 when CA bundle is missing).
     *
     * @return array<string, mixed>
     */
    private function firebaseTlsRequestOptions(): array
    {
        $verifySsl = filter_var(config('services.firebase.verify_ssl', true), FILTER_VALIDATE_BOOL);

        if (! $verifySsl) {
            return ['verify' => false];
        }

        $cafile = $this->resolveTlsCafile();
        if ($cafile !== null) {
            return ['verify' => $cafile];
        }

        return [];
    }

    /**
     * Optional HTTP handler so ServiceAccountCredentials::fetchAuthToken uses the same TLS settings as FCM requests.
     */
    private function googleAuthHttpHandler(): ?callable
    {
        $opts = $this->firebaseTlsRequestOptions();
        if ($opts === []) {
            return null;
        }

        return HttpHandlerFactory::build(new Client($opts));
    }

    /**
     * Laravel HTTP client for FCM / IID with matching TLS settings.
     */
    private function httpForFirebase(): PendingRequest
    {
        $req = Http::timeout(30);
        $opts = $this->firebaseTlsRequestOptions();
        if ($opts !== []) {
            $req = $req->withOptions($opts);
        }

        return $req;
    }

    /**
     * Get Firebase access token
     */
    private function getAccessToken()
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        try {
            if (! is_string($this->credentialsPath) || $this->credentialsPath === '' || ! is_file($this->credentialsPath)) {
                Log::error('Firebase credentials file missing', [
                    'credentials_path' => $this->credentialsPath,
                    'hint' => 'Place the Firebase service account JSON at storage/'.ltrim((string) config('services.firebase.credentials'), '/').' (or update FIREBASE_CREDENTIALS in .env).',
                ]);

                return null;
            }

            $decoded = json_decode((string) file_get_contents($this->credentialsPath), true);
            if (! is_array($decoded) || empty($decoded['private_key']) || empty($decoded['client_email'])) {
                Log::error('Firebase credentials JSON is invalid or incomplete', [
                    'credentials_path' => $this->credentialsPath,
                ]);

                return null;
            }

            $credentials = new GoogleServiceAccountCredentials(
                'https://www.googleapis.com/auth/cloud-platform',
                $decoded
            );

            $handler = $this->googleAuthHttpHandler();
            $tokenData = $credentials->fetchAuthToken($handler);
            $this->accessToken = $tokenData['access_token'] ?? null;

            if (! $this->accessToken) {
                Log::error('Firebase OAuth returned no access_token', [
                    'token_keys' => is_array($tokenData) ? array_keys($tokenData) : [],
                ]);
            }

            return $this->accessToken;
        } catch (\Exception $e) {
            Log::error('Firebase token error: '.$e->getMessage(), [
                'credentials_path' => $this->credentialsPath,
            ]);

            return null;
        }
    }

    /**
     * FCM requires every data payload value to be a string.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function stringifyFcmData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return $out;
    }

    /**
     * Send push notification to a single device
     */
    public function sendToDevice($deviceToken, $title, $body, $data = [], $deviceType = 'web')
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            Log::error('Failed to get Firebase access token');
            return ['success' => false, 'error_code' => 'NO_ACCESS_TOKEN', 'response' => null];
        }

        // Extract content_item_id from data or use default
        $contentItemId = $data['content_item_id'] ?? null;
        $clickAction = $data['click_action'] ?? null;
        $isIncomingCall = ($data['type'] ?? '') === 'incoming_call';
        $ringUrl = $data['ring_url'] ?? null;

        // Build the notification URL
        $notificationUrl = $clickAction
            ?? ($isIncomingCall && is_string($ringUrl) && $ringUrl !== '' ? $ringUrl : null)
            ?? url('/');

        $fcmData = $this->stringifyFcmData(array_merge($data, [
            'title' => $title,
            'body' => $body,
            'click_action' => $notificationUrl,
            'url' => $notificationUrl,
        ]));

        $message = [
            'message' => [
                'token' => $deviceToken,
                'data' => $fcmData,
            ],
        ];

        $notificationIcon = url('/favicon-96x96.png');
        $orgLogoUrl = trim((string) ($fcmData['organization_logo_url'] ?? ''));

        if ($deviceType === 'web') {
            $webpushNotification = [
                'title' => $title,
                'body' => $body,
                'icon' => $notificationIcon,
            ];

            if ($orgLogoUrl !== '' && ($fcmData['type'] ?? '') !== 'incoming_call'
                && ! app(OrganizationLogoResolver::class)->isSystemAutomaticNotification(null, $fcmData)) {
                // `icon` = right-side dynamic icon on Android; `image` would show as a large hero at the bottom.
                $webpushNotification['icon'] = $orgLogoUrl;
            }

            $message['message']['webpush'] = [
                'notification' => $webpushNotification,
                'fcm_options' => [
                    'link' => $notificationUrl,
                ],
                'headers' => [
                    'TTL' => $isIncomingCall ? '120' : '86400',
                    'Urgency' => $isIncomingCall ? 'high' : 'normal',
                ],
            ];

            if ($isIncomingCall) {
                $message['message']['android'] = [
                    'priority' => 'HIGH',
                ];
            }
        } else {
            $message['message']['notification'] = [
                'title' => $title,
                'body' => $body,
            ];
            $message['message']['android'] = [
                'priority' => 'HIGH',
                'notification' => [
                    'click_action' => $notificationUrl,
                    'sound' => 'default',
                    'channel_id' => 'default',
                ],
            ];
            $message['message']['apns'] = [
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'alert' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'sound' => 'default',
                    ],
                    'click_action' => $notificationUrl,
                ],
            ];
        }

        try {
            $response = $this->httpForFirebase()
                ->withToken($accessToken)
                ->post(
                    "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                    $message
                );

            if ($response->successful()) {
                Log::info('Push notification sent successfully', [
                    'device_token' => substr($deviceToken, 0, 20) . '...',
                    'title' => $title,
                    'content_item_id' => $contentItemId,
                    'url' => $notificationUrl,
                ]);
                return ['success' => true, 'error_code' => null, 'response' => null];
            }

            $body = $response->json();
            $errorCode = $body['error']['details'][0]['errorCode'] ?? $body['error']['message'] ?? (string) $response->status();
            Log::error('Firebase send error', [
                'status' => $response->status(),
                'response' => $body,
                'device_token' => substr($deviceToken, 0, 20) . '...',
            ]);
            return ['success' => false, 'error_code' => $errorCode, 'response' => $body];
        } catch (\Exception $e) {
            Log::error('Firebase exception: ' . $e->getMessage(), [
                'device_token' => substr($deviceToken, 0, 20) . '...',
            ]);
            return ['success' => false, 'error_code' => 'EXCEPTION', 'response' => ['message' => $e->getMessage()]];
        }
    }

    /**
     * Send to a user via centralized push notification logging.
     * No notification is sent without a log record.
     *
     * @param  int  $userId
     * @param  string  $title
     * @param  string  $body
     * @param  array  $data  Optional keys: content_item_id, click_action, source_type, source_id, module_name, organization_id, module_record_id, deep_link, audience_type, created_by, sender_id
     * @return array<string, array{success: bool, error_code: ?string, response: ?array}>
     */
    public function sendToUser($userId, $title, $body, $data = [])
    {
        $logger = app(PushNotificationLogger::class);

        [$moduleName, $moduleRecordId, $deepLink, $createdBy] = $this->resolveLogContext($data, $title, $body);

        $organizationId = $this->resolveOrganizationId($data, $createdBy);

        unset(
            $data['module_name'],
            $data['organization_id'],
            $data['module_record_id'],
            $data['deep_link'],
            $data['source_type'],
            $data['source_id'],
            $data['created_by'],
            $data['sender_id'],
        );

        $log = $logger->dispatch(
            [
                'organization_id' => $organizationId,
                'user_id' => (int) $userId,
                'module_name' => $moduleName,
                'module_record_id' => $moduleRecordId,
                'notification_title' => $title,
                'notification_body' => $body,
                'audience_type' => $data['audience_type'] ?? 'user',
                'deep_link' => $deepLink,
                'created_by' => $createdBy,
            ],
            [(int) $userId],
            $data,
        );

        $results = [];
        foreach ($log->recipients as $recipient) {
            if (! $recipient->device_token) {
                continue;
            }
            $success = in_array(
                $recipient->status->value ?? (string) $recipient->status,
                ['delivered', 'sent', 'opened'],
                true,
            );
            $results[$recipient->device_token] = [
                'success' => $success,
                'error_code' => $success ? null : 'DELIVERY_FAILED',
                'response' => $success ? null : ['message' => $recipient->failure_reason],
            ];
        }

        if ($results === []) {
            Log::warning("No active tokens found for user: {$userId}");
        }

        $successCount = count(array_filter($results, fn ($r) => $r['success']));
        Log::info('Notification sent to user', [
            'user_id' => $userId,
            'log_id' => $log->id,
            'total_tokens' => count($results),
            'success_count' => $successCount,
        ]);

        if ($successCount === 0 && $results !== []) {
            app(DeviceTokenService::class)->syncLegacyPushToken($userId);
        }

        return $results;
    }

    /**
     * Send push notifications to multiple users under a single log record.
     *
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): PushNotificationLog
    {
        $logger = app(PushNotificationLogger::class);

        [$moduleName, $moduleRecordId, $deepLink, $createdBy] = $this->resolveLogContext($data, $title, $body);

        $organizationId = $this->resolveOrganizationId($data, $createdBy);

        unset(
            $data['module_name'],
            $data['organization_id'],
            $data['module_record_id'],
            $data['deep_link'],
            $data['source_type'],
            $data['source_id'],
            $data['created_by'],
            $data['sender_id'],
        );

        return $logger->dispatch(
            [
                'organization_id' => $organizationId,
                'module_name' => $moduleName,
                'module_record_id' => $moduleRecordId,
                'notification_title' => $title,
                'notification_body' => $body,
                'audience_type' => $data['audience_type'] ?? 'users',
                'deep_link' => $deepLink,
                'created_by' => $createdBy,
            ],
            $userIds,
            $data,
        );
    }

    private function resolveModuleFromSourceType(?string $sourceType): string
    {
        return match ($sourceType) {
            'event' => 'events',
            'course' => 'courses',
            'campaign' => 'campaigns',
            'donation' => 'donations',
            'chat', 'chat_message' => 'chat',
            'unity_meet', 'unity_meet_invitation' => 'unity_meet',
            'unity_call', 'incoming_call' => 'unity_call',
            'unity_live', 'livestream' => 'unity_live',
            'job_post' => 'volunteer',
            'newsletter', 'email' => 'email',
            'marketplace', 'order' => 'marketplace',
            'proximity' => 'proximity',
            'daily_engagement' => 'daily_engagement',
            'social_feed', 'post', 'group' => 'social_feed',
            'membership' => 'membership',
            'wallet', 'believe_points', 'gift' => 'wallet_rewards',
            'announcement', 'announcements' => 'announcements',
            'discussion', 'discussions' => 'discussions',
            'admin_test' => 'system',
            default => 'system',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int|null, 2: string|null, 3: int|null}
     */
    private function resolveLogContext(array $data, string $title, string $body): array
    {
        $moduleName = $data['module_name'] ?? $this->resolveModuleFromSourceType($data['source_type'] ?? null);
        $moduleRecordId = isset($data['module_record_id'])
            ? (int) $data['module_record_id']
            : (isset($data['source_id']) ? (int) $data['source_id'] : null);

        $deepLink = $data['deep_link'] ?? null;
        if (! $deepLink && ! empty($data['click_action'])) {
            $deepLink = parse_url($data['click_action'], PHP_URL_PATH) ?: $data['click_action'];
        }

        if ($moduleName === PushNotificationModule::System->value
            && PushNotificationLogMetadata::looksLikeChat($title, $body, $deepLink)) {
            $moduleName = PushNotificationModule::Chat->value;
        }

        $createdBy = $this->resolveCreatedBy($data);

        if (! $createdBy && $moduleName === PushNotificationModule::Chat->value) {
            $senderName = PushNotificationLogMetadata::parseSenderNameFromBody($body, $title);
            if ($senderName) {
                $createdBy = User::query()->where('name', $senderName)->value('id');
                $createdBy = $createdBy ? (int) $createdBy : null;
            }
        }

        return [$moduleName, $moduleRecordId, $deepLink, $createdBy];
    }

    /**
     * Resolve organization for a push notification: explicit organization_id, else the sender's org
     * (owner or board member — e.g. Kenneth Matthews sending on behalf of STUTTIE LEARNING INC).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveOrganizationId(array $data, ?int $createdBy = null): ?int
    {
        return app(OrganizationLogoResolver::class)->resolveOrganizationIdFromPayload($data, $createdBy);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCreatedBy(array $data): ?int
    {
        if (isset($data['created_by']) && (int) $data['created_by'] > 0) {
            return (int) $data['created_by'];
        }

        if (isset($data['sender_id']) && (int) $data['sender_id'] > 0) {
            return (int) $data['sender_id'];
        }

        $authId = auth()->id();

        return $authId ? (int) $authId : null;
    }

    /**
     * Send push notification to multiple devices. Each result is ['success' => bool, 'error_code' => ?string, 'response' => ?array].
     */
    public function sendToMultipleDevices($deviceTokens, $title, $body, $data = [])
    {
        $results = [];
        foreach ($deviceTokens as $token) {
            $results[$token] = $this->sendToDevice($token, $title, $body, $data);
        }

        $successCount = count(array_filter($results, fn ($r) => $r['success']));
        Log::info('Bulk notification sent', [
            'total_tokens' => count($deviceTokens),
            'success_count' => $successCount,
            'title' => $title,
        ]);

        return $results;
    }

    /**
     * Send notification to all users in a topic
     */
    public function sendToTopic($topic, $title, $body, $data = [])
    {
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            Log::error('Failed to get Firebase access token');
            return false;
        }

        $contentItemId = $data['content_item_id'] ?? null;
        $notificationUrl = $contentItemId
            ? route('notifications.content.show', ['content_item' => $contentItemId])
            : url('/');

        $message = [
            'message' => [
                'topic' => $topic,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $this->stringifyFcmData(array_merge($data, [
                    'click_action' => $notificationUrl,
                    'url' => $notificationUrl,
                ])),
                'webpush' => [
                    'fcm_options' => [
                        'link' => $notificationUrl,
                    ],
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'icon' => url('/icon.png'),
                        'badge' => url('/badge.png'),
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->httpForFirebase()
                ->withToken($accessToken)
                ->post(
                    "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send",
                    $message
                );

            $success = $response->successful();

            if ($success) {
                Log::info('Topic notification sent successfully', [
                    'topic' => $topic,
                    'title' => $title,
                    'content_item_id' => $contentItemId,
                ]);
            } else {
                Log::error('Firebase topic send error', [
                    'topic' => $topic,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Firebase topic send exception: ' . $e->getMessage(), [
                'topic' => $topic,
            ]);
            return false;
        }
    }

    /**
     * Subscribe a device token to a topic
     */
    public function subscribeToTopic($deviceTokens, $topic)
    {
        $accessToken = $this->getAccessToken();

        if (!$accessToken) {
            Log::error('Failed to get Firebase access token for topic subscription');
            return false;
        }

        // Ensure deviceTokens is an array
        $deviceTokens = is_array($deviceTokens) ? $deviceTokens : [$deviceTokens];

        $data = [
            'to' => '/topics/' . $topic,
            'registration_tokens' => $deviceTokens,
        ];

        try {
            $response = $this->httpForFirebase()
                ->withToken($accessToken)
                ->post('https://iid.googleapis.com/iid/v1:batchAdd', $data);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Topic subscription error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate a device token
     */
    public function validateToken($deviceToken)
    {
        $result = $this->sendToDevice($deviceToken, 'Test', 'Test notification', [
            'test' => 'true',
            'silent' => 'true',
        ]);

        return $result['success'] ?? false;
    }
}

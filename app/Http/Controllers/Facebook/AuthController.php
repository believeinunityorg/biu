<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\FacebookAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AuthController extends Controller
{
    protected string $appId;

    protected string $appSecret;

    protected string $redirectUri;

    protected string $apiVersion;

    protected ?string $configId;

    protected bool $oauthDebug;

    public function __construct()
    {
        $this->appId = (string) config('services.facebook.app_id');
        $this->appSecret = (string) config('services.facebook.app_secret');
        $this->redirectUri = (string) config('services.facebook.redirect_uri');
        $this->apiVersion = (string) config('facebook.api_version', 'v21.0');
        $configId = config('services.facebook.config_id');
        $this->configId = filled($configId) ? (string) $configId : null;
        $this->oauthDebug = (bool) config('facebook.oauth.debug', true);
        $this->middleware('auth');
    }

    /**
     * Show Facebook connection page.
     */
    public function connect(Request $request)
    {
        $user = $request->user();
        $organization = $user->organization;

        if (! $organization) {
            return redirect()->route('dashboard')
                ->with('error', 'You need to have an organization to connect Facebook');
        }

        $accounts = FacebookAccount::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->latest()
            ->get()
            ->map(fn ($account) => [
                'id' => $account->id,
                'facebook_page_id' => $account->facebook_page_id,
                'facebook_page_name' => $account->facebook_page_name,
                'page_category' => $account->page_category,
                'followers_count' => $account->followers_count,
                'is_connected' => $account->is_connected,
                'last_synced_at' => $account->last_synced_at?->format('Y-m-d H:i:s'),
                'picture_url' => $account->getPagePictureUrl('small'),
                'is_token_expired' => $account->isTokenExpired(),
            ]);

        return Inertia::render('Facebook/Connect', [
            'accounts' => $accounts,
            'hasConnectedAccounts' => $accounts->count() > 0,
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'requestedScopes' => config('facebook.scopes', []),
            'privacyPolicyUrl' => url('/privacy-policy'),
        ]);
    }

    /**
     * Redirect to Facebook OAuth after in-app permission disclosure.
     *
     * This connects a Facebook Page to an already-authenticated platform user.
     * It is NOT platform login — do not treat this as "Login with Facebook".
     */
    public function redirectToFacebook(Request $request)
    {
        $nonce = bin2hex(random_bytes(16));
        $state = base64_encode(json_encode([
            'user_id' => auth()->id(),
            'time' => time(),
            'nonce' => $nonce,
        ]));

        // Persist state for CSRF validation on callback (Meta OAuth best practice).
        $request->session()->put(config('facebook.oauth.state_session_key'), [
            'state' => $state,
            'nonce' => $nonce,
            'user_id' => auth()->id(),
            'created_at' => now()->toIso8601String(),
        ]);

        $params = [
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'auth_type' => 'rerequest',
        ];

        // Standard Facebook Login scopes (Page connection).
        // If FACEBOOK_CONFIG_ID is set (Facebook Login for Business), include config_id.
        // Meta recommends omitting scope when using config_id; we keep scopes as fallback
        // unless explicitly disabled so existing dashboard configs keep working.
        $scopes = config('facebook.scopes', []);
        if ($this->configId) {
            $params['config_id'] = $this->configId;
            if (config('facebook.oauth.include_scopes_with_config_id', true)) {
                $params['scope'] = implode(',', $scopes);
            }
        } else {
            $params['scope'] = implode(',', $scopes);
        }

        $url = "https://www.facebook.com/{$this->apiVersion}/dialog/oauth?".http_build_query($params);

        $this->debugLog('OAuth authorization URL built', [
            'app_id' => $this->appId,
            'api_version' => $this->apiVersion,
            'redirect_uri' => $this->redirectUri,
            'requested_scopes' => $scopes,
            'config_id' => $this->configId,
            'auth_url' => $url,
        ]);

        return redirect($url);
    }

    /**
     * OAuth callback — exchange code, immediately list pages, store selection payload.
     */
    public function callback(Request $request)
    {
        $this->debugLog('OAuth callback executed', [
            'has_error' => $request->has('error'),
            'error' => $request->get('error'),
            'error_reason' => $request->get('error_reason'),
            'error_description' => $request->get('error_description'),
            'has_code' => $request->filled('code'),
            'has_state' => $request->filled('state'),
            'query_keys' => array_keys($request->query()),
        ]);

        if ($request->has('error')) {
            return redirect()->route('facebook.connect')
                ->with('error', 'Facebook authorization was cancelled or denied.');
        }

        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        $user = $request->user();
        $organization = $user->organization;

        if (! $organization) {
            return redirect()->route('facebook.connect')
                ->with('error', 'Organization not found');
        }

        if (! $this->validateOAuthState($request)) {
            return redirect()->route('facebook.connect')
                ->with('error', 'Invalid OAuth state. Please try connecting again.');
        }

        try {
            // 1) Exchange authorization code → fresh User Access Token (never reuse stored tokens).
            $tokenData = $this->exchangeCodeForToken($request->code);
            $userAccessToken = $tokenData['access_token'];

            $this->debugLog('User Access Token obtained from code exchange', [
                'user_access_token' => $userAccessToken,
                'token_type' => $tokenData['token_type'] ?? null,
                'expires_in' => $tokenData['expires_in'] ?? null,
            ]);

            // 2) Inspect granted scopes (helps App Review / Meta Testing debugging).
            $grantedScopes = $this->inspectGrantedScopes($userAccessToken);
            $this->debugLog('Granted scopes from debug_token', [
                'granted_scopes' => $grantedScopes,
                'requested_scopes' => config('facebook.scopes', []),
            ]);

            $facebookUser = $this->fetchFacebookUser($userAccessToken);

            // 3) Meta expected Page-connection step: immediately call GET /me/accounts
            //    with the User Access Token from this OAuth exchange (not a cached token).
            $pagesFromUserToken = $this->fetchManagedPages($userAccessToken, 'short_lived_user_token');

            // 4) Exchange for long-lived user token, then re-fetch pages so stored Page
            //    Access Tokens are non-expiring (Meta recommendation).
            $longLived = $this->exchangeForLongLivedToken($userAccessToken);
            $longLivedToken = $longLived['token'];
            $expiresIn = $longLived['expires_in'];

            $this->debugLog('Long-lived User Access Token exchange', [
                'user_access_token' => $longLivedToken,
                'expires_in' => $expiresIn,
                'used_short_lived_fallback' => $longLivedToken === $userAccessToken,
            ]);

            $pages = $this->fetchManagedPages($longLivedToken, 'long_lived_user_token');
            if ($pages === [] && $pagesFromUserToken !== []) {
                // Prefer durable tokens when available; fall back to immediate OAuth result.
                $pages = $pagesFromUserToken;
            }

            if ($pages === []) {
                throw new \Exception('No Facebook pages found. You must be an admin of at least one Page to connect.');
            }

            $request->session()->put(config('facebook.oauth.session_key'), [
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'facebook_user_id' => $facebookUser['id'],
                'facebook_user_name' => $facebookUser['name'],
                'granted_scopes' => $grantedScopes,
                'token_expires_at' => now()->addSeconds($expiresIn)->toIso8601String(),
                'pages' => $pages,
                'created_at' => now()->toIso8601String(),
            ]);

            $this->debugLog('Pending OAuth session stored for page selection', [
                'facebook_user_id' => $facebookUser['id'],
                'page_count' => count($pages),
                'page_ids' => array_values(array_map(fn ($p) => $p['id'] ?? null, $pages)),
                'granted_scopes' => $grantedScopes,
            ]);

            return redirect()->route('facebook.select-pages')
                ->with('success', 'Choose which Facebook Page(s) to connect to your organization.');

        } catch (\Exception $e) {
            Log::error('Facebook OAuth error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('facebook.connect')
                ->with('error', 'Failed to connect Facebook: '.$e->getMessage());
        }
    }

    /**
     * Page picker — demonstrates pages_show_list before saving tokens.
     */
    public function selectPages(Request $request)
    {
        $pending = $this->pendingOAuth($request);
        if (! $pending) {
            return redirect()->route('facebook.connect')
                ->with('error', 'Your Facebook session expired. Please connect again.');
        }

        $pages = collect($pending['pages'] ?? [])->map(fn ($page) => [
            'id' => $page['id'],
            'name' => $page['name'],
            'category' => $page['category'] ?? null,
            'followers_count' => $page['followers_count'] ?? 0,
            'picture_url' => $page['picture']['data']['url'] ?? null,
        ])->values();

        return Inertia::render('Facebook/SelectPages', [
            'pages' => $pages,
            'facebookUserName' => $pending['facebook_user_name'] ?? 'Facebook User',
            'organizationName' => $request->user()->organization?->name ?? 'Your Organization',
        ]);
    }

    /**
     * Save only the Pages the user selected (required for App Review screencast).
     */
    public function storeSelectedPages(Request $request)
    {
        $pending = $this->pendingOAuth($request);
        if (! $pending) {
            return redirect()->route('facebook.connect')
                ->with('error', 'Your Facebook session expired. Please connect again.');
        }

        $validated = $request->validate([
            'page_ids' => 'required|array|min:1',
            'page_ids.*' => 'required|string',
        ]);

        $user = $request->user();
        $organization = $user->organization;

        if (! $organization || (int) $pending['organization_id'] !== (int) $organization->id) {
            return redirect()->route('facebook.connect')
                ->with('error', 'Organization mismatch. Please connect again.');
        }

        $selectedIds = array_flip($validated['page_ids']);
        $pagesToSave = collect($pending['pages'] ?? [])
            ->filter(fn ($page) => isset($selectedIds[$page['id']]))
            ->values();

        if ($pagesToSave->isEmpty()) {
            return redirect()->route('facebook.select-pages')
                ->with('error', 'Select at least one Facebook Page to continue.');
        }

        DB::beginTransaction();

        try {
            $expiresAt = isset($pending['token_expires_at'])
                ? \Carbon\Carbon::parse($pending['token_expires_at'])
                : now()->addDays(60);

            foreach ($pagesToSave as $page) {
                $pageAccessToken = $page['access_token'] ?? null;
                if (! filled($pageAccessToken)) {
                    throw new \Exception('Missing Page Access Token for page '.$page['id']);
                }

                // Persist token in dedicated column; strip from JSON blob.
                $pageData = $page;
                unset($pageData['access_token']);

                $this->debugLog('Storing selected Page Access Token', [
                    'selected_page_id' => $page['id'],
                    'selected_page_name' => $page['name'] ?? null,
                    'page_access_token' => $pageAccessToken,
                ]);

                FacebookAccount::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'organization_id' => $organization->id,
                        'facebook_page_id' => $page['id'],
                    ],
                    [
                        'facebook_user_id' => $pending['facebook_user_id'],
                        'facebook_user_name' => $pending['facebook_user_name'],
                        'facebook_page_name' => $page['name'],
                        'page_access_token' => $pageAccessToken,
                        'page_category' => $page['category'] ?? null,
                        'followers_count' => $page['followers_count'] ?? 0,
                        'page_data' => $pageData,
                        'is_connected' => true,
                        'last_synced_at' => now(),
                        'token_expires_at' => $expiresAt,
                    ]
                );
            }

            DB::commit();
            $request->session()->forget(config('facebook.oauth.session_key'));

            $this->debugLog('Selected pages saved successfully', [
                'selected_page_ids' => $pagesToSave->pluck('id')->all(),
                'count' => $pagesToSave->count(),
            ]);

            return redirect()->route('facebook.connect')
                ->with('success', 'Connected '.$pagesToSave->count().' Facebook Page(s). You can now read Page content and publish posts.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Facebook page save error: '.$e->getMessage());

            return redirect()->route('facebook.select-pages')
                ->with('error', 'Failed to save selected pages: '.$e->getMessage());
        }
    }

    public function disconnect(Request $request, $id)
    {
        $user = $request->user();

        $account = FacebookAccount::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            Http::delete("https://graph.facebook.com/{$this->apiVersion}/{$account->facebook_page_id}/permissions", [
                'access_token' => $account->page_access_token,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to revoke Facebook token: '.$e->getMessage());
        }

        $account->delete();

        return redirect()->route('facebook.connect')
            ->with('success', 'Facebook page disconnected successfully');
    }

    public function refresh(Request $request, $id)
    {
        $user = $request->user();

        $account = FacebookAccount::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            $pageInfo = Http::get("https://graph.facebook.com/{$this->apiVersion}/{$account->facebook_page_id}", [
                'access_token' => $account->page_access_token,
                'fields' => 'id,name,category,followers_count,picture{url}',
            ]);

            if (! $pageInfo->successful()) {
                throw new \Exception('Failed to get page info from Facebook');
            }

            $data = $pageInfo->json();

            $account->update([
                'facebook_page_name' => $data['name'] ?? $account->facebook_page_name,
                'page_category' => $data['category'] ?? $account->page_category,
                'followers_count' => $data['followers_count'] ?? $account->followers_count,
                'last_synced_at' => now(),
                'is_connected' => true,
            ]);

            return redirect()->route('facebook.connect')
                ->with('success', 'Facebook page refreshed successfully');

        } catch (\Exception $e) {
            $account->update(['is_connected' => false]);

            return redirect()->route('facebook.connect')
                ->with('error', 'Failed to refresh Facebook page: '.$e->getMessage());
        }
    }

    public function setDefault(Request $request, $id)
    {
        $user = $request->user();
        $organization = $user->organization;

        if (! $organization) {
            return redirect()->route('facebook.connect')
                ->with('error', 'Organization not found');
        }

        $account = FacebookAccount::where('id', $id)
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        $organization->update([
            'default_facebook_account_id' => $account->id,
        ]);

        return redirect()->route('facebook.connect')
            ->with('success', 'Default Facebook page set successfully');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingOAuth(Request $request): ?array
    {
        $pending = $request->session()->get(config('facebook.oauth.session_key'));
        if (! is_array($pending)) {
            return null;
        }

        $created = isset($pending['created_at']) ? \Carbon\Carbon::parse($pending['created_at']) : null;
        $ttl = (int) config('facebook.oauth.session_ttl_minutes', 30);
        if ($created && $created->lt(now()->subMinutes($ttl))) {
            $request->session()->forget(config('facebook.oauth.session_key'));

            return null;
        }

        if ((int) ($pending['user_id'] ?? 0) !== (int) $request->user()->id) {
            return null;
        }

        return $pending;
    }

    private function validateOAuthState(Request $request): bool
    {
        $stateKey = config('facebook.oauth.state_session_key');
        $stored = $request->session()->pull($stateKey);
        $incomingState = (string) $request->state;

        $decoded = json_decode(base64_decode($incomingState), true);
        if (! is_array($decoded) || (int) ($decoded['user_id'] ?? 0) !== (int) $request->user()->id) {
            $this->debugLog('OAuth state user mismatch', [
                'decoded_user_id' => $decoded['user_id'] ?? null,
                'auth_user_id' => $request->user()->id,
            ]);

            return false;
        }

        if (! is_array($stored) || ($stored['state'] ?? null) !== $incomingState) {
            // Soft-fail only when session state is missing (e.g. cookie edge cases) but
            // decoded user_id still matches — keep strict match when stored state exists.
            if (is_array($stored) && ($stored['state'] ?? null) !== $incomingState) {
                $this->debugLog('OAuth state session mismatch', [
                    'stored_present' => true,
                ]);

                return false;
            }

            $this->debugLog('OAuth state session missing; accepted decoded user_id match', [
                'stored_present' => is_array($stored),
            ]);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCodeForToken(string $code): array
    {
        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        if (! $response->successful()) {
            Log::error('Facebook token error: '.$response->body());
            throw new \Exception('Failed to get access token from Facebook');
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['access_token'])) {
            throw new \Exception('Facebook token response missing access_token');
        }

        return $data;
    }

    /**
     * @return array{id: string, name: string}
     */
    private function fetchFacebookUser(string $accessToken): array
    {
        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/me", [
            'access_token' => $accessToken,
            'fields' => 'id,name',
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get user info from Facebook');
        }

        return $response->json();
    }

    /**
     * @return array{token: string, expires_in: int}
     */
    private function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (! $response->successful()) {
            Log::warning('Could not get long-lived token, using short-lived');

            return ['token' => $shortLivedToken, 'expires_in' => 3600];
        }

        $data = $response->json();

        return [
            'token' => $data['access_token'],
            'expires_in' => (int) ($data['expires_in'] ?? 5184000),
        ];
    }

    /**
     * GET /me/accounts — requires pages_show_list on the User Access Token.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchManagedPages(string $accessToken, string $tokenLabel = 'user_token'): array
    {
        $endpoint = "https://graph.facebook.com/{$this->apiVersion}/me/accounts";
        $query = [
            'access_token' => $accessToken,
            'fields' => 'id,name,category,followers_count,access_token,tasks,picture{url}',
            'limit' => 200,
        ];

        $this->debugLog('GET /me/accounts request', [
            'token_label' => $tokenLabel,
            'endpoint' => $endpoint,
            'fields' => $query['fields'],
            'user_access_token' => $accessToken,
        ]);

        $response = Http::get($endpoint, $query);

        $body = $response->json();
        $pages = is_array($body) ? ($body['data'] ?? []) : [];

        $this->debugLog('GET /me/accounts Graph API response', [
            'token_label' => $tokenLabel,
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'page_count' => is_array($pages) ? count($pages) : 0,
            'page_ids' => is_array($pages) ? array_values(array_map(fn ($p) => $p['id'] ?? null, $pages)) : [],
            'error' => is_array($body) ? ($body['error'] ?? null) : null,
            // TEMPORARY: full Graph payload including page tokens for audit.
            'raw_response' => $body,
        ]);

        if (! $response->successful()) {
            $message = is_array($body) ? ($body['error']['message'] ?? $response->body()) : $response->body();
            throw new \Exception('Failed to get pages from Facebook: '.$message);
        }

        return is_array($pages) ? $pages : [];
    }

    /**
     * @return list<string>
     */
    private function inspectGrantedScopes(string $userAccessToken): array
    {
        try {
            $appToken = $this->appId.'|'.$this->appSecret;
            $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/debug_token", [
                'input_token' => $userAccessToken,
                'access_token' => $appToken,
            ]);

            if (! $response->successful()) {
                $this->debugLog('debug_token failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json('data', []);
            $scopes = $data['scopes'] ?? [];

            if (isset($data['granular_scopes']) && is_array($data['granular_scopes'])) {
                $fromGranular = array_values(array_unique(array_filter(array_map(
                    fn ($item) => is_array($item) ? ($item['scope'] ?? null) : null,
                    $data['granular_scopes']
                ))));
                if ($fromGranular !== []) {
                    $scopes = array_values(array_unique(array_merge(
                        is_array($scopes) ? $scopes : [],
                        $fromGranular
                    )));
                }
            }

            return is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
        } catch (\Throwable $e) {
            $this->debugLog('debug_token exception', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * TEMPORARY debug logging for Meta Page-connection audit. Disable via FACEBOOK_OAUTH_DEBUG=false.
     *
     * @param  array<string, mixed>  $context
     */
    private function debugLog(string $message, array $context = []): void
    {
        if (! $this->oauthDebug) {
            return;
        }

        Log::channel(config('facebook.oauth.debug_channel', 'stack'))->info(
            '[TEMP facebook.oauth.debug] '.$message,
            $context
        );
    }
}

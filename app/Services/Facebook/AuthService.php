<?php

namespace App\Services\Facebook;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthService
{
    private string $appId;

    private string $appSecret;

    private string $redirectUri;

    private string $apiVersion = 'v21.0';

    public function __construct()
    {
        $this->appId = config('services.facebook.app_id') ?? '';
        $this->appSecret = config('services.facebook.app_secret') ?? '';
        $this->redirectUri = config('services.facebook.redirect_uri') ?? '';

        if (! $this->appId || ! $this->appSecret) {
            throw new Exception('Facebook app credentials not configured');
        }
    }

    /**
     * Get Facebook OAuth URL for page connection
     */
    public function getOAuthUrl(?string $state = null): string
    {
        $scopes = [
            'pages_show_list',
            'pages_manage_posts',
        ];

        $params = [
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
            'state' => $state ?? uniqid(),
            'auth_type' => 'rerequest',
        ];

        return 'https://www.facebook.com/'.$this->apiVersion.'/dialog/oauth?'.http_build_query($params);
    }

    /**
     * Exchange code for access token
     */
    public function getAccessToken(string $code): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/oauth/access_token";

        $response = Http::get($url, [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new Exception('Failed to get access token: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get long-lived access token
     */
    public function getLongLivedToken(string $shortLivedToken): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/oauth/access_token";

        $response = Http::get($url, [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($response->failed()) {
            throw new Exception('Failed to get long-lived token: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get user's Facebook pages
     */
    public function getUserPages(string $accessToken): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/me/accounts";

        $response = Http::get($url, [
            'access_token' => $accessToken,
            'fields' => 'id,name,access_token,category,category_list,followers_count,picture{url},link,verification_status',
        ]);

        if ($response->failed()) {
            throw new Exception('Failed to get user pages: '.$response->body());
        }

        return $response->json()['data'] ?? [];
    }

    /**
     * Get page access token
     */
    public function getPageAccessToken(string $userAccessToken, string $pageId): string
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$pageId}";

        $response = Http::get($url, [
            'fields' => 'access_token',
            'access_token' => $userAccessToken,
        ]);

        if ($response->failed()) {
            throw new Exception('Failed to get page access token: '.$response->body());
        }

        $data = $response->json();

        return $data['access_token'] ?? '';
    }

    /**
     * Debug token to get info
     */
    public function debugToken(string $accessToken): array
    {
        $url = 'https://graph.facebook.com/debug_token';

        $response = Http::get($url, [
            'input_token' => $accessToken,
            'access_token' => "{$this->appId}|{$this->appSecret}",
        ]);

        if ($response->failed()) {
            throw new Exception('Failed to debug token: '.$response->body());
        }

        return $response->json()['data'] ?? [];
    }

    /**
     * Revoke access token
     */
    public function revokeToken(string $userId, string $accessToken): bool
    {
        try {
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$userId}/permissions";

            $response = Http::delete($url, [
                'access_token' => $accessToken,
            ]);

            return $response->successful();
        } catch (Exception $e) {
            Log::error('Failed to revoke Facebook token: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Generate OAuth URL for specific app
     */
    public function getOAuthUrlForApp($appId, $appSecret, $redirectUri, $state = null)
    {
        $state = $state ?: base64_encode(json_encode(['app_id' => $appId, 'time' => time()]));

        $params = [
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', [
                'pages_show_list',
                'pages_manage_posts',
            ]),
            'response_type' => 'code',
        ];

        return 'https://www.facebook.com/v19.0/dialog/oauth?'.http_build_query($params);
    }

    /**
     * Get access token for specific app
     */
    public function getAccessTokenForApp($code, $appId, $appSecret, $redirectUri)
    {
        $response = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get access token: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get long-lived token for specific app
     */
    public function getLongLivedTokenForApp($shortLivedToken, $appId, $appSecret)
    {
        $response = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get long-lived token: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Verify app credentials
     */
    public function verifyAppCredentials($appId, $appSecret)
    {
        try {
            $response = Http::get('https://graph.facebook.com/debug_token', [
                'input_token' => $appId.'|'.$appSecret,
                'access_token' => $appId.'|'.$appSecret,
            ]);

            return $response->successful();

        } catch (\Exception $e) {
            return false;
        }
    }
}

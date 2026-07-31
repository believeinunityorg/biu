<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GiftCardService
{
    // Cache duration in seconds (5 minutes)
    private const CACHE_DURATION = 300;

    // Phaze API Configuration
    private function getBaseUrl(): string
    {
        $environment = config('services.phaze.environment', env('PHAZE_ENVIRONMENT', 'sandbox'));
        $explicitBaseUrl = env('PHAZE_BASE_URL');

        // If explicit base URL is set, use it
        if (! empty($explicitBaseUrl)) {
            return rtrim($explicitBaseUrl, '/');
        }

        // Otherwise, use environment-based defaults
        if ($environment === 'sandbox') {
            return 'https://api.sandbox.phaze.io';
        } else {
            return 'https://api.phaze.io';
        }
    }

    private function getApiKey(): string
    {
        return trim((string) config('services.phaze.api_key', env('PHAZE_API_KEY')));
    }

    private function getApiSecret(): string
    {
        return trim((string) config('services.phaze.api_secret', env('PHAZE_API_SECRET')));
    }

    /**
     * Stable JSON encoding for Phaze request bodies.
     * Signature and POST body MUST use this exact same string.
     *
     * Floats are injected as clean decimal literals (e.g. 9.9, 0.49). PHP's
     * json_encode of binary floats (9.90000000000000035…) breaks Phaze signatures.
     *
     * @param  array<string, mixed>  $body
     */
    private function encodeJsonBody(array $body): string
    {
        $numberLiterals = [];
        $sanitized = $this->replaceFloatsWithJsonNumberTokens($body, $numberLiterals);

        $encoded = json_encode(
            $sanitized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($encoded === false) {
            throw new \RuntimeException('Failed to JSON-encode Phaze request body.');
        }

        if ($numberLiterals !== []) {
            $encoded = strtr($encoded, $numberLiterals);
        }

        return $encoded;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $numberLiterals  map of JSON-encoded token string => numeric literal
     * @return array<string, mixed>
     */
    private function replaceFloatsWithJsonNumberTokens(array $data, array &$numberLiterals): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->replaceFloatsWithJsonNumberTokens($value, $numberLiterals);

                continue;
            }

            if (! is_float($value)) {
                continue;
            }

            $token = '__PHAZE_NUM_'.count($numberLiterals).'__';
            $numberLiterals['"'.$token.'"'] = $this->formatPhazeJsonNumber($value);
            $data[$key] = $token;
        }

        return $data;
    }

    /**
     * JSON number literal with at most 2 decimal places (no binary float noise).
     */
    private function formatPhazeJsonNumber(int|float|string $amount): string
    {
        $rounded = round((float) $amount, 2);

        if (abs($rounded - (int) round($rounded)) < 0.001) {
            return (string) (int) round($rounded);
        }

        $formatted = number_format($rounded, 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }

    /**
     * Normalize face value so JSON does not emit float noise (e.g. 0.49000000000000005),
     * which causes Phaze "Signature did not match" when their verifier re-serializes the body.
     */
    private function normalizePhazePrice(float|int|string $amount): int|float
    {
        $rounded = round((float) $amount, 2);

        if (abs($rounded - (int) round($rounded)) < 0.001) {
            return (int) round($rounded);
        }

        // Still a float in PHP — encodeJsonBody formats the JSON literal cleanly.
        return $rounded;
    }

    /**
     * Generate signature for Phaze API request.
     *
     * Phaze: sha256(METHOD + path + secret + rawJsonBody)
     *
     * @param  array<string, mixed>|string|null  $body  Pre-encoded JSON string preferred for POSTs
     */
    private function generateSignature(string $method, string $endpoint, array|string|null $body = null): ?string
    {
        $apiSecret = $this->getApiSecret();

        if ($apiSecret === '') {
            return null;
        }

        $requestMethod = strtoupper($method);
        $requestBody = '';

        if ($body !== null && in_array($requestMethod, ['POST', 'PUT', 'PATCH'], true)) {
            $requestBody = is_string($body) ? $body : $this->encodeJsonBody($body);
        }

        return hash('sha256', $requestMethod.$endpoint.$apiSecret.$requestBody);
    }

    /**
     * Make cURL request to Phaze API.
     *
     * @param  array<string, mixed>|string|null  $body  Array or pre-encoded JSON string
     */
    private function makeCurlRequest(string $method, string $endpoint, array $headers = [], array|string|null $body = null): ?array
    {
        $baseUrl = $this->getBaseUrl();
        $fullUrl = rtrim($baseUrl, '/').$endpoint;

        $rawBody = null;
        if (is_string($body)) {
            $rawBody = $body;
        } elseif (is_array($body)) {
            $rawBody = $this->encodeJsonBody($body);
        }

        $ch = curl_init();

        // Basic cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
        ]);

        // Set headers
        $headerArray = [];
        foreach ($headers as $key => $value) {
            $headerArray[] = $key.': '.$value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);

        // Set method and body — use the exact bytes that were signed
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($rawBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($rawBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error('cURL error in Phaze API request', [
                'error' => $error,
                'endpoint' => $endpoint,
            ]);

            return null;
        }

        // Try to parse response even if HTTP code indicates error
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse Phaze API JSON response', [
                'error' => json_last_error_msg(),
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
            ]);

            return null;
        }

        // If HTTP code indicates error, return error data with httpStatusCode
        if ($httpCode < 200 || $httpCode >= 300) {
            Log::warning('Phaze API request failed', [
                'http_code' => $httpCode,
                'endpoint' => $endpoint,
                'response_preview' => substr($response, 0, 200),
                'error_data' => $data,
            ]);


            // Return error data with httpStatusCode so caller can check for errors
            if (is_array($data)) {
                $data['httpStatusCode'] = $httpCode;

                return $data;
            }

            return [
                'httpStatusCode' => $httpCode,
                'error' => $response ? substr($response, 0, 200) : 'Unknown error',
            ];
        }

        return $data;
    }

    /**
     * Get account status from Phaze API
     */
    public function getAccountStatus(): ?array
    {
        try {
            $endpoint = '/accountstatus';
            $apiKey = $this->getApiKey();

            if (empty($apiKey)) {
                Log::warning('Phaze API key not configured');

                return null;
            }

            $signature = $this->generateSignature('GET', $endpoint, null);

            $headers = [
                'API-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            // Phaze requires Signature on authenticated routes; missing signature
            // returns HTTP 400 "Signature did not match".
            if ($signature) {
                $headers['Signature'] = $signature;
            }

            return $this->makeCurlRequest('GET', $endpoint, $headers);
        } catch (\Exception $e) {
            Log::error('Error fetching Phaze account status: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Fetch live Phaze account balance (primary source for purchase / fulfillment affordability).
     *
     * @return array{
     *     available: float|null,
     *     currency: string,
     *     fetched_at: string|null,
     *     error: string|null,
     *     variance: float|null
     * }
     */
    public function getLivePrefundedBalance(?float $internalBalance = null): array
    {
        $status = $this->getAccountStatus();

        if ($status === null) {
            return [
                'available' => null,
                'currency' => 'USD',
                'fetched_at' => now()->toIso8601String(),
                'error' => 'Unable to reach Phaze account status API.',
                'variance' => null,
            ];
        }

        if (isset($status['httpStatusCode']) && (int) $status['httpStatusCode'] >= 400) {
            return [
                'available' => null,
                'currency' => 'USD',
                'fetched_at' => now()->toIso8601String(),
                'error' => (string) ($status['error'] ?? 'Phaze account status request failed.'),
                'variance' => null,
            ];
        }

        $available = $this->extractBalanceFromAccountStatus($status);
        $currency = $this->extractCurrencyFromAccountStatus($status) ?? 'USD';

        if ($available === null) {
            return [
                'available' => null,
                'currency' => $currency,
                'fetched_at' => now()->toIso8601String(),
                'error' => 'Phaze account status did not include a recognizable balance field.',
                'variance' => null,
            ];
        }

        $variance = $internalBalance !== null
            ? round($available - $internalBalance, 2)
            : null;

        return [
            'available' => round($available, 2),
            'currency' => $currency,
            'fetched_at' => now()->toIso8601String(),
            'error' => null,
            'variance' => $variance,
        ];
    }

    public function isPurchaseSuccessful(?array $result): bool
    {
        if ($result === null || $result === []) {
            return false;
        }

        if (isset($result['error']) && $result['error'] !== '') {
            return false;
        }

        if (isset($result['httpStatusCode']) && (int) $result['httpStatusCode'] >= 400) {
            return false;
        }

        return true;
    }

    /**
     * Extract raw Phaze error text from a purchase/status response.
     */
    public function extractPhazeErrorMessage(?array $result, string $fallback = 'Phaze purchase failed.'): string
    {
        if ($result === null || $result === []) {
            return $fallback;
        }

        $error = $result['error'] ?? $result['message'] ?? null;

        if (is_string($error) && trim($error) !== '') {
            return trim($error);
        }

        if (isset($result['httpStatusCode'])) {
            return 'Phaze API error (HTTP '.(int) $result['httpStatusCode'].').';
        }

        return $fallback;
    }

    /**
     * Admin-facing Phaze error with actionable guidance.
     */
    public function humanizePhazeErrorForAdmin(?array $result, ?string $rawError = null): string
    {
        $raw = trim((string) ($rawError ?? $this->extractPhazeErrorMessage($result)));
        $code = is_array($result) ? (int) ($result['httpStatusCode'] ?? 0) : 0;
        $lower = strtolower($raw);

        if (str_contains($lower, 'signature')) {
            return 'Phaze signature mismatch. Confirm PHAZE_API_KEY / PHAZE_API_SECRET / PHAZE_BASE_URL match the Phaze dashboard (quote secrets in .env), then run config:clear and retry.';
        }

        if ($code === 402 || str_contains($lower, 'balance too low') || str_contains($lower, 'pre-funded') || str_contains($lower, 'prefunded')) {
            return 'Phaze account balance is too low to place this order. Prefund the live Phaze account (not the internal ledger), then retry fulfillment.';
        }

        if ($code === 401 || str_contains($lower, 'unauthorized') || str_contains($lower, 'api-key') || str_contains($lower, 'api key')) {
            return 'Phaze rejected the API key. Verify PHAZE_API_KEY for the production environment.';
        }

        return $raw !== '' ? $raw : 'Phaze purchase failed.';
    }

    /**
     * Supporter-facing Phaze error (no env/credential details).
     */
    public function humanizePhazeErrorForUser(?array $result, ?string $rawError = null): string
    {
        $raw = trim((string) ($rawError ?? $this->extractPhazeErrorMessage($result)));
        $code = is_array($result) ? (int) ($result['httpStatusCode'] ?? 0) : 0;
        $lower = strtolower($raw);

        if (str_contains($lower, 'signature') || $code === 401) {
            return 'Gift card issuance failed due to a provider authentication error. Our team has been notified — please try again later or contact support.';
        }

        if ($code === 402 || str_contains($lower, 'balance too low') || str_contains($lower, 'pre-funded') || str_contains($lower, 'prefunded')) {
            return 'Gift card issuance is temporarily unavailable because the gift card reserve needs funding. Please try again later or contact support.';
        }

        if ($raw !== '') {
            return 'Gift card issuance failed: '.$raw;
        }

        return 'Gift card issuance failed. Please try again later or contact support.';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractBalanceFromAccountStatus(array $payload): ?float
    {
        $candidateKeys = [
            'balance',
            'availableBalance',
            'available_balance',
            'prefundedBalance',
            'prefunded_balance',
            'accountBalance',
            'account_balance',
            'funds',
            'availableFunds',
            'available_funds',
            'remainingBalance',
            'remaining_balance',
            'credit',
            'credits',
        ];

        foreach ($candidateKeys as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (float) $payload[$key];
            }
        }

        foreach (['data', 'account', 'wallet', 'result', 'accountStatus', 'account_status'] as $nestedKey) {
            if (! isset($payload[$nestedKey]) || ! is_array($payload[$nestedKey])) {
                continue;
            }

            $nestedBalance = $this->extractBalanceFromAccountStatus($payload[$nestedKey]);
            if ($nestedBalance !== null) {
                return $nestedBalance;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractCurrencyFromAccountStatus(array $payload): ?string
    {
        foreach (['currency', 'baseCurrency', 'base_currency'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return strtoupper($payload[$key]);
            }
        }

        foreach (['data', 'account', 'wallet', 'result'] as $nestedKey) {
            if (! isset($payload[$nestedKey]) || ! is_array($payload[$nestedKey])) {
                continue;
            }

            $currency = $this->extractCurrencyFromAccountStatus($payload[$nestedKey]);
            if ($currency !== null) {
                return $currency;
            }
        }

        return null;
    }

    /**
     * Create a disbursement (gift card purchase) via Phaze API
     */
    public function createDisbursement(array $data): ?array
    {
        try {
            $disbursementData = [
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'USD',
                'recipient' => [
                    'email' => $data['recipient_email'],
                    'name' => $data['recipient_name'] ?? 'Gift Card Recipient',
                ],
                'description' => $data['description'] ?? 'Gift Card Purchase',
                'metadata' => $data['metadata'] ?? [],
            ];

            $endpoint = '/disbursements';
            $signature = $this->generateSignature('POST', $endpoint, $disbursementData);

            $headers = [
                'API-Key' => $this->getApiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            if ($signature) {
                $headers['Signature'] = $signature;
            }

            return $this->makeCurlRequest('POST', $endpoint, $headers, $disbursementData);
        } catch (\Exception $e) {
            Log::error('Error creating Phaze disbursement: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Get disbursement status from Phaze API
     */
    public function getDisbursementStatus(string $disbursementId): ?array
    {
        try {
            $endpoint = '/disbursements/'.$disbursementId;
            $headers = [
                'API-Key' => $this->getApiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            return $this->makeCurlRequest('GET', $endpoint, $headers);
        } catch (\Exception $e) {
            Log::error('Error fetching Phaze disbursement status: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Purchase gift card via Phaze API
     * This is called after Stripe payment is successful
     *
     * @param  array  $data  Purchase data including productId, amount, currency, orderId, externalUserId
     * @return array|null Purchase response from Phaze API
     */
    public function purchaseGiftCard(array $data): ?array
    {
        try {
            // Field order matches Phaze docs examples (orderId, productId, price, externalUserId).
            $purchaseData = [
                'orderId' => (string) $data['orderId'],
                'productId' => (int) $data['productId'],
                'price' => $this->normalizePhazePrice($data['amount'] ?? 0),
                'externalUserId' => (string) $data['externalUserId'],
            ];

            $currency = strtoupper(trim((string) ($data['currency'] ?? $data['baseCurrency'] ?? '')));
            if ($currency !== '') {
                $purchaseData['baseCurrency'] = $currency;
            }

            $endpoint = '/purchase';
            // Encode once — signature and POST body must be byte-identical.
            $rawBody = $this->encodeJsonBody($purchaseData);
            $signature = $this->generateSignature('POST', $endpoint, $rawBody);

            $headers = [
                'API-Key' => $this->getApiKey(),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            if ($signature) {
                $headers['Signature'] = $signature;
            } else {
                Log::error('Phaze purchase aborted: PHAZE_API_SECRET is empty');

                return [
                    'httpStatusCode' => 400,
                    'error' => 'Signature did not match',
                    'message' => 'PHAZE_API_SECRET is missing on this server.',
                ];
            }

            $response = $this->makeCurlRequest('POST', $endpoint, $headers, $rawBody);

            if (! $response) {
                Log::warning('Phaze purchase API call failed', [
                    'orderId' => $purchaseData['orderId'],
                    'productId' => $purchaseData['productId'],
                    'price' => $purchaseData['price'],
                    'externalUserId' => $purchaseData['externalUserId'],
                    'body' => $rawBody,
                ]);
            } elseif (! $this->isPurchaseSuccessful($response)) {
                Log::warning('Phaze purchase rejected', [
                    'orderId' => $purchaseData['orderId'],
                    'productId' => $purchaseData['productId'],
                    'price' => $purchaseData['price'],
                    'http_status' => $response['httpStatusCode'] ?? null,
                    'error' => $this->extractPhazeErrorMessage($response),
                    'body' => $rawBody,
                ]);
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('Error purchasing gift card from Phaze API: '.$e->getMessage(), [
                'data' => $data,
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get purchase/transaction details from Phaze.
     *
     * Prefer orderId — Phaze's live transaction endpoint is GET /transaction/{orderId}.
     * GET /purchase/{id} is not available on current API versions (returns 404).
     *
     * @param  string  $purchaseId  Phaze transaction id (numeric) — used only as a weak fallback label
     * @param  string|null  $orderId  Our/Phaze order UUID (preferred)
     * @return array<string, mixed>|null
     */
    public function getPurchaseDetails(string $purchaseId, ?string $orderId = null): ?array
    {
        if (is_string($orderId) && trim($orderId) !== '') {
            $byOrder = $this->lookupTransactionByOrderId($orderId);
            if ($byOrder !== null) {
                return $byOrder;
            }
        }

        // Legacy path — kept for compatibility; often 404 on current Phaze API.
        try {
            $endpoint = '/purchase/'.$purchaseId;
            $apiKey = $this->getApiKey();

            if (empty($apiKey)) {
                Log::warning('Phaze API key not configured');

                return null;
            }

            $apiSecret = $this->getApiSecret();
            $signature = ! empty($apiSecret) ? $this->generateSignature('GET', $endpoint, null) : null;

            $headers = [
                'API-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            if ($signature) {
                $headers['Signature'] = $signature;
            }

            $response = $this->makeCurlRequest('GET', $endpoint, $headers);

            if (! $response && $signature) {
                unset($headers['Signature']);
                $response = $this->makeCurlRequest('GET', $endpoint, $headers);
            }

            if ($response && $this->isPhazeErrorResponse($response)) {
                return null;
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('Error fetching purchase details from Phaze API: '.$e->getMessage(), [
                'purchase_id' => $purchaseId,
                'order_id' => $orderId,
            ]);

            return null;
        }
    }

    /**
     * Lookup transaction by order ID from Phaze API (GET /transaction/{orderId}).
     * Preferred way to refresh status + voucher credentials after purchase.
     *
     * @return array<string, mixed>|null Transaction data or null on failure
     */
    public function lookupTransactionByOrderId(string $orderId): ?array
    {
        try {
            $orderId = trim($orderId);
            if ($orderId === '') {
                return null;
            }

            $endpoint = '/transaction/'.rawurlencode($orderId);
            $apiKey = $this->getApiKey();

            if (empty($apiKey)) {
                Log::warning('Phaze API key not configured');

                return null;
            }

            $apiSecret = $this->getApiSecret();
            $signature = ! empty($apiSecret) ? $this->generateSignature('GET', $endpoint, null) : null;

            $headers = [
                'API-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            if ($signature) {
                $headers['Signature'] = $signature;
            }

            // makeCurlRequest already returns decoded JSON (or error payload with httpStatusCode).
            $response = $this->makeCurlRequest('GET', $endpoint, $headers);

            if (! $response && $signature) {
                unset($headers['Signature']);
                $response = $this->makeCurlRequest('GET', $endpoint, $headers);
            }

            if (! $response || $this->isPhazeErrorResponse($response)) {
                Log::warning('Phaze transaction lookup failed', [
                    'order_id' => $orderId,
                    'http_status' => $response['httpStatusCode'] ?? null,
                    'error' => $response['error'] ?? $response['message'] ?? null,
                ]);

                return null;
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('Error looking up transaction by order ID: '.$e->getMessage(), [
                'order_id' => $orderId,
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isPhazeErrorResponse(array $response): bool
    {
        if (isset($response['httpStatusCode']) && (int) $response['httpStatusCode'] >= 400) {
            return true;
        }

        // Successful transaction payloads include status; pure error objects usually do not.
        if (isset($response['error']) && ! isset($response['status']) && ! isset($response['orderId']) && ! isset($response['id'])) {
            return true;
        }

        return false;
    }

    /**
     * Get gift card brands from Phaze API for a specific country
     * Optimized with caching and cURL
     *
     * @param  string  $country  Country name (e.g., "USA", "Canada", "UK")
     * @param  int  $currentPage  Page number for pagination (default: 1)
     * @return array List of brands
     */
    public function getGiftBrands(string $country = 'USA', int $currentPage = 1): array
    {
        $payload = $this->getGiftBrandsPagePayload($country, $currentPage);

        return $payload['brands'];
    }

    /**
     * Return exactly $chunkSize brands for app page $appPage by walking Phaze currentPage results.
     * Each call is a real Phaze-backed fetch (cached per Phaze page). Used for scroll pagination of 20.
     *
     * @return array{brands: array<int, array>, total: int|null, last_page: int, per_page: int, has_more: bool}
     */
    public function getGiftBrandsChunk(string $country = 'USA', int $appPage = 1, int $chunkSize = 20): array
    {
        $appPage = max(1, $appPage);
        $chunkSize = max(1, min(48, $chunkSize));
        $offset = ($appPage - 1) * $chunkSize;
        $need = $offset + $chunkSize;

        $collected = [];
        $apiTotal = null;
        $phazePage = 1;
        $maxPhazePages = 40;
        $sawEmpty = false;

        while (count($collected) < $need && $phazePage <= $maxPhazePages) {
            $payload = $this->getGiftBrandsPagePayload($country, $phazePage);
            $pageBrands = is_array($payload['brands'] ?? null) ? $payload['brands'] : [];

            if ($payload['total'] !== null) {
                $apiTotal = (int) $payload['total'];
            }

            if ($pageBrands === []) {
                $sawEmpty = true;
                break;
            }

            $collected = array_merge($collected, array_values($pageBrands));

            if ($payload['last_page'] !== null && $phazePage >= (int) $payload['last_page']) {
                break;
            }

            // If Phaze doesn't advertise last_page, stop when a short page arrives.
            $hint = $payload['per_page'] ?? null;
            if ($hint !== null && count($pageBrands) < (int) $hint) {
                break;
            }

            $phazePage++;
        }

        $chunk = array_slice($collected, $offset, $chunkSize);
        $collectedCount = count($collected);
        $hasMore = ($offset + count($chunk)) < $collectedCount
            || (! $sawEmpty && count($chunk) === $chunkSize && ($apiTotal === null || ($offset + $chunkSize) < $apiTotal));

        if ($apiTotal !== null) {
            $lastPage = max(1, (int) ceil($apiTotal / $chunkSize));
        } elseif ($hasMore) {
            $lastPage = $appPage + 1;
        } else {
            $lastPage = max(1, $appPage);
        }

        return [
            'brands' => array_values($chunk),
            'total' => $apiTotal ?? $collectedCount,
            'last_page' => $lastPage,
            'per_page' => $chunkSize,
            'has_more' => $hasMore && count($chunk) > 0,
        ];
    }

    /**
     * Search brands across Phaze pages for a country, returning one 20-card page of matches.
     * Scans only as far as needed for the requested page (then caches progress for faster next pages).
     *
     * @return array{brands: array<int, array>, total: int, last_page: int, per_page: int, has_more: bool}
     */
    public function searchGiftBrandsChunk(
        string $country = 'USA',
        string $search = '',
        int $appPage = 1,
        int $chunkSize = 20
    ): array {
        $appPage = max(1, $appPage);
        $chunkSize = max(1, min(48, $chunkSize));
        $needle = strtolower(trim($search));

        if ($needle === '') {
            return $this->getGiftBrandsChunk($country, $appPage, $chunkSize);
        }

        $offset = ($appPage - 1) * $chunkSize;
        $needForPage = $offset + $chunkSize;
        $needForHasMore = $needForPage + 1;

        $cacheKey = 'phaze_brands_search_v3_'.$country.'_'.md5($needle);
        $state = $appPage === 1
            ? [
                'matches' => [],
                'scanned_through_page' => 0,
                'exhausted' => false,
            ]
            : Cache::get($cacheKey, [
                'matches' => [],
                'scanned_through_page' => 0,
                'exhausted' => false,
            ]);

        $matches = is_array($state['matches'] ?? null) ? $state['matches'] : [];
        $scannedThrough = (int) ($state['scanned_through_page'] ?? 0);
        $exhausted = (bool) ($state['exhausted'] ?? false);
        $maxPhazePages = 40;

        // Resume Phaze scan only until we have enough matches for this page (+1 to know has_more).
        $phazePage = $scannedThrough + 1;
        while (! $exhausted && count($matches) < $needForHasMore && $phazePage <= $maxPhazePages) {
            $payload = $this->getGiftBrandsPagePayload($country, $phazePage);
            $pageBrands = is_array($payload['brands'] ?? null) ? $payload['brands'] : [];

            if ($pageBrands === []) {
                $exhausted = true;
                $scannedThrough = $phazePage;
                break;
            }

            foreach ($pageBrands as $brand) {
                if ($this->brandMatchesSearchNeedle($brand, $needle)) {
                    $matches[] = $brand;
                }
            }

            $scannedThrough = $phazePage;

            if ($payload['last_page'] !== null && $phazePage >= (int) $payload['last_page']) {
                $exhausted = true;
                break;
            }

            $hint = $payload['per_page'] ?? null;
            if ($hint !== null && count($pageBrands) < (int) $hint) {
                $exhausted = true;
                break;
            }

            $phazePage++;
        }

        if ($phazePage > $maxPhazePages) {
            $exhausted = true;
        }

        Cache::put($cacheKey, [
            'matches' => $matches,
            'scanned_through_page' => $scannedThrough,
            'exhausted' => $exhausted,
        ], self::CACHE_DURATION);

        $chunk = array_slice($matches, $offset, $chunkSize);
        $matchCount = count($matches);
        $hasMore = ($offset + count($chunk)) < $matchCount
            || (! $exhausted && count($chunk) === $chunkSize);

        if ($exhausted) {
            $total = $matchCount;
            $lastPage = max(1, (int) ceil(max(1, $total) / $chunkSize));
            if ($total === 0) {
                $lastPage = 1;
            }
        } else {
            // Still scanning — report a lower bound so the UI can keep scrolling.
            $total = max($matchCount, $needForPage + ($hasMore ? 1 : 0));
            $lastPage = $hasMore ? $appPage + 1 : max(1, $appPage);
        }

        return [
            'brands' => array_values($chunk),
            'total' => $total,
            'last_page' => $lastPage,
            'per_page' => $chunkSize,
            'has_more' => $hasMore && count($chunk) > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $brand
     */
    private function brandMatchesSearchNeedle(array $brand, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $searchFields = [
            'productName',
            'name',
            'brandName',
            'title',
            'brand',
            'displayName',
            'productTitle',
            'merchantName',
            'storeName',
            'vendorName',
        ];

        foreach ($searchFields as $field) {
            $value = strtolower(trim((string) ($brand[$field] ?? '')));
            if ($value !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Brands for one Phaze API page plus pagination hints from the API response (when present).
     *
     * @return array{brands: array, total: int|null, last_page: int|null, per_page: int|null}
     */
    public function getGiftBrandsPagePayload(string $country = 'USA', int $currentPage = 1, int $pageSize = 20): array
    {
        // pageSize is reserved for callers; Phaze catalog uses currentPage only.
        // (Passing pageSize broke some environments and cached empty pages.)
        $cacheKey = "phaze_brands_v4_{$country}_page_{$currentPage}";

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($country, $currentPage, $pageSize) {
            try {
                $apiKey = $this->getApiKey();
                if (empty($apiKey)) {
                    return [
                        'brands' => [],
                        'total' => null,
                        'last_page' => null,
                        'per_page' => $pageSize,
                    ];
                }

                // Phaze paginates with currentPage only.
                $endpointPath = '/brands/country/'.urlencode($country);
                $endpoint = $endpointPath.'?currentPage='.$currentPage;

                // Generate signature
                $apiSecret = $this->getApiSecret();
                $signature = null;
                if (! empty($apiSecret)) {
                    $signature = $this->generateSignature('GET', $endpoint, null);
                }

                // Prepare headers
                $headers = [
                    'API-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ];

                if ($signature) {
                    $headers['Signature'] = $signature;
                }

                // Make request with cURL
                $response = $this->makeCurlRequest('GET', $endpoint, $headers);

                if (! $response) {
                    // Try without signature as fallback
                    if ($signature) {
                        unset($headers['Signature']);
                        $response = $this->makeCurlRequest('GET', $endpoint, $headers);
                    }
                }

                // Check for API errors
                if (! $response || ! is_array($response)) {
                    Log::warning('Phaze API brands request failed or returned invalid response', [
                        'country' => $country,
                        'currentPage' => $currentPage,
                        'endpoint' => $endpoint,
                        'response_type' => gettype($response),
                    ]);

                    return [
                        'brands' => [],
                        'total' => null,
                        'last_page' => null,
                        'per_page' => null,
                    ];
                }

                // Check if response contains an error (do not treat a soft "message" alone as failure —
                // some Phaze success payloads include message text alongside brands).
                if (
                    (isset($response['error']) && $response['error']) ||
                    (isset($response['httpStatusCode']) && (int) $response['httpStatusCode'] >= 400)
                ) {
                    Log::warning('Phaze API brands request returned error', [
                        'country' => $country,
                        'currentPage' => $currentPage,
                        'error' => $response['error'] ?? $response['message'] ?? 'Unknown error',
                        'http_status' => $response['httpStatusCode'] ?? null,
                    ]);

                    return [
                        'brands' => [],
                        'total' => null,
                        'last_page' => null,
                        'per_page' => null,
                    ];
                }

                // Extract brands from response
                if (isset($response['brands']) && is_array($response['brands'])) {
                    // Ensure all brands have productName for display
                    $brands = array_map(function ($brand) {
                        // Try multiple possible field names for brand name
                        $brandName = null;

                        // Helper function to check if a value looks like a productId
                        $isProductId = function ($value) {
                            if (empty($value)) {
                                return true;
                            }
                            $value = trim($value);
                            // ProductIds are usually long numbers (10+ digits)
                            // Brand names usually have letters or are shorter
                            if (is_numeric($value) && strlen($value) >= 10) {
                                return true; // Likely a productId
                            }
                            // Check if it's all digits
                            if (preg_match('/^[0-9]+$/', $value) && strlen($value) >= 8) {
                                return true; // Likely a productId
                            }
                            // Check if it starts with "Gift Card #" followed by numbers
                            if (preg_match('/^Gift Card #?[0-9]+$/', $value)) {
                                return true; // This is a generated name, not a real brand name
                            }

                            return false;
                        };

                        // Check common field names in order of preference
                        // Phaze API might use different field names
                        $possibleFields = [
                            'productName',
                            'name',
                            'brandName',
                            'title',
                            'brand',
                            'displayName',
                            'productTitle',
                            'merchantName',
                            'storeName',
                            'vendorName',
                        ];

                        foreach ($possibleFields as $field) {
                            if (isset($brand[$field]) && ! empty($brand[$field])) {
                                $value = trim($brand[$field]);
                                // Skip if it looks like a productId
                                if (! $isProductId($value)) {
                                    $brandName = $value;
                                    break;
                                }
                            }
                        }

                        // If we found a valid brand name, use it
                        if (isset($brandName) && $brandName && strlen($brandName) > 0) {
                            $brand['productName'] = $brandName;
                        } else {
                            // Log available fields for debugging (only once per cache cycle to avoid spam)
                            static $hasLoggedStructure = false;
                            if (! $hasLoggedStructure) {
                                Log::info('Phaze API brand structure - checking for name fields', [
                                    'productId' => $brand['productId'] ?? 'unknown',
                                    'available_fields' => array_keys($brand),
                                    'all_field_values' => $brand, // Full brand object for debugging
                                ]);
                                $hasLoggedStructure = true;
                            }
                            // Use a generic name instead of showing productId
                            $brand['productName'] = 'Gift Card';
                        }

                        // Ensure productId is preserved
                        if (! isset($brand['productId'])) {
                            Log::warning('Brand missing productId', [
                                'available_fields' => array_keys($brand),
                            ]);
                        }

                        return $brand;
                    }, $response['brands']);

                    $total = $response['totalBrands'] ?? $response['totalCount'] ?? $response['total'] ?? $response['numberOfBrands'] ?? null;
                    $lastPage = $response['lastPage'] ?? $response['totalPages'] ?? $response['total_pages'] ?? null;
                    $perPageHint = $response['perPage'] ?? $response['pageSize'] ?? $response['page_size'] ?? null;
                    if ($total !== null) {
                        $total = (int) $total;
                    }
                    if ($lastPage !== null) {
                        $lastPage = (int) $lastPage;
                    }
                    if ($perPageHint !== null) {
                        $perPageHint = (int) $perPageHint;
                    }

                    return [
                        'brands' => $brands,
                        'total' => $total,
                        'last_page' => $lastPage,
                        'per_page' => $perPageHint,
                    ];
                }

                // Log if response structure is unexpected
                Log::warning('Phaze API brands response missing brands array', [
                    'country' => $country,
                    'currentPage' => $currentPage,
                    'response_keys' => is_array($response) ? array_keys($response) : 'not_array',
                    'response_preview' => is_array($response) ? json_encode(array_slice($response, 0, 3)) : substr((string) $response, 0, 200),
                ]);

                return [
                    'brands' => [],
                    'total' => null,
                    'last_page' => null,
                    'per_page' => null,
                ];
            } catch (\Exception $e) {
                Log::error('Error fetching gift card brands from Phaze API: '.$e->getMessage(), [
                    'country' => $country,
                    'currentPage' => $currentPage,
                    'trace' => $e->getTraceAsString(),
                ]);

                return [
                    'brands' => [],
                    'total' => null,
                    'last_page' => null,
                    'per_page' => null,
                ];
            }
        });
    }

    /**
     * Clear cache for a specific country
     */
    public function clearBrandsCache(?string $country = null): void
    {
        $forgetCountryPages = function (string $countryCode): void {
            Cache::forget("phaze_brands_catalog_v1_{$countryCode}");
            for ($page = 1; $page <= 40; $page++) {
                Cache::forget("phaze_brands_v4_{$countryCode}_page_{$page}");
                Cache::forget("phaze_brands_v3_{$countryCode}_page_{$page}_size_12");
                Cache::forget("phaze_brands_v2_{$countryCode}_page_{$page}");
                Cache::forget("phaze_brands_{$countryCode}_page_{$page}");
            }
        };

        if ($country) {
            $forgetCountryPages($country);
        } else {
            foreach (['USA', 'Canada', 'UK', 'France', 'India', 'Italy', 'Japan'] as $countryCode) {
                $forgetCountryPages($countryCode);
            }
        }
    }
}

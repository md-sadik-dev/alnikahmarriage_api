<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected string $projectId;
    protected string $credentialsPath;
    protected string $defaultIcon;

    public function __construct()
    {
        $this->projectId = config('firebase.project_id', 'alnikah-marriage-notifications');
        
        $rawPath = config('firebase.credentials_path');
        if (empty($rawPath)) {
            $rawPath = storage_path('credentials/firebase-service-account.json');
        } elseif (!str_starts_with($rawPath, '/') && !str_contains($rawPath, ':')) {
            $rawPath = base_path($rawPath);
        }

        // Robust fallback checking
        if (!file_exists($rawPath)) {
            if (file_exists(storage_path('credentials/firebase-service-account.json'))) {
                $rawPath = storage_path('credentials/firebase-service-account.json');
            } elseif (file_exists(storage_path('credentials/firebase-service-account.json.json'))) {
                $rawPath = storage_path('credentials/firebase-service-account.json.json');
            } elseif (file_exists($rawPath . '.json')) {
                $rawPath = $rawPath . '.json';
            }
        }

        $this->credentialsPath = $rawPath;
        $this->defaultIcon = config('firebase.default_icon', 'images/logo.webp');
    }

    /**
     * Check if Firebase Service Account credentials are fully configured and accessible.
     */
    public function isConfigured(): bool
    {
        if (file_exists($this->credentialsPath) && is_readable($this->credentialsPath)) {
            $data = json_decode(file_get_contents($this->credentialsPath), true);
            return !empty($data['private_key']) && !empty($data['client_email']);
        }
        return false;
    }

    /**
     * Retrieve or generate a cached Google OAuth2 Access Token for FCM HTTP v1.
     */
    public function getAccessToken(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        return Cache::remember('fcm_oauth2_access_token', 3300, function () {
            return $this->generateAccessToken();
        });
    }

    /**
     * Generate OAuth2 token via Google OAuth2 token endpoint using signed JWT (RS256).
     */
    protected function generateAccessToken(): ?string
    {
        try {
            $credentials = json_decode(file_get_contents($this->credentialsPath), true);
            if (empty($credentials['client_email']) || empty($credentials['private_key'])) {
                Log::warning('[FCM] Invalid service account format: client_email or private_key missing.');
                return null;
            }

            $clientEmail = $credentials['client_email'];
            $privateKey = $credentials['private_key'];
            $now = time();

            // 1. JWT Header
            $header = [
                'alg' => 'RS256',
                'typ' => 'JWT',
            ];

            // 2. JWT Claims
            $claims = [
                'iss' => $clientEmail,
                'sub' => $clientEmail,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            ];

            $base64Header = $this->base64UrlEncode(json_encode($header));
            $base64Claims = $this->base64UrlEncode(json_encode($claims));
            $signingInput = "{$base64Header}.{$base64Claims}";

            // 3. OpenSSL RS256 signature
            $signature = '';
            $keyResource = openssl_pkey_get_private($privateKey);
            if (!$keyResource) {
                Log::error('[FCM] Could not parse private key from service account credentials.');
                return null;
            }

            if (!openssl_sign($signingInput, $signature, $keyResource, OPENSSL_ALGO_SHA256)) {
                Log::error('[FCM] OpenSSL failed to sign JWT assertion: ' . openssl_error_string());
                return null;
            }

            $jwt = "{$signingInput}." . $this->base64UrlEncode($signature);

            // 4. Request OAuth2 Access Token from Google
            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['access_token'] ?? null;
            }

            Log::error('[FCM] Google OAuth2 token error: ' . $response->body());
            return null;
        } catch (\Throwable $e) {
            Log::error('[FCM] Exception while generating Google access token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send an FCM HTTP v1 push notification to a specific browser device token.
     *
     * @param string $token Device registration token
     * @param string $title Safe notification title
     * @param string $body Safe notification message body
     * @param string $actionUrl Internal website destination
     * @param array $extraData Additional payload metadata (no sensitive biodata)
     * @return array Result with status ('sent', 'invalid_token', 'failed', 'unconfigured')
     */
    public function sendToToken(string $token, string $title, string $body, string $actionUrl = '/dashboard', array $extraData = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'status' => 'unconfigured',
                'error' => 'Firebase service account credentials file is not yet placed in storage/credentials/firebase-service-account.json.',
            ];
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return [
                'success' => false,
                'status' => 'failed',
                'error' => 'Unable to generate Google OAuth2 token for Firebase Messaging.',
            ];
        }

        // Clean action URL: ensure it is a safe relative internal route
        $safeUrl = $this->sanitizeActionUrl($actionUrl);

        // Normalize string payload for data fields (FCM data values must be strings)
        $stringData = [
            'title' => (string) $title,
            'body' => (string) $body,
            'url' => (string) $safeUrl,
            'action_url' => (string) $safeUrl,
        ];
        foreach ($extraData as $k => $v) {
            if (is_scalar($v) || is_null($v)) {
                $stringData[(string) $k] = (string) $v;
            } else {
                $stringData[(string) $k] = json_encode($v);
            }
        }

        $messagePayload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $stringData,
                'webpush' => [
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'icon' => $this->defaultIcon,
                        'badge' => $this->defaultIcon,
                    ],
                    'fcm_options' => [
                        'link' => $safeUrl,
                    ],
                ],
            ],
        ];

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        try {
            $response = Http::withToken($accessToken)
                ->timeout(12)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($endpoint, $messagePayload);

            if ($response->successful()) {
                $resData = $response->json();
                $messageId = $resData['name'] ?? ('fcm_' . uniqid());

                // Update last_used_at on subscription
                PushSubscription::where('token', $token)->update(['last_used_at' => now()]);

                return [
                    'success' => true,
                    'status' => 'sent',
                    'message_id' => $messageId,
                ];
            }

            $statusCode = $response->status();
            $bodyContent = $response->body();
            $errorData = $response->json()['error'] ?? [];
            $errorDetails = $errorData['details'] ?? [];
            $errorCode = $errorData['status'] ?? '';

            // Detect expired or uninstalled device tokens
            $isInvalid = ($statusCode === 404)
                || ($errorCode === 'NOT_FOUND')
                || (str_contains($bodyContent, 'UNREGISTERED'))
                || (str_contains($bodyContent, 'registration-token-not-registered'));

            if ($isInvalid) {
                // Deactivate the invalid token to prevent future wasteful calls
                PushSubscription::where('token', $token)->update(['is_active' => false]);

                return [
                    'success' => false,
                    'status' => 'invalid_token',
                    'error' => 'FCM registration token is unregistered or expired. Token deactivated.',
                ];
            }

            Log::warning("[FCM] Delivery failed (HTTP {$statusCode}): {$bodyContent}");

            return [
                'success' => false,
                'status' => 'failed',
                'error' => $errorData['message'] ?? "FCM HTTP {$statusCode} response error",
            ];
        } catch (\Throwable $e) {
            Log::error("[FCM] Exception sending push to token: " . $e->getMessage());

            return [
                'success' => false,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sanitize and validate internal action URLs against open redirects.
     */
    public function sanitizeActionUrl(?string $url): string
    {
        if (empty($url)) {
            return 'dashboard';
        }

        $url = trim($url);

        // Disallow external absolute protocols
        if (preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, 'javascript:')) {
            return 'dashboard';
        }

        // Relative path normalized
        return ltrim($url, '/');
    }

    /**
     * Base64URL encoder helper for JWT.
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

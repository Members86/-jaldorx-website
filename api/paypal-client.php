<?php
declare(strict_types=1);

/**
 * JALDORX PayPal REST helper.
 * Used by the sandbox checkout endpoints.
 * Requires paypal-config.php one directory above the public web root on IONOS (never commit that file).
 */

function jdxPayPalConfig(): array
{
    $file = dirname(__DIR__) . '/paypal-config.php';
    if (!is_file($file)) {
        throw new RuntimeException('PAYPAL_CONFIG_MISSING');
    }

    $config = require $file;
    if (!is_array($config)) {
        throw new RuntimeException('PAYPAL_CONFIG_INVALID');
    }

    $mode = $config['mode'] ?? 'sandbox';
    if (!in_array($mode, ['sandbox', 'live'], true)) {
        throw new RuntimeException('PAYPAL_MODE_INVALID');
    }

    $clientId = trim((string)($config['client_id'] ?? ''));
    $secret = trim((string)($config['client_secret'] ?? ''));
    if ($clientId === '' || $secret === '' ||
        str_contains($clientId, '_HIER_EINTRAGEN') ||
        str_contains($secret, '_HIER_EINTRAGEN')) {
        throw new RuntimeException('PAYPAL_CREDENTIALS_MISSING');
    }

    return [
        'mode' => $mode,
        'client_id' => $clientId,
        'client_secret' => $secret,
        'base_url' => $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com',
    ];
}

/**
 * Makes an authenticated PayPal REST request.
 * Never return or log the access token or client secret.
 */
function jdxPayPalRequest(string $method, string $path, ?array $payload = null): array
{
    $method = strtoupper(trim($method));
    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new RuntimeException('PAYPAL_METHOD_INVALID');
    }
    if (!preg_match('#^/v2/checkout/orders(?:/[A-Za-z0-9-]+(?:/(?:capture|authorize))?)?$#', $path)) {
        throw new RuntimeException('PAYPAL_PATH_INVALID');
    }
    if ($method === 'GET' && $payload !== null) {
        throw new RuntimeException('PAYPAL_PAYLOAD_INVALID');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PAYPAL_CURL_UNAVAILABLE');
    }

    $config = jdxPayPalConfig();
    $tokenHandle = curl_init($config['base_url'] . '/v1/oauth2/token');
    curl_setopt_array($tokenHandle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $config['client_id'] . ':' . $config['client_secret'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: de_DE'],
    ]);

    $tokenBody = curl_exec($tokenHandle);
    $tokenStatus = (int)curl_getinfo($tokenHandle, CURLINFO_HTTP_CODE);
    $tokenError = curl_errno($tokenHandle);
    curl_close($tokenHandle);

    if ($tokenBody === false || $tokenError !== 0 || $tokenStatus < 200 || $tokenStatus >= 300) {
        throw new RuntimeException('PAYPAL_AUTH_FAILED');
    }

    $tokenData = json_decode((string)$tokenBody, true);
    $accessToken = is_array($tokenData) ? (string)($tokenData['access_token'] ?? '') : '';
    if ($accessToken === '') {
        throw new RuntimeException('PAYPAL_AUTH_FAILED');
    }

    $url = $config['base_url'] . $path;
    $handle = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            curl_close($handle);
            throw new RuntimeException('PAYPAL_PAYLOAD_INVALID');
        }
        $options[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_errno($handle);
    curl_close($handle);

    if ($body === false || $error !== 0) {
        throw new RuntimeException('PAYPAL_REQUEST_FAILED');
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded) || $status < 200 || $status >= 300) {
        throw new RuntimeException('PAYPAL_REQUEST_REJECTED');
    }

    return $decoded;
}

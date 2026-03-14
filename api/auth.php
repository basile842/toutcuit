<?php
require_once __DIR__ . '/config.php';

// JWT HS256 implementation — no external library needed

function base64urlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64urlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwtCreate(array $payload): string {
    $header = base64urlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_LIFETIME;
    $payloadEncoded = base64urlEncode(json_encode($payload));
    $signature = base64urlEncode(
        hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
    );
    return "$header.$payloadEncoded.$signature";
}

function jwtValidate(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;

    // Verify signature
    $expected = base64urlEncode(
        hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
    );
    if (!hash_equals($expected, $signature)) return null;

    // Decode payload
    $data = json_decode(base64urlDecode($payload), true);
    if (!is_array($data)) return null;

    // Check expiration
    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

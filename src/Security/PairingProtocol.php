<?php declare(strict_types=1);

namespace Devshot\Connector\Security;

final class PairingProtocol
{
    public const VERSION = 'DEVSHOT-SIGNATURE-V1';
    public const MAX_CLOCK_SKEW_SECONDS = 300;

    public static function encode(string $value): string
    {
        return sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public static function decode(string $value): string
    {
        try {
            return sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\Throwable) {
            return '';
        }
    }

    public static function contentDigest(string $body): string
    {
        return self::encode(hash('sha256', $body, true));
    }

    public static function canonicalRequest(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $contentDigest,
    ): string {
        return implode("\n", [
            self::VERSION,
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $contentDigest,
        ]);
    }

    /**
     * @param array{timestamp?: string, nonce?: string, contentDigest?: string, signature?: string} $headers
     */
    public static function verify(
        string $publicKey,
        string $method,
        string $path,
        string $body,
        array $headers,
        ?int $now = null,
    ): bool {
        $timestamp = $headers['timestamp'] ?? '';
        $nonce = $headers['nonce'] ?? '';
        $contentDigest = $headers['contentDigest'] ?? '';
        $signature = self::decode($headers['signature'] ?? '');
        $publicKeyBytes = self::decode($publicKey);
        $nonceBytes = self::decode($nonce);

        if (!preg_match('/^[0-9]{10}$/', $timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return false;
        }
        if (strlen($nonceBytes) < 16 || strlen($nonceBytes) > 64) {
            return false;
        }
        if (strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !hash_equals(self::contentDigest($body), $contentDigest)) {
            return false;
        }

        $canonical = self::canonicalRequest($method, $path, $timestamp, $nonce, $contentDigest);

        return sodium_crypto_sign_verify_detached($signature, $canonical, $publicKeyBytes);
    }

    public static function fingerprint(string $publicKey): string
    {
        $decoded = self::decode($publicKey);

        return strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ? hash('sha256', $decoded)
            : '';
    }

    public static function displayFingerprint(string $fingerprint): string
    {
        return strtoupper(implode(':', str_split($fingerprint, 4)));
    }
}

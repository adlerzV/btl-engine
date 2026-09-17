<?php
defined('ABSPATH') || exit;

final class BTL_Secure_Vault
{
    private static function key(): string
    {
        if (!defined('BTL_VAULT_KEY') || BTL_VAULT_KEY === '') {
            throw new RuntimeException('BTL_VAULT_KEY تعریف نشده.');
        }
        $raw = BTL_VAULT_KEY;
        if (str_starts_with($raw, 'base64:')) {
            $raw = base64_decode(substr($raw, 7), true);
            if ($raw === false) {
                throw new RuntimeException('طول BTL_VAULT_KEY نامعتبر است.');
            }
        }
        if (strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('طول BTL_VAULT_KEY نامعتبر است.');
        }
        return $raw;
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
    }

    public static function decrypt(string $payload): ?string
    {
        try {
            $raw = base64_decode($payload, true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
            return $plain === false ? null : $plain;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function fingerprint(string $plaintext): string
    {
        $key = sodium_crypto_generichash('btl-cdkey-fingerprint-v1', self::key(), 32);
        return sodium_crypto_generichash(trim($plaintext), $key, 32);
    }
}

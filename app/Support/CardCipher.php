<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Card number handling rules for the withdrawal form.
 *
 * Everything here is deliberately stateless: the number is normalised,
 * validated, fingerprinted and encrypted once, on the way into the database,
 * and the CVV never reaches this class at all. The client application can only
 * read `last4`; the CRM decrypts `pan()` for the payout.
 */
final class CardCipher
{
    /**
     * Cipher of the payload the CRM opens for the payout.
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * Version prefix of the shared-key payload, so a second format can be
     * introduced later without breaking the rows already written.
     */
    private const VERSION = 'v1';

    /**
     * Strip spaces and dashes, keep digits only.
     */
    public static function normalize(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }

    /**
     * Luhn checksum, the same test every payment terminal runs offline.
     */
    public static function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 12 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($index = $length - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Payment system detected from the issuer prefix (IIN).
     */
    public static function brand(string $digits): string
    {
        return match (true) {
            str_starts_with($digits, '4') => 'visa',
            preg_match('/^(5[1-5]|2[2-7])/', $digits) === 1 => 'mastercard',
            preg_match('/^220[0-4]/', $digits) === 1 => 'mir',
            default => 'unknown',
        };
    }

    /**
     * A card is valid while its month has not passed yet.
     */
    public static function isExpired(string $expiry): bool
    {
        if (preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $expiry, $matches) !== 1) {
            return true;
        }

        $year = 2000 + (int) $matches[2];
        $month = (int) $matches[1];

        // A card stays valid through the last day of its expiry month.
        $lastValidDay = now()->setDate($year, $month, 1)->endOfMonth()->startOfDay();

        return $lastValidDay->lessThan(now()->startOfDay());
    }

    /**
     * Bring "12 / 2027", "12-27" and "1227" to the "MM/YY" stored form.
     */
    public static function normalizeExpiry(string $expiry): string
    {
        $digits = self::normalize($expiry);

        if (strlen($digits) === 6) {
            $digits = substr($digits, 0, 2).substr($digits, 4, 2);
        }

        if (strlen($digits) !== 4) {
            return trim($expiry);
        }

        return substr($digits, 0, 2).'/'.substr($digits, 2, 2);
    }

    /**
     * Encrypt the number with the key both applications share.
     *
     * The CRM runs on its own `APP_KEY`, so a value encrypted with Laravel's
     * `Crypt` here could never be opened there. The number is therefore sealed
     * with `WITHDRAWAL_CARD_KEY` (AES-256-GCM, nonce and tag stored next to the
     * ciphertext) and only falls back to `Crypt` when that secret is not
     * configured, which keeps a single-application deployment working. GCM
     * authenticates the payload, so a tampered row fails to decrypt instead of
     * producing a wrong card number.
     */
    public static function encrypt(string $digits): string
    {
        $key = self::sharedKey();

        if ($key === null) {
            return Crypt::encryptString($digits);
        }

        $nonce = random_bytes(12);

        $tag = '';

        $ciphertext = openssl_encrypt($digits, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            return Crypt::encryptString($digits);
        }

        return self::VERSION.':'.base64_encode($nonce).':'.base64_encode($tag).':'.base64_encode($ciphertext);
    }

    /**
     * Decrypt the number, or null when the key does not match the row.
     *
     * A row written before the shared key existed is a `Crypt` payload and is
     * still readable here, so the rollout needs no data migration.
     */
    public static function decrypt(string $payload): ?string
    {
        if (str_starts_with($payload, self::VERSION.':')) {
            return self::decryptShared($payload);
        }

        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Open a `v1` payload, or null when the secret is missing or the tag does
     * not verify.
     */
    private static function decryptShared(string $payload): ?string
    {
        $key = self::sharedKey();

        if ($key === null) {
            return null;
        }

        $parts = explode(':', $payload);

        if (count($parts) !== 4) {
            return null;
        }

        [, $nonce, $tag, $ciphertext] = $parts;

        $nonce = base64_decode($nonce, true);

        $tag = base64_decode($tag, true);

        $ciphertext = base64_decode($ciphertext, true);

        if ($nonce === false || $tag === false || $ciphertext === false || strlen($nonce) !== 12 || strlen($tag) !== 16) {
            return null;
        }

        $digits = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);

        return $digits === false || $digits === '' ? null : $digits;
    }

    /**
     * The 32-byte key derived from the shared secret, or null when it is not
     * configured.
     */
    private static function sharedKey(): ?string
    {
        $secret = trim((string) config('trading.withdrawal.card_key', ''));

        return $secret === '' ? null : hash('sha256', $secret, true);
    }

    /**
     * Stable hash of the digits, used to reuse a card instead of duplicating it.
     *
     * The application key is mixed in, so the fingerprint cannot be reversed
     * with a precomputed table of card numbers.
     */
    public static function fingerprint(string $digits): string
    {
        return hash_hmac('sha256', $digits, (string) config('app.key'));
    }
}

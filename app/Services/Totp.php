<?php

namespace App\Services;

class Totp
{
    // RFC 6238, using the SHA-1 profile supported by authenticator apps.
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec($chunk)];
        }

        return $secret;
    }

    public static function matchingStep(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, int $timestamp): ?int
    {
        if (! preg_match('/^[A-Z2-7]{32}$/D', $secret) || ! preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }

        $bits = '';
        foreach (str_split($secret) as $letter) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $letter)), 5, '0', STR_PAD_LEFT);
        }
        $key = implode('', array_map(fn (string $byte) => chr(bindec($byte)), str_split($bits, 8)));
        $step = intdiv($timestamp, 30);
        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            $mac = hash_hmac('sha1', pack('N2', $candidate >> 32, $candidate & 0xFFFFFFFF), $key, true);
            $offset = ord($mac[19]) & 15;
            $value = unpack('N', substr($mac, $offset, 4))[1] & 0x7FFFFFFF;
            $expected = str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($expected, $code)) {
                return $candidate;
            }
        }

        return null;
    }
}

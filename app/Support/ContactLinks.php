<?php

declare(strict_types=1);

namespace App\Support;

final class ContactLinks
{
    private const AREA_CODES = '11 12 13 14 15 16 17 18 19 21 22 24 27 28 31 32 33 34 35 37 38 41 42 43 44 45 46 47 48 49 51 53 54 55 61 62 63 64 65 66 67 68 69 71 73 74 75 77 79 81 82 83 84 85 86 87 88 89 91 92 93 94 95 96 97 98 99';

    public static function phone(?string $value): ?string
    {
        $raw = trim($value ?? '');
        if (! preg_match('/^(\+)?[\d\s().-]+$/D', $raw)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (preg_match('/^(\d)\1+$/D', $digits)) {
            return null;
        }
        if (str_starts_with($raw, '+') && ! str_starts_with($digits, '55')) {
            return preg_match('/^[1-9]\d{7,14}$/D', $digits) ? $digits : null;
        }
        if (str_starts_with($raw, '+55') && ! in_array(strlen($digits), [12, 13], true)) {
            return null;
        }
        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        if (! in_array(substr($digits, 0, 2), explode(' ', self::AREA_CODES), true)) {
            return null;
        }

        return preg_match('/^(?:\d{2}[2-5]\d{7}|\d{2}9\d{8})$/D', $digits) ? "55{$digits}" : null;
    }

    public static function email(?string $value, string $subject = '', string $body = ''): ?string
    {
        $email = trim($value ?? '');
        if (strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            return null;
        }

        // Some mail programs read an encoded @ literally, so it stays as it is.
        return 'mailto:'.str_replace('%40', '@', rawurlencode($email)).'?subject='.rawurlencode($subject).'&body='.rawurlencode($body);
    }

    // A mailto link does nothing on a computer without a mail program, which
    // is common for people who only use Gmail in the browser.
    public static function gmail(?string $value, string $subject = '', string $body = ''): ?string
    {
        if (self::email($value) === null) {
            return null;
        }

        return 'https://mail.google.com/mail/?'.http_build_query([
            'view' => 'cm',
            'fs' => 1,
            'to' => trim($value),
            'su' => $subject,
            'body' => $body,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function whatsapp(?string $value, string $body = ''): ?string
    {
        $phone = self::phone($value);

        return $phone ? "https://wa.me/{$phone}?text=".rawurlencode($body) : null;
    }
}

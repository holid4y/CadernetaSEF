<?php

declare(strict_types=1);

namespace App\Support;

// HMAC of an encrypted value, for exact lookups without storing it in clear.
use App\Rules\BrazilianDocument;

final class BlindIndex
{
    public static function forDocument(?string $document): ?string
    {
        $characters = BrazilianDocument::characters((string) $document);

        if ($characters === '') {
            return null;
        }

        return hash_hmac('sha256', 'document:'.$characters, (string) config('app.key'));
    }
}

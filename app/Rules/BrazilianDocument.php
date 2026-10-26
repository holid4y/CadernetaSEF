<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Clients\Enums\ClientType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF for individuals, CNPJ for organizations, both with check digits.
 */
final class BrazilianDocument implements ValidationRule
{
    public function __construct(private ClientType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valid = match ($this->type) {
            ClientType::Individual => self::isCpf(preg_replace('/\D/', '', (string) $value)),
            ClientType::Organization => self::isCnpj(self::characters((string) $value)),
        };

        if (! $valid) {
            $fail(__("clients.errors.invalid_{$this->type->value}_document"));
        }
    }

    public static function isCpf(string $digits): bool
    {
        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        return self::checkDigits($digits, 9, fn (int $position, int $length): int => $length + 1 - $position)
            && self::checkDigits($digits, 10, fn (int $position, int $length): int => $length + 1 - $position);
    }

    // Since July 2026 the Receita also issues CNPJs with letters in the first
    // twelve places; the two check digits stay numeric.
    public static function isCnpj(string $cnpj): bool
    {
        if (! preg_match('/^[0-9A-Z]{12}\d{2}$/', $cnpj) || preg_match('/^(.)\1{13}$/', $cnpj)) {
            return false;
        }

        // Weights run 5..2 then 9..2 for the first digit, 6..2 then 9..2 for the second.
        $weight = fn (int $position, int $length): int => (($length - 1 - $position) % 8) + 2;

        return self::checkDigits($cnpj, 12, $weight) && self::checkDigits($cnpj, 13, $weight);
    }

    // Letters and digits only, in upper case: how a document is compared and indexed.
    public static function characters(string $document): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $document));
    }

    /**
     * @param  Closure(int, int): int  $weight  weight for the digit at a position among $length digits
     */
    private static function checkDigits(string $digits, int $length, Closure $weight): bool
    {
        $sum = 0;

        for ($position = 0; $position < $length; $position++) {
            // A letter is worth its ASCII code minus 48, which keeps every digit at its own value.
            $sum += (ord($digits[$position]) - 48) * $weight($position, $length);
        }

        $expected = $sum % 11 < 2 ? 0 : 11 - $sum % 11;

        return (int) $digits[$length] === $expected;
    }
}

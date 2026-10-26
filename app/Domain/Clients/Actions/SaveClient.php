<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Enums\ClientType;
use App\Models\Client;
use App\Rules\BrazilianDocument;
use App\Support\BlindIndex;
use App\Support\ContactLinks;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaveClient
{
    /**
     * @param  array{
     *     type: string,
     *     name: string,
     *     trade_name?: ?string,
     *     document?: ?string,
     *     phone?: ?string,
     *     email?: ?string,
     *     notes?: ?string,
     *     accepts_reminders?: bool,
     *     active?: bool,
     * }  $input
     */
    public function handle(?Client $client, array $input): Client
    {
        $data = Validator::make($input, self::rules($client, $input['type'] ?? null), self::messages())->validate();
        $organization = $data['type'] === ClientType::Organization->value;

        $client ??= new Client;
        $client->fill([
            'type' => $data['type'],
            'name' => $data['name'],
            'trade_name' => $organization ? (($data['trade_name'] ?? null) ?: null) : null,
            'document' => ($data['document'] ?? null) ?: null,
            'phone' => ($data['phone'] ?? null) ?: null,
            'email' => ($data['email'] ?? null) ?: null,
            'notes' => ($data['notes'] ?? null) ?: null,
            'accepts_reminders' => (bool) ($data['accepts_reminders'] ?? false),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        try {
            $client->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['document' => __('clients.errors.document_taken')]);
        }

        return $client;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(?Client $client, mixed $type): array
    {
        return [
            'type' => ['required', Rule::enum(ClientType::class)],
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'document' => [
                'bail', 'nullable', 'string', 'max:20',
                new BrazilianDocument((is_string($type) ? ClientType::tryFrom($type) : null) ?? ClientType::Individual),
                self::documentIsFree($client),
            ],
            'phone' => ['bail', 'nullable', 'string', 'max:30', 'required_without:email', self::phoneHasDigits(...)],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'accepts_reminders' => ['boolean'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'phone.required_without' => __('clients.errors.contact_required'),
        ];
    }

    private static function documentIsFree(?Client $client): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($client): void {
            $taken = Client::query()
                ->where('document_index', BlindIndex::forDocument((string) $value))
                ->when($client?->exists, fn ($query) => $query->whereKeyNot($client->id))
                ->exists();

            if ($taken) {
                $fail(__('clients.errors.document_taken'));
            }
        };
    }

    // Landline or mobile with area code, optionally with the country code.
    private static function phoneHasDigits(string $attribute, mixed $value, Closure $fail): void
    {
        if (ContactLinks::phone((string) $value) === null) {
            $fail(__('clients.errors.invalid_phone'));
        }
    }
}

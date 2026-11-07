<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Enums\ClientType;
use App\Models\Client;
use App\Rules\BrazilianDocument;
use App\Support\BlindIndex;
use App\Support\ContactLinks;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Brings clients from a spreadsheet the project already kept. Every row goes
 * through SaveClient, so validation, encryption and the CPF/CNPJ index are
 * the same as in the form; the preview runs the same checks without saving.
 */
final class ImportClients
{
    public const TARGETS = ['ignore', 'name', 'document', 'email', 'phone', 'trade_name', 'notes'];

    public const MAX_ROWS = 1000;

    private const NOTES_LIMIT = 2000;

    public function __construct(private SaveClient $saveClient) {}

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows  without the header
     * @param  array<int, string>  $mapping  column => target
     * @return array{imported: int, skipped: list<array{row: int, name: string, reason: string}>}
     */
    public function handle(array $headers, array $rows, array $mapping, ?string $areaCode): array
    {
        $imported = 0;
        $skipped = [];

        foreach ($this->check($headers, $rows, $mapping, $areaCode) as $line) {
            if ($line['problem'] !== null) {
                $skipped[] = ['row' => $line['row'], 'name' => $line['input']['name'], 'reason' => $line['problem']];

                continue;
            }

            // Someone may have registered the same client while the preview was open.
            try {
                $this->saveClient->handle(null, $line['input'], requireContact: false);
                $imported++;
            } catch (ValidationException $error) {
                $skipped[] = ['row' => $line['row'], 'name' => $line['input']['name'], 'reason' => collect($error->errors())->flatten()->first()];
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * What would happen to each row, without saving anything.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  array<int, string>  $mapping
     * @return list<array{row: int, input: array<string, mixed>, problem: ?string}>
     */
    public function check(array $headers, array $rows, array $mapping, ?string $areaCode): array
    {
        $inputs = array_map(fn (array $row): array => self::buildInput($headers, $row, $mapping, $areaCode), $rows);
        $takenDocuments = self::takenDocuments($inputs);
        $takenNames = self::takenNames($inputs);
        $seen = [];
        $lines = [];

        foreach ($inputs as $position => $input) {
            if ($input['name'] === '' && $input['document'] === null && $input['email'] === null && $input['phone'] === null) {
                continue;
            }

            $key = $input['document'] === null ? 'name:'.mb_strtolower($input['name']) : 'document:'.$input['document'];
            $problem = self::validationProblem($input) ?? match (true) {
                isset($seen[$key]) => __('clients.import.reason_duplicate_in_file'),
                $input['document'] !== null && isset($takenDocuments[BlindIndex::forDocument($input['document'])]) => __('clients.errors.document_taken'),
                $input['document'] === null && isset($takenNames[mb_strtolower($input['name'])]) => __('clients.import.reason_name_taken'),
                default => null,
            };

            $seen[$key] = true;
            $lines[] = ['row' => $position + 2, 'input' => $input, 'problem' => $problem];
        }

        return $lines;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $row
     * @param  array<int, string>  $mapping
     * @return array{type: string, name: string, trade_name: ?string, document: ?string, phone: ?string, email: ?string, notes: ?string, accepts_reminders: bool, active: bool}
     */
    public static function buildInput(array $headers, array $row, array $mapping, ?string $areaCode): array
    {
        $values = [];

        foreach ($mapping as $column => $target) {
            $value = self::clean($row[$column] ?? '');

            if ($value !== '' && $target !== 'ignore' && in_array($target, self::TARGETS, true)) {
                $values[$target][] = [trim($headers[$column] ?? '') ?: 'Coluna '.($column + 1), $value, $column];
            }
        }

        $document = self::document($values['document'][0][1] ?? '');
        $email = self::firstValid($values['email'] ?? [], fn (string $value): ?string => filter_var(mb_strtolower($value), FILTER_VALIDATE_EMAIL) ?: null);
        $phone = self::firstValid($values['phone'] ?? [], fn (string $value): ?string => self::phone($value, $areaCode));

        // Second e-mails, phones that could not be read and address columns are
        // kept as notes, in the order of the spreadsheet, instead of being lost.
        $extras = [
            ...array_slice($values['name'] ?? [], 1),
            ...array_slice($values['document'] ?? [], 1),
            ...array_slice($values['trade_name'] ?? [], 1),
            ...array_filter($values['email'] ?? [], fn (array $pair): bool => $email === null || mb_strtolower($pair[1]) !== $email),
            ...array_filter($values['phone'] ?? [], fn (array $pair): bool => $phone === null || self::phone($pair[1], $areaCode) !== $phone),
            ...$values['notes'] ?? [],
        ];
        usort($extras, fn (array $a, array $b): int => $a[2] <=> $b[2]);
        $notes = implode("\n", array_map(fn (array $pair): string => "{$pair[0]}: {$pair[1]}", $extras));

        return [
            'type' => strlen(BrazilianDocument::characters((string) $document)) > 11 ? ClientType::Organization->value : ClientType::Individual->value,
            'name' => $values['name'][0][1] ?? '',
            'trade_name' => $values['trade_name'][0][1] ?? null,
            'document' => $document,
            'phone' => $phone,
            'email' => $email,
            'notes' => $notes === '' ? null : mb_substr($notes, 0, self::NOTES_LIMIT),
            'accepts_reminders' => false,
            'active' => true,
        ];
    }

    /**
     * Guesses the mapping from the headers, including the ones the project
     * already uses (CPF, BENEFICIADO, E-MAIL, ENDEREÇO...). A second name or
     * document column goes to the notes; extra e-mails and phones are sorted
     * out row by row.
     *
     * @param  list<string>  $headers
     * @return array<int, string>
     */
    public static function guessMapping(array $headers): array
    {
        $mapping = [];

        foreach ($headers as $column => $header) {
            $plain = str(trim($header))->lower()->ascii()->toString();
            $target = match (true) {
                $plain === '' => 'ignore',
                str_contains($plain, 'cpf') || str_contains($plain, 'cnpj') => 'document',
                str_contains($plain, 'fantasia') => 'trade_name',
                str_contains($plain, 'beneficiado') || str_contains($plain, 'razao') || str_contains($plain, 'nome') => 'name',
                str_contains($plain, 'mail') => 'email',
                str_contains($plain, 'fone') || str_contains($plain, 'celular') || str_contains($plain, 'whats') => 'phone',
                in_array($plain, ['n', 'id', '#', 'item'], true) => 'ignore',
                default => 'notes',
            };

            $repeatable = in_array($target, ['notes', 'ignore', 'email', 'phone'], true);
            $mapping[$column] = $repeatable || ! in_array($target, $mapping, true) ? $target : 'notes';
        }

        return $mapping;
    }

    /**
     * Same rules as the form, except that the contact may be missing and the
     * duplicate check, which is done for all rows at once.
     *
     * @param  array<string, mixed>  $input
     */
    private static function validationProblem(array $input): ?string
    {
        $rules = SaveClient::rules(null, $input['type'], requireContact: false);
        $rules['document'] = array_values(array_filter($rules['document'], fn (mixed $rule): bool => ! $rule instanceof Closure));
        $validator = Validator::make($input, $rules, SaveClient::messages());

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return array<string, true>
     */
    private static function takenDocuments(array $inputs): array
    {
        $indexes = array_map(fn (string $document): string => BlindIndex::forDocument($document), array_filter(array_column($inputs, 'document')));

        return Client::query()->whereIn('document_index', $indexes)->pluck('document_index')->flip()->map(fn (): bool => true)->all();
    }

    /**
     * Rows without CPF/CNPJ can only be told apart by the name.
     *
     * @param  list<array<string, mixed>>  $inputs
     * @return array<string, true>
     */
    private static function takenNames(array $inputs): array
    {
        $names = array_map(fn (array $input): string => mb_strtolower($input['name']), array_filter($inputs, fn (array $input): bool => $input['document'] === null));

        return Client::query()->whereNull('document_index')->whereIn(DB::raw('lower(name)'), $names)->pluck('name')
            ->mapWithKeys(fn (string $name): array => [mb_strtolower($name) => true])->all();
    }

    // Cells such as "-" or "n/a" stand for an empty value.
    private static function clean(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return preg_match('/[\p{L}\p{N}]/u', $value) && ! in_array(mb_strtolower($value), ['n/a', 'na', 'nao tem', 'não tem'], true) ? $value : '';
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $pairs
     * @param  Closure(string): ?string  $normalize
     */
    private static function firstValid(array $pairs, Closure $normalize): ?string
    {
        foreach ($pairs as [, $value]) {
            $normalized = $normalize($value);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    // Spreadsheets keep CPF and CNPJ as numbers, which drops the leading zeros.
    private static function document(string $value): ?string
    {
        $characters = BrazilianDocument::characters($value);
        $length = strlen($characters);
        $cpf = $length >= 9 && $length <= 11 ? str_pad($characters, 11, '0', STR_PAD_LEFT) : '';
        $cnpj = $length >= 12 && $length <= 14 ? str_pad($characters, 14, '0', STR_PAD_LEFT) : '';

        return match (true) {
            $characters === '' => null,
            BrazilianDocument::isCpf($cpf) => vsprintf('%s.%s.%s-%s', str_split($cpf, 3)),
            BrazilianDocument::isCnpj($cnpj) => sprintf('%s.%s.%s/%s-%s', substr($cnpj, 0, 2), substr($cnpj, 2, 3), substr($cnpj, 5, 3), substr($cnpj, 8, 4), substr($cnpj, 12)),
            default => $characters,
        };
    }

    /**
     * Local numbers get the area code the person chose. Mobile numbers gained
     * a ninth digit in 2016, so one written the old way (9671-8762) gets it;
     * any other number with a digit missing is a typo and stays in the notes.
     */
    private static function phone(string $value, ?string $areaCode): ?string
    {
        $oldMobile = preg_match('/(^|\D)[6-9]\d{3}-?\d{4}$/', $value) === 1;
        $digits = (string) preg_replace('/\D/', '', $value);
        $digits = strlen($digits) > 11 && str_starts_with($digits, '55') ? substr($digits, 2) : $digits;
        $digits = in_array(strlen($digits), [8, 9], true) ? $areaCode.$digits : $digits;
        $digits = $oldMobile && strlen($digits) === 10 ? substr($digits, 0, 2).'9'.substr($digits, 2) : $digits;

        if (ContactLinks::phone($digits) === null) {
            return null;
        }

        return strlen($digits) === 11
            ? sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7))
            : sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DisplayTimezone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class FormAppointmentsRequest extends FormRequest
{
    private const MAX_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    // A query string can carry lists (from[]=...), which the date rules can not read.
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(fn (mixed $value): mixed => is_string($value) ? $value : null, $this->only('from', 'to', 'page')));
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->from()->diffInDays($this->to()) > self::MAX_DAYS) {
                $validator->errors()->add('to', 'Peça no máximo '.self::MAX_DAYS.' dias por vez.');
            }
        }];
    }

    // Days are São Paulo days, from the start of "from" to the end of "to".
    public function from(): Carbon
    {
        return DisplayTimezone::toUtc($this->validated('from').' 00:00');
    }

    public function to(): Carbon
    {
        return DisplayTimezone::toUtc($this->validated('to').' 00:00')->addDay();
    }
}

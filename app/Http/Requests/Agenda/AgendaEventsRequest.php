<?php

declare(strict_types=1);

namespace App\Http\Requests\Agenda;

use App\Support\DisplayTimezone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

// The calendar runs in "UTC" over São Paulo wall times, so the "Z" it sends
// is not a real offset: the values are local time.
class AgendaEventsRequest extends FormRequest
{
    // Longest view is a month plus the days of the weeks around it.
    private const MAX_RANGE_DAYS = 45;

    // A query string can carry lists (start[]=...), which the date rules can not read.
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(fn (mixed $value): mixed => is_string($value) ? $value : null, $this->only('start', 'end')));
    }

    public function rules(): array
    {
        return [
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'responsible' => ['nullable', 'integer', 'exists:users,id'],
            'cancelled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->from()->diffInDays($this->to()) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('end', __('agenda.range_too_long'));
            }
        }];
    }

    public function from(): Carbon
    {
        return self::wallTimeToUtc($this->string('start')->toString());
    }

    public function to(): Carbon
    {
        return self::wallTimeToUtc($this->string('end')->toString());
    }

    public function responsibleId(): ?int
    {
        return $this->integer('responsible') ?: null;
    }

    private static function wallTimeToUtc(string $value): Carbon
    {
        return DisplayTimezone::toUtc(Carbon::parse($value)->format('Y-m-d H:i:s'));
    }
}

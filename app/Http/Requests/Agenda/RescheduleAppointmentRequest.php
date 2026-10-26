<?php

declare(strict_types=1);

namespace App\Http\Requests\Agenda;

use App\Support\DisplayTimezone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('appointment'));
    }

    // A body can carry lists where dates belong, which the date rules can not read.
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(fn (mixed $value): mixed => is_string($value) ? $value : null, $this->only('starts_at', 'ends_at')));
    }

    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i:s', 'after_or_equal:-1 year', 'before_or_equal:+2 years'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i:s', 'after:starts_at'],
        ];
    }

    public function startsAt(): Carbon
    {
        return DisplayTimezone::toUtc($this->string('starts_at')->toString());
    }

    public function endsAt(): Carbon
    {
        return DisplayTimezone::toUtc($this->string('ends_at')->toString());
    }
}

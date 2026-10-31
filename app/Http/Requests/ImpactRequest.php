<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\DisplayTimezone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ImpactRequest extends FormRequest
{
    public const PERIODS = [
        'mes' => 'Este mês',
        'trimestre' => '3 meses',
        'semestre' => '6 meses',
        'datas' => 'Datas',
    ];

    private const MAX_YEARS = 5;

    // A query string can carry lists (de[]=...), which the date rules can not read.
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(fn (mixed $value): mixed => is_string($value) ? $value : null, $this->only('periodo', 'de', 'ate')));
    }

    public function rules(): array
    {
        return [
            'periodo' => ['nullable', Rule::in(array_keys(self::PERIODS))],
            'de' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2020-01-01'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            [$from, $to] = $validator->errors()->isEmpty() ? $this->range() : [null, null];

            if ($from !== null && $from->diffInYears($to) > self::MAX_YEARS) {
                $validator->errors()->add('ate', 'Escolha no máximo '.self::MAX_YEARS.' anos.');
            }
        }];
    }

    public function period(): string
    {
        return $this->input('periodo') ?: 'trimestre';
    }

    /**
     * Start (inclusive) and end (exclusive) in UTC, counted in São Paulo days.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(): array
    {
        $today = DisplayTimezone::toLocal(now())->startOfDay();
        $month = $today->copy()->startOfMonth();
        $tomorrow = $today->copy()->addDay();

        [$from, $to] = match ($this->period()) {
            'mes' => [$month, $month->copy()->addMonth()],
            'semestre' => [$month->copy()->subMonths(5), $tomorrow],
            'datas' => [
                $this->input('de') ? Carbon::parse($this->input('de'), DisplayTimezone::name()) : $month->copy()->subMonths(2),
                $this->input('ate') ? Carbon::parse($this->input('ate'), DisplayTimezone::name())->addDay() : $tomorrow,
            ],
            default => [$month->copy()->subMonths(2), $tomorrow],
        };

        return [$from->utc(), $to->utc()];
    }
}

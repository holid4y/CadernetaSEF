<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\FormLinks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FormEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'form_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
            'appointment_id' => ['nullable', 'uuid'],
            'version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'status' => ['required', Rule::in(['pending', 'in_progress', 'completed', 'deleted'])],
            'title' => ['required', 'string', 'max:160', 'not_regex:/[\x00-\x1f\x7f]/'],
            'url' => ['bail', 'nullable', 'required_unless:status,deleted', 'string', 'max:2048', function ($attribute, $value, $fail): void {
                if (! FormLinks::allowed($value)) {
                    $fail('O link precisa usar HTTPS em um domínio autorizado.');
                }
            }],
            'occurred_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'Envie apenas os metadados previstos no contrato.');
            }
        }];
    }

    public function payload(): array
    {
        $data = $this->validated();

        return [
            'event_id' => strtolower($data['event_id']),
            'form_id' => $data['form_id'],
            'appointment_id' => isset($data['appointment_id']) ? strtolower($data['appointment_id']) : null,
            'version' => (int) $data['version'],
            'status' => $data['status'],
            'title' => $data['title'],
            'url' => $data['status'] === 'deleted' ? null : $data['url'],
            'occurred_at' => CarbonImmutable::parse($data['occurred_at'])->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}

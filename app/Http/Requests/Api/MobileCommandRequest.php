<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MobileCommandRequest extends FormRequest
{
    public const COMMANDS = [
        'saveClient',
        'scheduleAppointment',
        'rescheduleAppointment',
        'changeStatus',
        'toggleDocument',
        'sendReminder',
        'restoreAppointment',
        'setServiceActive',
        'addServiceDocument',
        'removeServiceDocument',
        'dismissGoogleEvent',
    ];

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', Rule::in(self::COMMANDS)],
            'args' => ['present', 'list', 'max:3'],
        ];
    }

    public function command(): string
    {
        return $this->validated('name');
    }

    /**
     * @return list<mixed>
     */
    public function arguments(): array
    {
        return $this->validated('args');
    }
}

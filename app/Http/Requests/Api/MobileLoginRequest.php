<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Auth\LoginRequest;

class MobileLoginRequest extends LoginRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}

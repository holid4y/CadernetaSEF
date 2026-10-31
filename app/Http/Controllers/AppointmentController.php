<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function create(Request $request): View
    {
        return view('appointments.create', [
            'start' => $this->wallTime($request, 'inicio'),
            'end' => $this->wallTime($request, 'fim'),
            'clientId' => $request->integer('atendido') ?: null,
            'googleEventId' => $request->integer('google') ?: null,
        ]);
    }

    public function show(Appointment $appointment): View
    {
        return view('appointments.show', ['appointment' => $appointment]);
    }

    /**
     * The agenda links here with the slot that was clicked. A mangled link
     * opens the form on the default hour instead of failing.
     */
    private function wallTime(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value) || Validator::make([$key => $value], [$key => 'date_format:Y-m-d\TH:i:s'])->fails()) {
            return null;
        }

        return $value;
    }
}

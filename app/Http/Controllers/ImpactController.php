<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Appointments\Queries\ImpactIndicators;
use App\Domain\Appointments\Queries\ImpactSpreadsheet;
use App\Http\Requests\ImpactRequest;
use App\Support\DisplayTimezone;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ImpactController extends Controller
{
    public function index(ImpactRequest $request): View
    {
        [$from, $to] = $request->range();

        return view('impact.index', [
            'data' => ImpactIndicators::between($from, $to, $request->clientType()),
            'period' => $request->period(),
            'type' => $request->clientType(),
            'from' => DisplayTimezone::toLocal($from),
            'until' => DisplayTimezone::toLocal($to)->subDay(),
        ]);
    }

    public function export(ImpactRequest $request): Response
    {
        [$from, $to] = $request->range();
        $prefix = $request->input('tipo') ? $request->input('tipo').'-' : '';
        $name = sprintf('impacto-%s%s-a-%s.csv', $prefix, DisplayTimezone::toLocal($from)->format('Y-m-d'), DisplayTimezone::toLocal($to)->subDay()->format('Y-m-d'));

        return response(ImpactSpreadsheet::between($from, $to, $request->clientType()), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }
}

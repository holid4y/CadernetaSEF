<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Queries\ImpactIndicators;
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
            'data' => ImpactIndicators::between($from, $to),
            'period' => $request->period(),
            'from' => DisplayTimezone::toLocal($from),
            'until' => DisplayTimezone::toLocal($to)->subDay(),
        ]);
    }

    public function export(ImpactRequest $request): Response
    {
        [$from, $to] = $request->range();
        $data = ImpactIndicators::between($from, $to);
        $first = DisplayTimezone::toLocal($from);
        $last = DisplayTimezone::toLocal($to)->subDay();
        $rate = $data['attendance_rate'] === null ? '' : round($data['attendance_rate'] * 100).'%';

        $rows = [
            ['Indicador', 'Valor'],
            ['Período', $first->format('d/m/Y').' a '.$last->format('d/m/Y')],
            ['Total de atendimentos', $data['total']],
            ...array_map(fn (AppointmentStatus $status): array => [$status->label(), $data['status'][$status->value]], AppointmentStatus::cases()),
            ['Taxa de comparecimento', $rate],
            ['Pessoas atendidas (únicas)', $data['unique_clients']],
            ['Pessoa física', $data['individuals']],
            ['Pessoa jurídica', $data['organizations']],
            ['MEI', $data['mei']],
            ['ONGs e terceiro setor', $data['third_sector']],
            [],
            ['Demandas mais frequentes', 'Atendimentos'],
            ...array_map(fn (array $demand): array => [$demand['name'], $demand['count']], array_slice($data['demands'], 0, 50)),
            [],
            ['Responsável', 'Atendimentos'],
            ...array_map(fn (array $person): array => [$person['name'], $person['count']], $data['by_responsible']),
            [],
            ['Mês', 'Pessoas atendidas'],
            ...array_map(fn (array $month): array => [$month['key'], $month['count']], $data['people_by_month']),
        ];

        // The BOM makes Excel read the accents; the separator is the Brazilian one.
        $csv = "\u{FEFF}".implode("\r\n", array_map(fn (array $row): string => implode(';', array_map(self::cell(...), $row)), $rows));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="impacto-%s-a-%s.csv"', $first->format('Y-m-d'), $last->format('Y-m-d')),
        ]);
    }

    // A cell starting with = + - @ would run as a formula in a spreadsheet.
    private static function cell(mixed $value): string
    {
        $text = (string) $value;
        $safe = preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'{$text}" : $text;

        return '"'.str_replace('"', '""', $safe).'"';
    }
}

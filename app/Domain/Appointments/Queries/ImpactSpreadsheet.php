<?php

declare(strict_types=1);

namespace App\Domain\Appointments\Queries;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Clients\Enums\ClientType;
use App\Support\DisplayTimezone;
use Carbon\CarbonInterface;

// The Impact page as a CSV that opens straight in a Brazilian Excel.
final class ImpactSpreadsheet
{
    public static function between(CarbonInterface $from, CarbonInterface $to, ?ClientType $type = null): string
    {
        $data = ImpactIndicators::between($from, $to, $type);
        $first = DisplayTimezone::toLocal($from);
        $last = DisplayTimezone::toLocal($to)->subDay();

        $rows = [
            ['Indicador', 'Valor'],
            ['Período', $first->format('d/m/Y').' a '.$last->format('d/m/Y')],
            ['Atendidos', $type?->label() ?? 'Todos'],
            ['Total de atendimentos', $data['total']],
            ...array_map(fn (AppointmentStatus $status): array => [$status->label(), $data['status'][$status->value]], AppointmentStatus::cases()),
            ['Taxa de comparecimento', $data['attendance_rate'] === null ? '' : round($data['attendance_rate'] * 100).'%'],
            ['Pessoas atendidas (únicas)', $data['unique_clients']],
            ['Pessoa física', $data['individuals']],
            ['Pessoa jurídica', $data['organizations']],
            ['ONG', $data['ngos']],
            ['MEI', $data['mei']],
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
        return "\u{FEFF}".implode("\r\n", array_map(fn (array $row): string => implode(';', array_map(self::cell(...), $row)), $rows));
    }

    // A cell starting with = + - @ would run as a formula in a spreadsheet.
    private static function cell(mixed $value): string
    {
        $text = (string) $value;
        $safe = preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'{$text}" : $text;

        return '"'.str_replace('"', '""', $safe).'"';
    }
}

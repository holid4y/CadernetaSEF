<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Appointments\Actions\ChangeAppointmentStatus;
use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\LocationType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Support\DisplayTimezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

class DemoAgendaSeeder extends Seeder
{
    private const CLIENTS = [
        ['individual', 'Joana Lima', null, null, '(51) 99812-4410', 'joana.lima@example.com'],
        ['individual', 'Carlos Eduardo Souza', null, null, '(51) 99701-2280', null],
        ['organization', 'Teresa Alves Doces', 'Doces da Vó Teresa', '45.123.987/0001-70', '(51) 98455-0192', 'contato@docesdavo.example.com'],
        ['ngo', 'Associação Comunitária Vila Nova', 'ACVN', null, '(51) 3595-2211', 'acvn@example.org'],
        ['individual', 'Marcos Pereira', null, null, '(51) 99633-8814', 'marcos.p@example.com'],
        ['organization', 'Oficina do Beto Reparos', 'Oficina do Beto', null, '(51) 98122-7765', null],
        ['individual', 'Luciana Ferreira', null, null, '(51) 99277-3301', 'lu.ferreira@example.com'],
        ['ngo', 'Instituto Mãos que Ajudam', 'Mãos que Ajudam', null, '(51) 3587-4400', 'contato@maos.example.org'],
    ];

    /**
     * [day offset from this Monday, local start, minutes, client, service slug, responsible, status]
     */
    private const AGENDA = [
        [-5, '09:00', 60, 0, 'irpf', 'equipe', AppointmentStatus::Completed],
        [-4, '14:00', 90, 3, 'prestacao-de-contas-para-entidades-do-terceiro-setor', 'admin', AppointmentStatus::Completed],
        [-3, '10:00', 60, 2, 'atendimento-mei', 'equipe', AppointmentStatus::NoShow],
        [0, '09:00', 60, 4, 'orcamento-familiar-e-pessoal', 'equipe', AppointmentStatus::Completed],
        [0, '14:00', 60, 5, 'formacao-do-preco-de-venda', 'admin', AppointmentStatus::Confirmed],
        [1, '10:30', 60, 0, 'declaracao-de-imposto-de-renda', 'equipe', AppointmentStatus::Confirmed],
        [1, '19:30', 90, 7, 'elaboracao-de-demonstracoes-contabeis', 'admin', AppointmentStatus::Scheduled],
        [2, '09:00', 60, 2, 'atendimento-mei', 'equipe', AppointmentStatus::Scheduled],
        [2, '09:00', 60, 1, 'emissao-de-guias-para-pagamento-de-tributos', 'admin', AppointmentStatus::Scheduled],
        [3, '15:00', 60, 6, 'fluxo-de-caixa', 'equipe', AppointmentStatus::Scheduled],
        [4, '13:30', 180, 2, 'atendimento-externo', 'admin', AppointmentStatus::Confirmed],
        [5, '09:30', 60, 1, 'irpf', 'equipe', AppointmentStatus::Scheduled],
        [7, '10:00', 60, 3, 'prestacao-de-contas-para-entidades-do-terceiro-setor', 'equipe', AppointmentStatus::Scheduled],
        [8, '14:00', 60, 4, 'planejamento-financeiro', 'admin', AppointmentStatus::Scheduled],
        [9, '16:00', 60, 5, 'calculo-de-custos', 'equipe', AppointmentStatus::Scheduled],
        [10, '09:00', 60, 7, 'elaboracao-de-relatorio-de-responsabilidade-social', 'admin', AppointmentStatus::Scheduled],
    ];

    private const STEPS_TO = [
        AppointmentStatus::Scheduled->value => [],
        AppointmentStatus::Confirmed->value => [AppointmentStatus::Confirmed],
        AppointmentStatus::Completed->value => [AppointmentStatus::InProgress, AppointmentStatus::Completed],
        AppointmentStatus::NoShow->value => [AppointmentStatus::NoShow],
    ];

    public function run(ScheduleAppointment $schedule, ChangeAppointmentStatus $changeStatus): void
    {
        if (app()->isProduction() || Appointment::query()->exists()) {
            $this->command?->warn('DemoAgendaSeeder skipped: production, or appointments already exist.');

            return;
        }

        $team = [
            'admin' => User::query()->where('email', 'admin@agenda.local')->firstOrFail(),
            'equipe' => User::query()->where('email', 'equipe@agenda.local')->firstOrFail(),
        ];

        $clients = collect(self::CLIENTS)->map(fn (array $client): Client => Client::query()->firstOrCreate(
            ['name' => $client[1]],
            ['type' => $client[0], 'trade_name' => $client[2], 'document' => $client[3], 'phone' => $client[4], 'email' => $client[5], 'accepts_reminders' => $client[5] !== null],
        ));

        // In pt_BR, Carbon weeks start on Sunday.
        $monday = DisplayTimezone::toLocal(now())->startOfWeek(CarbonInterface::MONDAY);

        foreach (self::AGENDA as [$offset, $time, $minutes, $clientIndex, $serviceSlug, $responsible, $status]) {
            $startsAt = $monday->copy()->addDays($offset)->setTimeFromTimeString($time);

            $appointment = $schedule->handle($team['admin'], [
                'client_id' => $clients[$clientIndex]->id,
                'service_id' => Service::query()->where('slug', $serviceSlug)->firstOrFail()->id,
                'responsible_user_id' => $team[$responsible]->id,
                'starts_at' => $startsAt->copy()->utc(),
                'ends_at' => $startsAt->copy()->addMinutes($minutes)->utc(),
                'location_type' => $serviceSlug === 'atendimento-externo' ? LocationType::External : LocationType::OnCampus,
                'location' => $serviceSlug === 'atendimento-externo' ? 'Sala do Empreendedor' : LocationType::DEFAULT_PLACE,
            ]);

            foreach (self::STEPS_TO[$status->value] as $next) {
                $changeStatus->handle($team[$responsible], $appointment, $next);
            }

            if (! $appointment->documentsAreDue()) {
                continue;
            }

            $appointment->documents()
                ->unless($status === AppointmentStatus::Completed, fn ($documents) => $documents->limit(1))
                ->get()
                ->each(fn ($document) => $document->update(['received_at' => now()]));
        }
    }
}

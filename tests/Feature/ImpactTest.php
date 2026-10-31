<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Clients\Enums\ClientType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 15:00');
    }

    public function test_the_web_shows_the_same_indicators_as_the_app(): void
    {
        $joana = Client::factory()->create(['type' => ClientType::Individual]);
        $ong = Client::factory()->create(['type' => ClientType::Organization]);
        $irpf = Service::factory()->create(['name' => 'IRPF']);
        $mei = Service::factory()->create(['slug' => 'atendimento-mei', 'name' => 'Atendimento MEI']);
        $accounts = Service::factory()->for(ServiceCategory::factory()->create(['slug' => 'terceiro-setor']), 'category')->create(['name' => 'Prestação de contas']);

        $this->appointment($joana, $irpf, '2026-10-05 13:00', AppointmentStatus::Completed);
        $this->appointment($joana, $irpf, '2026-10-06 13:00', AppointmentStatus::Completed);
        $this->appointment($joana, $mei, '2026-10-07 13:00', AppointmentStatus::NoShow);
        $this->appointment($joana, $mei, '2026-10-08 13:00', AppointmentStatus::Cancelled);
        $this->appointment($ong, $accounts, '2026-09-10 13:00', AppointmentStatus::Completed);
        $this->appointment($ong, $accounts, '2026-01-10 13:00', AppointmentStatus::Completed);

        $response = $this->actingAs(User::factory()->viewer()->create())->get(route('impact'))->assertOk();
        $data = $response->viewData('data');

        $this->assertSame([5, 3, 2], [$data['total'], $data['status']['completed'], $data['unique_clients']]);
        $this->assertSame(0.75, $data['attendance_rate']);
        $this->assertSame([3, 1, 1, 1], [$data['individuals'], $data['organizations'], $data['mei'], $data['third_sector']]);
        $this->assertSame(['name' => 'IRPF', 'count' => 2], $data['demands'][0]);
        $this->assertSame([['key' => '2026-08', 'count' => 0], ['key' => '2026-09', 'count' => 1], ['key' => '2026-10', 'count' => 1]],
            array_map(fn (array $month): array => ['key' => $month['key'], 'count' => $month['count']], $data['people_by_month']));
        $response->assertSee('75%')->assertSee('Demandas mais frequentes');
    }

    public function test_a_chosen_period_counts_only_its_days(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create();
        $this->appointment($client, $service, '2026-03-10 13:00', AppointmentStatus::Completed);
        $this->appointment($client, $service, '2026-03-31 23:30', AppointmentStatus::Completed);
        $this->appointment($client, $service, '2026-04-01 13:00', AppointmentStatus::Completed);

        $this->actingAs(User::factory()->create());

        $this->assertSame(2, $this->get(route('impact', ['periodo' => 'datas', 'de' => '2026-03-01', 'ate' => '2026-03-31']))->viewData('data')['total']);
        $this->assertSame(0, $this->get(route('impact', ['periodo' => 'mes']))->viewData('data')['total']);
        $this->get(route('impact', ['periodo' => 'datas', 'de' => '2026-03-31', 'ate' => '2026-03-01']))->assertSessionHasErrors('ate');
        $this->get(route('impact', ['periodo' => 'todos']))->assertSessionHasErrors('periodo');
    }

    public function test_the_spreadsheet_opens_in_excel_and_never_runs_formulas(): void
    {
        $service = Service::factory()->create(['name' => '=HYPERLINK("https://evil.example","x")']);
        $this->appointment(Client::factory()->create(), $service, '2026-10-05 13:00', AppointmentStatus::Completed);

        $response = $this->actingAs(User::factory()->create())->get(route('impact.export', ['periodo' => 'mes']))->assertOk();
        $csv = $response->getContent();

        $this->assertStringStartsWith("\u{FEFF}\"Indicador\";\"Valor\"\r\n", $csv);
        $this->assertStringContainsString('"\'=HYPERLINK(""https://evil.example"",""x"")";"1"', $csv);
        $this->assertSame('attachment; filename="impacto-2026-10-01-a-2026-10-31.csv"', $response->headers->get('Content-Disposition'));
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('impact'))->assertRedirect(route('login'));
        $this->get(route('impact.export'))->assertRedirect(route('login'));
    }

    private function appointment(Client $client, Service $service, string $start, AppointmentStatus $status): Appointment
    {
        return Appointment::factory()->for($client)->for($service)
            ->between($start, now()->parse($start)->addHour()->format('Y-m-d H:i'))
            ->status($status)
            ->create();
    }
}

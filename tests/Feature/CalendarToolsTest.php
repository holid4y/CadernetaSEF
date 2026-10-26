<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\ReminderMessage;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_and_calendar_tools_are_rendered_without_requiring_google_credentials(): void
    {
        $client = Client::factory()->create(['email' => 'ana@example.com', 'phone' => '(51) 99125-1538', 'accepts_reminders' => true]);
        $appointment = Appointment::factory()->create(['client_id' => $client->id, 'status' => AppointmentStatus::Scheduled]);
        $this->actingAs(User::factory()->create());
        $this->get(route('clients.show', $client))->assertOk()->assertSee('mailto:ana%40example.com', false)->assertSee('https://wa.me/5551991251538', false);
        $this->get(route('appointments.show', $appointment))->assertOk()->assertSee('data-calendar-event', false)->assertSee('Escrever e-mail')->assertSee('Google Agenda');
        $this->get(route('agenda'))->assertOk()->assertDontSee('Importar de outra agenda')->assertDontSee('data-calendar-import', false);
    }

    public function test_invalid_contacts_do_not_generate_links(): void
    {
        $client = Client::factory()->create(['email' => 'invalid', 'phone' => '1234']);
        $this->actingAs(User::factory()->create())->get(route('clients.show', $client))->assertOk()->assertDontSee('mailto:', false)->assertDontSee('https://wa.me/', false)->assertSee('Cadastre um e-mail válido');
    }

    public function test_rendered_whatsapp_and_email_reminders_include_the_signed_response_url(): void
    {
        $client = Client::factory()->create(['email' => 'ana@example.com', 'phone' => '(51) 99125-1538', 'accepts_reminders' => true]);
        $appointment = Appointment::factory()->create(['client_id' => $client->id]);
        $responseUrl = ReminderMessage::responseUrl($appointment);
        $response = $this->actingAs(User::factory()->create())->get(route('appointments.show', $appointment))->assertOk();
        $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5);
        foreach (['https://wa.me/', 'mailto:'] as $prefix) {
            preg_match('~href="('.preg_quote($prefix, '~').'[^"]+)"~', $html, $match);
            $this->assertNotEmpty($match, $prefix);
            $this->assertStringContainsString($responseUrl, rawurldecode($match[1]));
        }
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }
}

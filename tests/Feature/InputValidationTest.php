<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Appointments\CreateAppointment;
use App\Livewire\Clients\ClientEditor;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use App\Rules\BrazilianDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function documents(): array
    {
        return [
            'valid CPF' => ['529.982.247-25', true],
            'CPF with a wrong check digit' => ['529.982.247-26', false],
            'repeated digits' => ['111.111.111-11', false],
            'valid CNPJ' => ['11.222.333/0001-81', true],
            'CNPJ with a wrong check digit' => ['11.222.333/0001-82', false],
            'CNPJ with letters' => ['12.ABC.345/01DE-35', true],
            'CNPJ with letters typed in lower case' => ['12abc34501de35', true],
            'CNPJ with letters and a wrong check digit' => ['12.ABC.345/01DE-36', false],
            'letters where a check digit belongs' => ['12.ABC.345/01DE-3A', false],
        ];
    }

    #[DataProvider('documents')]
    public function test_document_check_digits(string $document, bool $valid): void
    {
        $characters = BrazilianDocument::characters($document);

        $this->assertSame($valid, strlen($characters) === 11 ? BrazilianDocument::isCpf($characters) : BrazilianDocument::isCnpj($characters));
    }

    public function test_the_document_must_match_the_client_type(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ClientEditor::class)
            ->set('form.type', 'individual')
            ->set('form.name', 'Ana')
            ->set('form.phone', '(51) 99999-0000')
            ->set('form.document', '11.222.333/0001-81')
            ->call('save')
            ->assertHasErrors('form.document');
    }

    public function test_the_same_document_can_not_belong_to_two_clients(): void
    {
        $this->actingAs(User::factory()->create());
        Client::factory()->create(['document' => '529.982.247-25']);

        Livewire::test(ClientEditor::class)
            ->set('form.name', 'Outra pessoa')
            ->set('form.phone', '(51) 99999-0000')
            ->set('form.document', '52998224725')
            ->call('save')
            ->assertHasErrors('form.document')
            ->assertSee('Já existe um atendido com este CPF/CNPJ.');
    }

    public function test_a_client_keeps_its_own_document_when_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['document' => '529.982.247-25']);

        Livewire::test(ClientEditor::class, ['client' => $client])
            ->set('form.name', 'Nome corrigido')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_phone_needs_area_code_and_number(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ClientEditor::class)
            ->set('form.name', 'Ana')
            ->set('form.phone', '9999-000')
            ->call('save')
            ->assertHasErrors('form.phone');
    }

    public function test_unknown_ids_in_the_booking_form_are_validation_errors(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateAppointment::class)
            ->set('service_id', 999999)
            ->set('responsible_user_id', 999999)
            ->call('save')
            ->assertHasErrors(['service_id' => 'exists', 'responsible_user_id' => 'exists']);
    }

    public function test_bookings_far_in_the_past_or_future_are_refused(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateAppointment::class)
            ->set('date', '1999-01-01')
            ->call('save')
            ->assertHasErrors(['date' => 'after_or_equal']);
    }

    public function test_an_appointment_can_not_last_more_than_twelve_hours(): void
    {
        $member = User::factory()->create();
        $appointment = Appointment::factory()->for($member, 'responsible')->between('2026-10-05 13:00', '2026-10-05 14:00')->create();

        $this->actingAs($member)
            ->patchJson("/atendimentos/{$appointment->id}/horario", [
                'starts_at' => '2026-10-06T08:00:00',
                'ends_at' => '2026-10-07T08:00:00',
            ])
            ->assertJsonValidationErrors(['ends_at' => 'no máximo 12 horas']);
    }

    public function test_the_agenda_feed_refuses_huge_ranges(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/agenda/eventos?start=2020-01-01T00:00:00Z&end=2030-01-01T00:00:00Z')
            ->assertJsonValidationErrors('end');
    }

    public function test_long_searches_are_cut_short(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/atendidos?busca='.str_repeat('a', 5000))
            ->assertOk();
    }

    public function test_markdown_typed_into_a_record_is_not_a_link_in_the_reminder(): void
    {
        $appointment = Appointment::factory()
            ->for(Client::factory()->create(['name' => 'Ana', 'accepts_reminders' => true]))
            ->for(Service::factory()->create())
            ->create(['location' => '[Pague a taxa aqui](https://golpe.example/pix)']);

        $html = (string) (new AppointmentReminder($appointment))->toMail($appointment->client)->render();

        $this->assertStringNotContainsString('href="https://golpe.example', $html);
        $this->assertStringContainsString('[Pague a taxa aqui]', $html);
    }

    public function test_password_reset_does_not_reveal_who_has_an_account(): void
    {
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'ninguem@example.com']);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
        $this->assertSame(session('status'), 'Se houver uma conta com este e-mail, enviamos um link para criar uma nova senha.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Livewire\Services\ServiceCatalog;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_reach_the_catalog(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/configuracoes/servicos')->assertOk();
        $this->actingAs(User::factory()->create())->get('/configuracoes/servicos')->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get('/configuracoes/servicos')->assertForbidden();
    }

    public function test_the_menu_shows_the_catalog_to_admins_only(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertSee(route('services.index'));
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertDontSee(route('services.index'));
    }

    public function test_an_admin_creates_a_service_with_its_checklist(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $category = ServiceCategory::factory()->create();

        Livewire::test(ServiceCatalog::class)
            ->call('create')
            ->set('service_category_id', $category->id)
            ->set('name', 'Carnê-leão')
            ->set('default_duration_minutes', 45)
            ->set('new_document', 'Recibos de aluguel')->call('addDocument')
            ->set('new_document', 'Comprovante de pagamento')->call('addDocument')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Serviço "Carnê-leão" criado.');

        $service = Service::query()->where('name', 'Carnê-leão')->sole();
        $this->assertSame('carne-leao', $service->slug);
        $this->assertSame(45, $service->default_duration_minutes);
        $this->assertSame(['Recibos de aluguel', 'Comprovante de pagamento'], $service->documents()->pluck('name')->all());
    }

    public function test_the_checklist_can_be_reordered_renamed_and_trimmed(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $service = Service::factory()->withDocuments(['A', 'B', 'C'])->create();

        Livewire::test(ServiceCatalog::class)
            ->call('edit', $service->id)
            ->assertSet('documents', ['A', 'B', 'C'])
            ->call('moveDocument', 2, -1)
            ->call('removeDocument', 0)
            ->set('documents.1', '  B   revisado ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['C', 'B revisado'], $service->documents()->pluck('name')->all());
    }

    public function test_blank_and_repeated_documents_are_dropped(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $service = Service::factory()->create();

        Livewire::test(ServiceCatalog::class)
            ->call('edit', $service->id)
            ->set('documents', ['RG', ' ', 'rg', 'CPF'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['RG', 'CPF'], $service->documents()->pluck('name')->all());
    }

    public function test_editing_the_checklist_keeps_booked_appointments_as_they_were(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $service = Service::factory()->withDocuments(['Informe de rendimentos'])->create();
        $appointment = Appointment::factory()->for($service)->create();
        $appointment->documents()->create(['name' => 'Informe de rendimentos', 'sort_order' => 0]);

        Livewire::test(ServiceCatalog::class)
            ->call('edit', $service->id)
            ->set('documents', ['Outro documento'])
            ->call('save');

        $this->assertSame(['Informe de rendimentos'], $appointment->documents()->pluck('name')->all());
    }

    public function test_a_deactivated_service_leaves_the_booking_form(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $service = Service::factory()->create(['name' => 'Serviço antigo']);

        Livewire::test(ServiceCatalog::class)
            ->call('edit', $service->id)
            ->set('active', false)
            ->call('save');

        $this->assertFalse($service->fresh()->active);
        $this->get('/atendimentos/novo')->assertDontSee('Serviço antigo');
    }

    public function test_a_deleted_service_leaves_the_catalog_but_not_the_history(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $service = Service::factory()->create(['name' => 'Serviço antigo', 'slug' => 'servico-antigo']);
        $past = Appointment::factory()->for($service)->status(AppointmentStatus::Completed)->between('2026-09-01 13:00', '2026-09-01 14:00')->create();
        Appointment::factory()->for($service)->status(AppointmentStatus::Cancelled)->create();

        Livewire::test(ServiceCatalog::class)
            ->call('edit', $service->id)
            ->assertSee('Excluir serviço')
            ->call('delete')
            ->assertHasNoErrors()
            ->assertSee('Serviço "Serviço antigo" excluído do catálogo.')
            ->assertDontSeeHtml('wire:click="edit('.$service->id.')"');

        $this->assertSoftDeleted($service);
        $this->assertSame('Serviço antigo', $past->fresh()->service->name);
        $this->get('/atendimentos/novo')->assertDontSee('Serviço antigo');
        $this->get(route('appointments.show', $past))->assertOk()->assertSee('Serviço antigo');

        // The name is free again; the slug of the old one is kept for its history.
        Livewire::test(ServiceCatalog::class)
            ->call('create')
            ->set('service_category_id', $service->service_category_id)
            ->set('name', 'Serviço antigo')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('servico-antigo-2', Service::query()->sole()->slug);
    }

    public function test_a_service_with_appointments_ahead_is_not_deleted(): void
    {
        $this->travelTo('2026-11-07 12:00');
        $this->actingAs(User::factory()->admin()->create());
        $service = Service::factory()->create();
        Appointment::factory()->for($service)->between('2026-11-12 13:00', '2026-11-12 14:00')->create();
        Appointment::factory()->for($service)->status(AppointmentStatus::Confirmed)->between('2026-11-20 13:00', '2026-11-20 14:00')->create();

        Livewire::test(ServiceCatalog::class)
            ->call('edit', $service->id)
            ->call('delete')
            ->assertHasErrors('service')
            ->assertSee('Há 2 atendimentos marcados com este serviço, o próximo em 12/11.');

        $this->assertNotSoftDeleted($service);
    }

    public function test_a_deleted_service_can_not_be_booked(): void
    {
        $member = User::factory()->create();
        $service = Service::factory()->create();
        $service->delete();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Este serviço foi excluído do catálogo');

        app(ScheduleAppointment::class)->handle($member, [
            'client_id' => Client::factory()->create()->id,
            'service_id' => $service->id,
            'responsible_user_id' => $member->id,
            'starts_at' => Carbon::parse('2026-12-01 13:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-12-01 14:00', 'UTC'),
        ]);
    }

    public function test_slugs_stay_unique_when_names_repeat(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Service::factory()->create(['name' => 'Consultoria', 'slug' => 'consultoria']);
        $category = ServiceCategory::factory()->create();

        Livewire::test(ServiceCatalog::class)
            ->call('create')
            ->set('service_category_id', $category->id)
            ->set('name', 'Consultoria')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['consultoria', 'consultoria-2'], Service::query()->orderBy('id')->pluck('slug')->all());
    }

    public function test_a_category_does_not_get_two_services_with_the_same_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $existing = Service::factory()->create(['name' => 'Consultoria']);

        Livewire::test(ServiceCatalog::class)
            ->call('create')
            ->set('service_category_id', $existing->service_category_id)
            ->set('name', ' consultoria ')
            ->call('save')
            ->assertHasErrors(['name']);

        Livewire::test(ServiceCatalog::class)
            ->call('edit', $existing->id)
            ->set('description', 'Atualizada')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Service::query()->count());
    }

    public function test_a_checklist_over_the_limit_says_so_on_the_page(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $service = Service::factory()->create();

        Livewire::test(ServiceCatalog::class)
            ->call('edit', $service->id)
            ->set('documents', array_map(fn (int $position): string => "Documento {$position}", range(1, 31)))
            ->call('save')
            ->assertHasErrors(['documents' => 'max'])
            ->assertSee(__('validation.max.array', ['attribute' => __('validation.attributes.documents'), 'max' => 30]));
    }

    public function test_members_can_not_save_through_the_component(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ServiceCatalog::class)->assertForbidden();
    }

    public function test_the_duration_has_sensible_limits(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ServiceCatalog::class)
            ->call('create')
            ->set('name', 'X')
            ->set('default_duration_minutes', 5)
            ->call('save')
            ->assertHasErrors(['default_duration_minutes' => 'min']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Appointments;

use App\Domain\Appointments\Actions\MarkDocumentReceived;
use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_service_checklist_is_copied_when_scheduling(): void
    {
        $appointment = $this->scheduleFor($this->irpf());

        $this->assertSame(
            ['Informes de rendimentos', 'Recibo da última declaração'],
            $appointment->documents->pluck('name')->all(),
        );
        $this->assertTrue($appointment->documents->every(fn ($document) => $document->received_at === null));
    }

    public function test_editing_the_service_checklist_does_not_rewrite_existing_appointments(): void
    {
        $service = $this->irpf();
        $appointment = $this->scheduleFor($service);

        $service->documents()->delete();
        $service->documents()->create(['name' => 'Comprovante de residência', 'sort_order' => 0]);

        $this->assertSame(
            ['Informes de rendimentos', 'Recibo da última declaração'],
            $appointment->documents()->pluck('name')->all(),
        );
    }

    public function test_documents_can_be_marked_and_unmarked_as_received(): void
    {
        $this->freezeSecond();
        $document = $this->scheduleFor($this->irpf())->documents->first();
        $mark = app(MarkDocumentReceived::class);

        $mark->handle($document, received: true);
        $this->assertTrue($document->fresh()->received_at->equalTo(now()));

        $mark->handle($document, received: false);
        $this->assertNull($document->fresh()->received_at);
    }

    public function test_marking_twice_keeps_the_first_delivery_time(): void
    {
        $document = $this->scheduleFor($this->irpf())->documents->first();
        $mark = app(MarkDocumentReceived::class);

        $this->travelTo('2026-10-05 13:00');
        $mark->handle($document, received: true);

        $this->travelTo('2026-10-05 15:00');
        $mark->handle($document, received: true);

        $this->assertSame('13:00', $document->fresh()->received_at->format('H:i'));
    }

    public function test_documents_are_checked_from_the_day_of_the_appointment(): void
    {
        $document = $this->scheduleFor($this->irpf())->documents->first();
        $mark = app(MarkDocumentReceived::class);

        // 2026-10-05 13:00 UTC is 10:00 in São Paulo; the day starts at 03:00 UTC.
        $this->travelTo(Carbon::parse('2026-10-05 02:59', 'UTC'));
        $this->assertThrows(fn () => $mark->handle($document, received: true), ValidationException::class);

        $this->travelTo(Carbon::parse('2026-10-05 03:00', 'UTC'));
        $mark->handle($document, received: true);
        $this->assertNotNull($document->fresh()->received_at);
    }

    private function irpf(): Service
    {
        return Service::factory()
            ->withDocuments(['Informes de rendimentos', 'Recibo da última declaração'])
            ->create(['name' => 'IRPF']);
    }

    private function scheduleFor(Service $service): Appointment
    {
        $member = User::factory()->create();

        return app(ScheduleAppointment::class)->handle($member, [
            'client_id' => Client::factory()->create()->id,
            'service_id' => $service->id,
            'responsible_user_id' => $member->id,
            'starts_at' => Carbon::parse('2026-10-05 13:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-10-05 14:00', 'UTC'),
        ]);
    }
}

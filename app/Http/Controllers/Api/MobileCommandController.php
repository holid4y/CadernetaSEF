<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Appointments\Actions\ChangeAppointmentStatus;
use App\Domain\Appointments\Actions\MarkDocumentReceived;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Actions\ScheduleAppointment;
use App\Domain\Appointments\Actions\SendAppointmentReminder;
use App\Domain\Appointments\Actions\UndoStatusChange;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\LocationType;
use App\Domain\Clients\Actions\SaveClient;
use App\Domain\Services\Actions\SaveService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MobileCommandRequest;
use App\Http\Resources\Mobile\AppointmentResource;
use App\Http\Resources\Mobile\ClientResource;
use App\Http\Resources\Mobile\MobileState;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Support\ContactLinks;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Each command name maps to one method below; anything else is refused by
// MobileCommandRequest. The methods only translate the app's arguments and
// call the same actions the web pages use.
class MobileCommandController extends Controller
{
    private const MAX_DOCUMENTS = 30;

    // Server field names, as the app's forms call them.
    private const FIELDS = [
        'client_id' => 'clientId',
        'service_id' => 'serviceSlug',
        'service_details' => 'serviceDetails',
        'responsible_user_id' => 'responsibleId',
        'starts_at' => 'start',
        'ends_at' => 'end',
        'location_type' => 'locationType',
        'trade_name' => 'tradeName',
        'accepts_reminders' => 'acceptsReminders',
    ];

    public function __construct(
        private SaveClient $saveClient,
        private ScheduleAppointment $schedule,
        private RescheduleAppointment $reschedule,
        private ChangeAppointmentStatus $changeStatus,
        private MarkDocumentReceived $markDocument,
        private SendAppointmentReminder $sendReminder,
        private UndoStatusChange $undo,
        private SaveService $saveService,
    ) {}

    public function __invoke(MobileCommandRequest $request): JsonResponse
    {
        $user = $request->user();
        $args = $request->arguments();

        try {
            $result = match ($request->command()) {
                'saveClient' => $this->saveClient($args),
                'scheduleAppointment' => $this->scheduleAppointment($user, $args),
                'rescheduleAppointment' => $this->rescheduleAppointment($user, $args),
                'changeStatus' => $this->changeStatus($user, $args),
                'toggleDocument' => $this->toggleDocument($args),
                'sendReminder' => $this->sendReminder($args),
                'restoreAppointment' => $this->restoreAppointment($user, $args),
                'setServiceActive' => $this->setServiceActive($args),
                'addServiceDocument' => $this->addServiceDocument($args),
                'removeServiceDocument' => $this->removeServiceDocument($args),
            };
        } catch (ValidationException $error) {
            throw self::forTheApp($error);
        } catch (QueryException $error) {
            // The exclusion constraint is the last guard against double booking.
            if ($error->getCode() !== '23P01') {
                throw $error;
            }

            throw ValidationException::withMessages(['start' => __('appointments.errors.slot_taken')])->status(409);
        }

        return response()->json([
            'state' => MobileState::for($request),
            'result' => $result,
        ]);
    }

    private function saveClient(array $args): array
    {
        $data = self::validate($args, ['input', 'id'], [
            'input' => ['required', 'array'],
            'input.updatedAt' => ['nullable', 'integer'],
            'id' => ['nullable', 'integer'],
        ]);
        $input = $data['input'];

        return DB::transaction(function () use ($data, $input): array {
            $client = $data['id'] === null ? null : Client::query()->lockForUpdate()->findOrFail($data['id']);
            Gate::authorize($client ? 'update' : 'create', $client ?? Client::class);
            self::ensureUnchanged($client, $input['updatedAt'] ?? null, 'clients.errors.stale');

            $saved = $this->saveClient->handle($client, [
                'type' => $input['type'] ?? null,
                'name' => $input['name'] ?? null,
                'trade_name' => $input['tradeName'] ?? null,
                'document' => $input['document'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'notes' => $input['notes'] ?? null,
                'accepts_reminders' => $input['acceptsReminders'] ?? false,
                'active' => $input['active'] ?? true,
            ]);

            return ClientResource::make($saved)->resolve(request());
        }, 3);
    }

    private function scheduleAppointment(User $user, array $args): array
    {
        Gate::authorize('create', Appointment::class);

        $input = self::validate($args, ['input'], [
            'input' => ['required', 'array'],
            'input.clientId' => ['required', 'integer', Rule::exists('clients', 'id')->where('active', true)->whereNull('deleted_at')],
            'input.serviceSlug' => ['required', 'string', Rule::exists('services', 'slug')],
            'input.serviceDetails' => ['nullable', 'string', 'max:2000'],
            'input.responsibleId' => ['required', 'integer', Rule::exists('users', 'id')],
            'input.start' => self::momentRules(),
            'input.end' => ['required', 'integer', 'gt:input.start'],
            'input.locationType' => ['nullable', Rule::enum(LocationType::class)],
            'input.location' => ['nullable', 'string', 'max:255'],
            'input.notes' => ['nullable', 'string', 'max:2000'],
        ])['input'];

        $appointment = $this->schedule->handle($user, [
            'client_id' => $input['clientId'],
            'service_id' => Service::query()->where('slug', $input['serviceSlug'])->value('id'),
            'responsible_user_id' => $input['responsibleId'],
            'starts_at' => self::moment($input['start']),
            'ends_at' => self::moment($input['end']),
            'service_details' => $input['serviceDetails'] ?? null,
            'location_type' => $input['locationType'] ?? LocationType::OnCampus->value,
            'location' => $input['location'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);

        return self::appointment($appointment);
    }

    private function rescheduleAppointment(User $user, array $args): array
    {
        $data = self::validate($args, ['id', 'input'], [
            'id' => ['required', 'integer'],
            'input' => ['required', 'array'],
            'input.start' => self::momentRules(),
            'input.end' => ['required', 'integer', 'gt:input.start'],
            'input.responsibleId' => ['required', 'integer', Rule::exists('users', 'id')],
            'input.updatedAt' => ['nullable', 'integer'],
        ]);
        $input = $data['input'];

        return DB::transaction(function () use ($user, $data, $input): array {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($data['id']);
            Gate::authorize('update', $appointment);
            self::ensureUnchanged($appointment, $input['updatedAt'] ?? null, 'appointments.errors.stale');

            $this->reschedule->handle(
                $user,
                $appointment,
                self::moment($input['start']),
                self::moment($input['end']),
                User::query()->findOrFail($input['responsibleId']),
            );

            return self::appointment($appointment);
        }, 3);
    }

    private function changeStatus(User $user, array $args): array
    {
        $data = self::validate($args, ['id', 'status', 'from'], [
            'id' => ['required', 'integer'],
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'from' => ['nullable', Rule::enum(AppointmentStatus::class)],
        ]);
        $next = AppointmentStatus::from($data['status']);

        return DB::transaction(function () use ($user, $data, $next): array {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($data['id']);
            Gate::authorize($next === AppointmentStatus::Cancelled ? 'cancel' : 'update', $appointment);

            if ($data['from'] !== null && $appointment->status->value !== $data['from']) {
                throw ValidationException::withMessages(['status' => __('appointments.errors.stale')])->status(409);
            }

            $this->changeStatus->handle($user, $appointment, $next);

            return self::appointment($appointment);
        }, 3);
    }

    private function toggleDocument(array $args): array
    {
        $data = self::validate($args, ['appointmentId', 'documentId', 'received'], [
            'appointmentId' => ['required', 'integer'],
            'documentId' => ['required', 'integer'],
            'received' => ['nullable', 'boolean'],
        ]);

        $appointment = Appointment::query()->findOrFail($data['appointmentId']);
        Gate::authorize('update', $appointment);

        $document = $appointment->documents()->findOrFail($data['documentId']);
        $received = $data['received'] ?? ! $document->isReceived();

        return AppointmentResource::document($this->markDocument->handle($document, (bool) $received));
    }

    private function sendReminder(array $args): string
    {
        $data = self::validate($args, ['id'], ['id' => ['required', 'integer']]);

        $appointment = Appointment::query()->with(['client', 'service', 'documents'])->findOrFail($data['id']);
        Gate::authorize('update', $appointment);

        $blocker = self::reminderBlocker($appointment);

        if ($blocker !== null) {
            throw ValidationException::withMessages(['reminder' => $blocker]);
        }

        abort_unless($this->sendReminder->handle($appointment, again: true), 503, __('reminders.errors.mail_unavailable'));

        return $appointment->client->email;
    }

    private function restoreAppointment(User $user, array $args): null
    {
        $data = self::validate($args, ['snapshot'], [
            'snapshot' => ['required', 'array'],
            'snapshot.id' => ['required', 'integer'],
            'snapshot.status' => ['nullable', Rule::enum(AppointmentStatus::class)],
        ]);

        $appointment = Appointment::query()->findOrFail($data['snapshot']['id']);
        Gate::authorize('update', $appointment);

        $this->undo->handle($user, $appointment, AppointmentStatus::tryFrom($data['snapshot']['status'] ?? ''));

        return null;
    }

    private function setServiceActive(array $args): null
    {
        $data = self::validate($args, ['slug', 'active'], [
            'slug' => ['required', 'string'],
            'active' => ['required', 'boolean'],
        ]);

        return $this->changeService($data['slug'], fn (bool $active, array $documents): array => [(bool) $data['active'], $documents]);
    }

    private function addServiceDocument(array $args): null
    {
        $data = self::validate($args, ['slug', 'name'], [
            'slug' => ['required', 'string'],
            'name' => ['required', 'string', 'max:200'],
        ]);

        return $this->changeService($data['slug'], function (bool $active, array $documents) use ($data): array {
            if (count($documents) >= self::MAX_DOCUMENTS) {
                throw ValidationException::withMessages(['name' => __('services.errors.too_many_documents', ['max' => self::MAX_DOCUMENTS])]);
            }

            return [$active, [...$documents, $data['name']]];
        });
    }

    private function removeServiceDocument(array $args): null
    {
        $data = self::validate($args, ['slug', 'index', 'name'], [
            'slug' => ['required', 'string'],
            'index' => ['required', 'integer', 'min:0'],
            'name' => ['nullable', 'string'],
        ]);

        return $this->changeService($data['slug'], function (bool $active, array $documents) use ($data): array {
            $current = $documents[$data['index']] ?? null;

            if ($current === null || ($data['name'] !== null && $current !== $data['name'])) {
                throw ValidationException::withMessages(['name' => __('services.errors.stale')])->status(409);
            }

            unset($documents[$data['index']]);

            return [$active, array_values($documents)];
        });
    }

    /**
     * @param  Closure(bool, list<string>): array{0: bool, 1: list<string>}  $change
     */
    private function changeService(string $slug, Closure $change): null
    {
        DB::transaction(function () use ($slug, $change): void {
            $service = Service::query()->where('slug', $slug)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $service);

            [$active, $documents] = $change($service->active, $service->documents()->pluck('name')->all());

            $this->saveService->handle($service, [
                'service_category_id' => $service->service_category_id,
                'name' => $service->name,
                'description' => $service->description,
                'default_duration_minutes' => $service->default_duration_minutes,
                'requires_details' => $service->requires_details,
                'active' => $active,
            ], $documents);
        }, 3);

        return null;
    }

    private static function appointment(Appointment $appointment): array
    {
        return AppointmentResource::make($appointment->load(MobileState::APPOINTMENT_RELATIONS))->resolve(request());
    }

    /**
     * @param  list<mixed>  $args
     * @param  list<string>  $names
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function validate(array $args, array $names, array $rules): array
    {
        $values = [];

        foreach ($names as $position => $name) {
            $values[$name] = $args[$position] ?? null;
        }

        $messages = [
            'input.start.between' => __('api.invalid_period'),
            'input.end.gt' => __('appointments.errors.invalid_period'),
        ];

        // Validated data only keeps the nested keys that have a rule (an input with
        // a rule for updatedAt would lose every other field), so the checked values
        // are returned as they came.
        Validator::make($values, $rules, $messages)->validate();

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function momentRules(): array
    {
        return ['required', 'integer', 'between:'.now()->subYear()->getTimestampMs().','.now()->addYears(2)->getTimestampMs()];
    }

    private static function moment(int $milliseconds): Carbon
    {
        return Carbon::createFromTimestamp(intdiv($milliseconds, 1000), 'UTC');
    }

    private static function ensureUnchanged(?Model $model, mixed $seenAt, string $message): void
    {
        if ($model === null || $seenAt === null || (int) $seenAt === $model->updated_at?->getTimestampMs()) {
            return;
        }

        throw ValidationException::withMessages(['updatedAt' => __($message)])->status(409);
    }

    private static function reminderBlocker(Appointment $appointment): ?string
    {
        $client = $appointment->client;

        return match (true) {
            ! config('mail.reminders_enabled') => __('reminders.errors.disabled'),
            ! in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true) => __('reminders.errors.closed'),
            $appointment->starts_at->isPast() => __('reminders.errors.past'),
            ! $client->active => __('reminders.errors.inactive'),
            ! $client->accepts_reminders => __('reminders.card.no_consent'),
            blank($client->email) => __('reminders.card.no_email'),
            ContactLinks::email($client->email) === null => __('reminders.errors.invalid_email'),
            default => null,
        };
    }

    private static function forTheApp(ValidationException $error): ValidationException
    {
        $errors = collect($error->errors())
            ->mapWithKeys(function (array $messages, string $field): array {
                $field = Str::after($field, 'input.');

                return [self::FIELDS[$field] ?? $field => $messages];
            })
            ->all();

        return ValidationException::withMessages($errors)->status($error->status);
    }
}

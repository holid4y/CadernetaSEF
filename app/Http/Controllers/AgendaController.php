<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Queries\AgendaAppointments;
use App\Domain\Google\GoogleAuthorization;
use App\Http\Requests\Agenda\AgendaEventsRequest;
use App\Http\Requests\Agenda\RescheduleAppointmentRequest;
use App\Http\Resources\AgendaEventResource;
use App\Http\Resources\GoogleAgendaEventResource;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AgendaController extends Controller
{
    public function index(Request $request): View
    {
        return view('agenda.index', [
            'responsibles' => User::query()->assignable()->orderBy('name')->get(['id', 'name']),
            'google' => GoogleAuthorization::configured() && $request->user()->canWrite()
                ? ['account' => GoogleAccount::query()->where('user_id', $request->user()->id)->first()]
                : null,
            'waitingFromGoogle' => GoogleEvent::query()->pending()->where('ends_at', '>', now())->count(),
        ]);
    }

    public function events(AgendaEventsRequest $request): JsonResponse
    {
        $appointments = AgendaAppointments::between(
            $request->from(),
            $request->to(),
            $request->responsibleId(),
            $request->boolean('cancelled'),
        );

        $fromGoogle = GoogleEvent::query()
            ->pending()
            ->with('account.user')
            ->overlapping($request->from(), $request->to())
            ->when($request->responsibleId(), fn (Builder $query, int $id) => $query->whereRelation('account', 'user_id', $id))
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            ...AgendaEventResource::collection($appointments)->resolve($request),
            ...GoogleAgendaEventResource::collection($fromGoogle)->resolve($request),
        ]);
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment, RescheduleAppointment $reschedule): Response
    {
        $reschedule->handle($request->user(), $appointment, $request->startsAt(), $request->endsAt());

        return response()->noContent();
    }
}

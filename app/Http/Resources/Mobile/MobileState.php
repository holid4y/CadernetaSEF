<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\GoogleEvent;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

// Everything the app shows, read from the same tables as the web pages.
// The app replaces its whole state with this snapshot after every change.
final class MobileState
{
    public const APPOINTMENT_RELATIONS = ['service:id,slug', 'documents', 'activities', 'externalForms', 'googleEventCopies', 'googleEvent'];

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $appointments = Appointment::query()
            ->with(self::APPOINTMENT_RELATIONS)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        // A removed client still has to be named in the history of its appointments.
        $clients = Client::withTrashed()
            ->where(fn (Builder $query) => $query->whereNull('deleted_at')->orWhereIn('id', $appointments->pluck('client_id')->unique()))
            ->orderBy('name')
            ->get();

        return [
            'users' => UserResource::collection(User::query()->orderBy('name')->get())->resolve($request),
            'clients' => ClientResource::collection($clients)->resolve($request),
            'services' => ServiceResource::collection(
                Service::query()->with(['category:id,slug', 'documents'])->orderBy('sort_order')->orderBy('id')->get(),
            )->resolve($request),
            'appointments' => AppointmentResource::collection($appointments)->resolve($request),
            'google' => GoogleStatus::for($request->user()),
            'googleEvents' => GoogleEvent::query()
                ->pending()
                ->with('account:id,user_id')
                ->where('ends_at', '>', now()->subDays(7))
                ->orderBy('starts_at')
                ->get()
                ->map(fn (GoogleEvent $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'location' => $event->location,
                    'start' => $event->starts_at->getTimestampMs(),
                    'end' => $event->ends_at->getTimestampMs(),
                    'ownerId' => $event->account->user_id,
                ])
                ->all(),
            'counters' => (object) [],
            'meta' => ['serverTime' => now()->getTimestampMs()],
        ];
    }
}

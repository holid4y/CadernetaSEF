<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Google\Actions\ChooseGoogleSource;
use App\Domain\Google\Actions\DisconnectGoogleAccount;
use App\Domain\Google\GoogleAuthorization;
use App\Domain\Google\GoogleHandoff;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\GoogleStatus;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MobileGoogleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['google' => GoogleStatus::for($request->user())]);
    }

    public function calendars(Request $request, ChooseGoogleSource $source): JsonResponse
    {
        Gate::authorize('create', Appointment::class);

        return response()->json(['calendars' => $source->calendars($this->account($request))]);
    }

    public function connect(Request $request): JsonResponse
    {
        Gate::authorize('create', Appointment::class);
        abort_unless(GoogleAuthorization::configured(), 404);

        return response()->json(['url' => route('google.handoff', GoogleHandoff::issue($request->user()))]);
    }

    public function update(Request $request, ChooseGoogleSource $source): JsonResponse
    {
        Gate::authorize('create', Appointment::class);
        $data = $request->validate(['calendarId' => ['nullable', 'string', 'max:255']]);

        $source->handle($this->account($request), $data['calendarId'] ?? null);

        return response()->json(['google' => GoogleStatus::for($request->user())]);
    }

    public function destroy(Request $request, DisconnectGoogleAccount $disconnect): JsonResponse
    {
        $disconnect->handle(GoogleAccount::query()->where('user_id', $request->user()->id)->firstOrFail());

        return response()->json(['google' => GoogleStatus::for($request->user())]);
    }

    private function account(Request $request): GoogleAccount
    {
        return GoogleAccount::query()->working()->where('user_id', $request->user()->id)->firstOrFail();
    }
}

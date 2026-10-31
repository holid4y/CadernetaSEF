<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Google\Actions\ChooseGoogleSource;
use App\Domain\Google\Actions\ConnectGoogleAccount;
use App\Domain\Google\Actions\DisconnectGoogleAccount;
use App\Domain\Google\GoogleAuthorization;
use App\Domain\Google\GoogleHandoff;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\GoogleEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GoogleAccountController extends Controller
{
    private const SESSION_KEY = 'google.authorization';

    public function show(Request $request, ChooseGoogleSource $source): View
    {
        Gate::authorize('create', Appointment::class);

        $account = GoogleAccount::query()->where('user_id', $request->user()->id)->first();
        $calendars = null;
        $calendarsError = null;

        if ($account !== null && $account->failed_at === null) {
            try {
                $calendars = $source->calendars($account);
            } catch (ValidationException $error) {
                $calendarsError = $error->getMessage();
            }
        }

        return view('google.show', [
            'configured' => GoogleAuthorization::configured(),
            'account' => $account,
            'calendars' => $calendars,
            'calendarsError' => $calendarsError,
        ]);
    }

    public function connect(Request $request, GoogleAuthorization $authorization): RedirectResponse
    {
        Gate::authorize('create', Appointment::class);

        return $this->toGoogle($request, $request->user(), 'web', $authorization);
    }

    public function handoff(Request $request, string $token, GoogleAuthorization $authorization): RedirectResponse|View
    {
        $user = GoogleHandoff::redeem($token);

        if ($user === null) {
            return view('google.finished', ['error' => __('google.errors.link')]);
        }

        return $this->toGoogle($request, $user, 'app', $authorization);
    }

    public function callback(Request $request, GoogleAuthorization $authorization, ConnectGoogleAccount $connect): RedirectResponse|View
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');
        $valid = is_array($pending) && is_string($state) && hash_equals($pending['state'], $state) && $pending['expires'] > now()->timestamp;
        $via = $valid ? $pending['via'] : ($request->user() ? 'web' : 'app');
        $user = $valid ? User::query()->find($pending['user']) : null;

        $error = match (true) {
            ! $valid => __('google.errors.expired'),
            $request->query('error') !== null => __('google.errors.denied'),
            $user === null || ! $user->canWrite() => __('google.errors.expired'),
            $via === 'web' && $request->user()?->id !== $user->id => __('google.errors.expired'),
            ! is_string($request->query('code')) => __('google.errors.refused'),
            default => null,
        };

        if ($error === null) {
            try {
                $account = $connect->handle($user, $authorization->exchange($request->query('code'), $pending['verifier']));
            } catch (ValidationException $exception) {
                $error = $exception->getMessage();
            }
        }

        if ($via === 'app') {
            return view('google.finished', ['error' => $error, 'email' => $account->email ?? null, 'name' => $user?->name]);
        }

        return $error === null
            ? redirect()->route('google.show')->with('notice', __('google.flash.connected', ['email' => $account->email]))
            : redirect()->route('google.show')->withErrors(['google' => $error]);
    }

    public function update(Request $request, ChooseGoogleSource $source): RedirectResponse
    {
        Gate::authorize('create', Appointment::class);
        $data = $request->validate(['calendar' => ['nullable', 'string', 'max:255']]);
        $account = GoogleAccount::query()->working()->where('user_id', $request->user()->id)->firstOrFail();

        $name = $source->handle($account, $data['calendar'] ?? null);

        return redirect()->route('google.show')->with('notice', $name === null
            ? __('google.flash.source_cleared')
            : __('google.flash.source_saved', ['name' => $name]));
    }

    public function destroy(Request $request, DisconnectGoogleAccount $disconnect): RedirectResponse
    {
        $account = GoogleAccount::query()->where('user_id', $request->user()->id)->firstOrFail();

        $disconnect->handle($account);

        return redirect()->route('google.show')->with('notice', __('google.flash.disconnected'));
    }

    public function dismiss(GoogleEvent $googleEvent): RedirectResponse
    {
        Gate::authorize('create', Appointment::class);
        abort_unless($googleEvent->isPending(), 409, __('google.errors.already_imported'));

        $googleEvent->update(['dismissed_at' => now()]);

        return redirect()->route('agenda')->with('notice', __('google.flash.dismissed'));
    }

    private function toGoogle(Request $request, User $user, string $via, GoogleAuthorization $authorization): RedirectResponse
    {
        abort_unless(GoogleAuthorization::configured(), 404);

        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'user' => $user->id,
            'via' => $via,
            'expires' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away($authorization->url($state, $verifier));
    }
}

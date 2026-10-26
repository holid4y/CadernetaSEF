<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->active) {
            return $next($request);
        }

        // The mobile app signs in with tokens: all of them stop working at once.
        if (! $request->hasSession()) {
            $user->tokens()->delete();

            abort(Response::HTTP_UNAUTHORIZED, __('auth.inactive'));
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort_if($request->expectsJson(), Response::HTTP_UNAUTHORIZED);

        return redirect()->route('login')->withErrors(['email' => __('auth.inactive')]);
    }
}

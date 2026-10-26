<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

// The link opens the web page that resets the password; the app only asks for it.
class MobilePasswordResetController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        Password::sendResetLink(['email' => $request->string('email')->lower()->toString()]);

        return response()->json(['message' => __('passwords.sent_if_exists')]);
    }
}

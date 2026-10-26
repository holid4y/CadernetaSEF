<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\MobileState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileStateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['state' => MobileState::for($request)]);
    }
}

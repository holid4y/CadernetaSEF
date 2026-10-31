<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Forms\ReceiveFormEvent;
use App\Http\Requests\FormAppointmentsRequest;
use App\Http\Requests\FormEventRequest;
use App\Http\Resources\FormAppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormIntegrationController extends Controller
{
    private const PER_PAGE = 50;

    public function store(FormEventRequest $request, ReceiveFormEvent $receive): JsonResponse
    {
        $result = $receive->handle($request->payload());

        return response()->json($result, $result['result'] === 'pending_review' ? 202 : 200);
    }

    public function index(FormAppointmentsRequest $request): JsonResponse
    {
        $page = Appointment::query()
            ->with(FormAppointmentResource::RELATIONS)
            ->overlapping($request->from(), $request->to())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->simplePaginate(self::PER_PAGE);

        return response()->json([
            'data' => FormAppointmentResource::collection($page->getCollection())->resolve($request),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function show(Request $request, string $integrationId): JsonResponse
    {
        $appointment = Appointment::query()
            ->with(FormAppointmentResource::RELATIONS)
            ->where('integration_id', $integrationId)
            ->firstOrFail();

        return response()->json(FormAppointmentResource::make($appointment)->resolve($request));
    }
}

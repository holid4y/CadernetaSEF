<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Clients\Queries\ClientHistory;
use App\Models\Client;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(): View
    {
        return view('clients.index');
    }

    public function create(): View
    {
        return view('clients.create');
    }

    public function import(): View
    {
        return view('clients.import');
    }

    public function show(Client $client): View
    {
        return view('clients.show', [
            'client' => $client,
            'upcoming' => ClientHistory::upcoming($client),
            'past' => ClientHistory::past($client),
        ]);
    }

    public function edit(Client $client): View
    {
        return view('clients.edit', ['client' => $client]);
    }
}

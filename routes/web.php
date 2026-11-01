<?php

declare(strict_types=1);

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FormReviewController;
use App\Http\Controllers\GoogleAccountController;
use App\Http\Controllers\ImpactController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReminderResponseController;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::view('/privacidade', 'privacy')->name('privacy');

Route::middleware(['signed', 'throttle:20,1,reminder-response'])->controller(ReminderResponseController::class)->group(function () {
    Route::get('/confirmacao/{appointment}', 'show')->name('appointments.respond');
    Route::post('/confirmacao/{appointment}', 'store');
});

// Google sends people back here, and the app's single-use link starts here,
// in a browser that may not be signed in to the Caderneta.
Route::middleware('throttle:10,1,google-return')->controller(GoogleAccountController::class)->group(function () {
    Route::get('/google/retorno', 'callback')->name('google.callback');
    Route::get('/google/conectar/{token}', 'handoff')->where('token', '[A-Za-z0-9]{64}')->name('google.handoff');
});

Route::middleware('auth')->group(function () {
    Route::controller(GoogleAccountController::class)->group(function () {
        Route::get('/google', 'show')->name('google.show');
        Route::get('/google/conectar', 'connect')->middleware('throttle:10,1,google-connect')->name('google.connect');
        Route::patch('/google', 'update')->middleware('throttle:20,1,google-update')->name('google.update');
        Route::delete('/google', 'destroy')->middleware('throttle:10,1,google-disconnect')->name('google.destroy');
        Route::post('/google/eventos/{googleEvent}/ignorar', 'dismiss')->middleware('throttle:30,1,google-dismiss')->name('google.dismiss');
    });

    Route::get('/integracoes/formularios', [FormReviewController::class, 'index'])->name('forms.review');
    Route::post('/integracoes/formularios/{externalForm}/vincular', [FormReviewController::class, 'store'])
        ->middleware('throttle:30,1,forms-link')->name('forms.link');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/perfil', [ProfileController::class, 'update'])->name('profile.update');

    Route::controller(AgendaController::class)->group(function () {
        Route::get('/agenda', 'index')->name('agenda')->can('viewAny', Appointment::class);
        Route::get('/agenda/eventos', 'events')->name('agenda.events')->can('viewAny', Appointment::class)->middleware('throttle:120,1,agenda-events');
        Route::patch('/atendimentos/{appointment}/horario', 'reschedule')->name('appointments.reschedule')->middleware('throttle:30,1,agenda-reschedule');
    });

    Route::controller(AppointmentController::class)->group(function () {
        Route::get('/atendimentos/novo', 'create')->name('appointments.create')->can('create', Appointment::class);
        Route::get('/atendimentos/{appointment}', 'show')->name('appointments.show')->can('view', 'appointment');
    });

    Route::controller(ClientController::class)->group(function () {
        Route::get('/atendidos', 'index')->name('clients.index')->can('viewAny', Client::class);
        Route::get('/atendidos/novo', 'create')->name('clients.create')->can('create', Client::class);
        Route::get('/atendidos/{client}', 'show')->name('clients.show')->can('view', 'client');
        Route::get('/atendidos/{client}/editar', 'edit')->name('clients.edit')->can('update', 'client');
    });

    Route::get('/impacto', [ImpactController::class, 'index'])->name('impact')->can('viewAny', Appointment::class);
    Route::get('/impacto/planilha', [ImpactController::class, 'export'])->name('impact.export')->can('viewAny', Appointment::class)->middleware('throttle:30,1,impact-export');

    Route::view('/configuracoes/servicos', 'settings.services')->name('services.index')->can('create', Service::class);
});

require __DIR__.'/auth.php';

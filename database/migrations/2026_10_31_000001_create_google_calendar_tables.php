<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('scopes');
            $table->string('calendar_id')->nullable();
            $table->string('source_calendar_id')->nullable();
            $table->string('source_calendar_name')->nullable();
            $table->timestamp('pulled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        // Appointments copied to the Caderneta SEF calendar of their responsible.
        Schema::create('google_event_copies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('google_account_id')->constrained()->cascadeOnDelete();
            $table->text('html_link')->nullable();
            $table->timestamps();

            $table->unique(['appointment_id', 'google_account_id']);
        });

        // Events read from the calendar each person chose, waiting to become
        // appointments or already linked to one.
        Schema::create('google_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('google_account_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 1024);
            $table->string('title');
            $table->string('location')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('html_link')->nullable();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->string('sync_error')->nullable();
            $table->timestamps();

            $table->unique(['google_account_id', 'event_id']);
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_events');
        Schema::dropIfExists('google_event_copies');
        Schema::dropIfExists('google_accounts');
    }
};

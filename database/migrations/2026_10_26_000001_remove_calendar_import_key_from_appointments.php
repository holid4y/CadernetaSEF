<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Importing events from other calendars was removed; the key only served it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique(['calendar_import_key']);
            $table->dropColumn('calendar_import_key');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('calendar_import_key', 64)->nullable()->unique();
        });
    }
};

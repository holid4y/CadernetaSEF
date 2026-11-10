<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// An account can exist before its owner has chosen a password. Accounts
// cleared while the column was still required hold an empty value.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });

        DB::table('users')->where('password', '')->update(['password' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('password')->update(['password' => '']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};

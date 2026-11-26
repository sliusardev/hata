<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A module's own columns on the host's `users` table for the test form card `Tests\Fixtures\Forms\PersonalExtension`
 * (a workbench migration, not `Schema::table` in a test: on MySQL a schema change commits the test's transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birthday')->nullable();
            $table->string('gender')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birthday', 'gender']);
        });
    }
};

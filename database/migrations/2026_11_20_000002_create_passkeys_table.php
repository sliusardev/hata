<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel Passkeys' table (Fortify's passkey sign-in). Skipped when the site already published and ran the
     * package's own migration.
     */
    public function up(): void
    {
        if (Schema::hasTable('passkeys')) {
            return;
        }

        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Deliberately nothing: `up()` skips when the site already has the table, so the table may be the site's own,
     * and rolling Hata back must never delete a site's passkeys. `migrate:fresh` still drops it with the rest.
     */
    public function down(): void
    {
        //
    }
};

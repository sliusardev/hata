<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('entry_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('locale', 12);
            $table->string('path');
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['type', 'locale', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entry_redirects');
    }
};

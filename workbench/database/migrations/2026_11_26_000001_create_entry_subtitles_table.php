<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A module's own table for the test form card `Tests\Fixtures\Forms\SubtitleExtension`, as the design recommends for
 * a module's fields (a table of its own, never a column on Hata's tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_subtitles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entry_id')->unique();
            $table->string('subtitle');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_subtitles');
    }
};

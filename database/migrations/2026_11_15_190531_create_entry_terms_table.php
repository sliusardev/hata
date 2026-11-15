<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which terms (records of a taxonomy type, such as a category or a tag) a record is filed under. Both sides are
     * rows of `entries`, in the same language.
     */
    public function up(): void
    {
        Schema::create('entry_terms', function (Blueprint $table) {
            $table->foreignId('entry_id')->constrained('entries')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('entries')->cascadeOnDelete();

            $table->primary(['entry_id', 'term_id']);
            // A term's page lists its records.
            $table->index(['term_id', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_terms');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The journal of the maintenance page. `slot` is the run lock: only the run in progress holds the one value
     * it can have, and the unique index refuses a second one.
     */
    public function up(): void
    {
        Schema::create('maintenance_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->string('action', 40);
            $table->string('parameter')->nullable();
            $table->string('status', 20);
            $table->string('slot', 10)->nullable()->unique();
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->boolean('output_truncated')->default(false);
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_runs');
    }
};

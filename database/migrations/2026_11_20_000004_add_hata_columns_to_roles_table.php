<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hata's columns on spatie's `roles` table: the label of a custom role and the system-role flag. Each one is added
     * only when missing, so it also works on a host that created spatie's tables itself.
     */
    public function up(): void
    {
        $roles = (string) config('permission.table_names.roles');
        $missing = $this->missing($roles);

        if ($missing === []) {
            return;
        }

        Schema::table($roles, function (Blueprint $table) use ($missing) {
            if (in_array('label', $missing, true)) {
                $table->string('label')->nullable();
            }

            if (in_array('is_system', $missing, true)) {
                $table->boolean('is_system')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations: drops the columns this migration adds.
     */
    public function down(): void
    {
        $roles = (string) config('permission.table_names.roles');

        if (! Schema::hasTable($roles)) {
            return;
        }

        $present = array_values(array_diff(['label', 'is_system'], $this->missing($roles)));

        if ($present !== []) {
            Schema::table($roles, function (Blueprint $table) use ($present) {
                $table->dropColumn($present);
            });
        }
    }

    /**
     * @return list<string>
     */
    private function missing(string $roles): array
    {
        return array_values(array_filter(['label', 'is_system'], fn (string $column): bool => ! Schema::hasColumn($roles, $column)));
    }
};

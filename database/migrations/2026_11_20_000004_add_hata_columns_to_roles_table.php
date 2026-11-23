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
     * Deliberately nothing: the roles table may be the site's own (spatie's migration), and the labels of its roles
     * live in these columns; rolling Hata back must never lose a site's roles. `migrate:fresh` still drops them.
     */
    public function down(): void
    {
        //
    }

    /**
     * @return list<string>
     */
    private function missing(string $roles): array
    {
        return array_values(array_filter(['label', 'is_system'], fn (string $column): bool => ! Schema::hasColumn($roles, $column)));
    }
};

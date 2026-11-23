<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hata's columns on the host's `users` table. A column the host already has is skipped: the two-factor columns
     * come from Fortify's own migration when the site published it.
     *
     * `search_index` holds a lowercased copy of the name and email, so case-insensitive search works the same for
     * Cyrillic on SQLite (whose LIKE only folds ASCII) and MySQL; the host's existing users are filled in here.
     */
    public function up(): void
    {
        $missing = fn (string $column): bool => ! Schema::hasColumn('users', $column);
        $addsSearchIndex = $missing('search_index');

        Schema::table('users', function (Blueprint $table) use ($missing) {
            if ($missing('two_factor_secret')) {
                $table->text('two_factor_secret')->nullable();
            }

            if ($missing('two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable();
            }

            if ($missing('two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }

            if ($missing('locale')) {
                $table->string('locale', 10)->nullable();
            }

            if ($missing('search_index')) {
                $table->text('search_index')->nullable();
            }
        });

        if ($addsSearchIndex) {
            DB::table('users')->select(['id', 'name', 'email'])->orderBy('id')->each(function (object $user): void {
                DB::table('users')->where('id', $user->id)->update([
                    'search_index' => mb_strtolower($user->name.' '.$user->email),
                ]);
            });
        }
    }

    /**
     * Reverse the migrations. The two-factor columns stay: they may be the host's (Fortify's migration).
     */
    public function down(): void
    {
        $present = array_values(array_filter(['locale', 'search_index'], fn (string $column): bool => Schema::hasColumn('users', $column)));

        if ($present !== []) {
            Schema::table('users', function (Blueprint $table) use ($present) {
                $table->dropColumn($present);
            });
        }
    }
};

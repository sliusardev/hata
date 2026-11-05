<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * search_index holds a lowercased copy of the searchable columns, so case-insensitive
     * search works the same for Cyrillic on SQLite (whose LIKE only folds ASCII) and MySQL.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('search_index')->nullable();
        });

        DB::table('users')->select(['id', 'name', 'email'])->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'search_index' => mb_strtolower($user->name.' '.$user->email),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('search_index');
        });
    }
};

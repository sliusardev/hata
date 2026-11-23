<?php

use App\Models\User;
use Hata\Hata;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds only the users columns the host does not have, and indexes the existing users', function () {
    $user = User::factory()->create(['name' => 'Олена', 'email' => 'Olena@Example.test']);

    Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['locale', 'search_index']));

    // the two-factor columns are already there (as when the host ran Fortify's migration): they are skipped
    (require glob(Hata::path('database/migrations/*_add_hata_columns_to_users_table.php'))[0])->up();

    expect(Schema::hasColumns('users', ['locale', 'search_index', 'two_factor_secret', 'two_factor_confirmed_at']))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('search_index'))->toBe('олена olena@example.test');
});

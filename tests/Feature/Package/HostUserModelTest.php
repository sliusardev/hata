<?php

use App\Models\Member;
use App\Models\User;
use Hata\Hata;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

it('uses the model configured as the auth user', function () {
    Schema::create('members', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('locale', 12)->nullable();
        $table->string('search_index')->nullable();
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    config(['auth.providers.users.model' => Member::class]);

    User::factory()->create(['name' => 'Workbench Person']);
    $actor = Member::create(['name' => 'Member Actor', 'email' => 'actor@example.test', 'password' => 'secret-password']);
    $actor->forceFill(['email_verified_at' => now()])->save();
    Member::create(['name' => 'Member Other', 'email' => 'other@example.test', 'password' => 'secret-password']);

    foreach (['admin.access', 'users.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['admin.access', 'users.view']);

    expect(Hata::userModel())->toBe(Member::class)
        ->and(Hash::check('secret-password', $actor->password))->toBeTrue();

    $this->actingAs($actor)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Member Actor')
        ->assertSee('Member Other')
        ->assertDontSee('Workbench Person');
});

it('explains a user model without the hata trait', function () {
    $plain = new class extends Authenticatable {};
    config(['auth.providers.users.model' => $plain::class]);

    expect(fn () => Hata::userModel())->toThrow(LogicException::class, 'IsAdminUser');
});

it('keeps the host\'s own fillable and casts', function () {
    Schema::table('users', fn (Blueprint $table) => $table->string('nickname')->nullable());

    $user = User::create(['name' => 'Host Person', 'email' => 'host@example.test', 'password' => 'secret-password', 'locale' => 'uk', 'nickname' => 'x']);

    expect($user->fresh())
        ->nickname->toBe('x')
        ->locale->toBe('uk')
        ->and($user->getCasts())->toMatchArray(['nickname' => 'string', 'password' => 'hashed', 'email_verified_at' => 'datetime'])
        ->and($user->getHidden())->toContain('password', 'two_factor_secret', 'search_index')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

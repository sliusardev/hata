<?php

use App\Models\Member;
use App\Models\PartlyGuardedMember;
use App\Models\UnguardedMember;
use App\Models\User;
use Hata\Hata;
use Hata\Policies\UserPolicy;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

it('uses the model configured as the auth user', function () {
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
    $user = User::create(['name' => 'Host Person', 'email' => 'host@example.test', 'password' => 'secret-password', 'locale' => 'uk', 'nickname' => 'x']);

    expect($user->fresh())
        ->nickname->toBe('x')
        ->locale->toBe('uk')
        ->and($user->getCasts())->toMatchArray(['nickname' => 'string', 'password' => 'hashed', 'email_verified_at' => 'datetime'])
        ->and($user->getHidden())->toContain('password', 'two_factor_secret', 'search_index')
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('leaves an unguarded host model fillable everywhere', function () {
    $member = UnguardedMember::create(['name' => 'Open Person', 'email' => 'open@example.test', 'password' => 'secret-password', 'title' => 'Editor']);

    expect($member->getFillable())->toBe([])
        ->and($member->fresh()?->title)->toBe('Editor');
});

it('leaves a host model that guards only some columns fillable for the others', function () {
    $member = PartlyGuardedMember::create(['id' => 4242, 'name' => 'Partly Open', 'email' => 'partly@example.test', 'password' => 'secret-password', 'title' => 'Editor']);

    expect($member->getFillable())->toBe([])
        ->and($member->id)->not->toBe(4242)
        ->and($member->fresh()?->title)->toBe('Editor')
        ->and(Hash::check('secret-password', (string) $member->fresh()?->password))->toBeTrue();
});

it('decides the users pages with hata\'s policy over a policy laravel guesses for the host model', function () {
    // workbench/app/Policies/UserPolicy.php denies everything and is where Laravel guesses a policy for App\Models\User
    expect(Gate::getPolicyFor(User::class))->toBeInstanceOf(UserPolicy::class);

    $actor = User::factory()->withPermissions(['admin.access', 'users.view', 'users.edit'])->create();
    $other = User::factory()->create();

    $this->actingAs($actor)->get(route('admin.users.index'))->assertOk();
    $this->actingAs($actor)->get(route('admin.users.edit', $other))->assertOk()->assertSee($other->email);
});

it('answers 404 for the edit page of a user that does not exist', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'users.view', 'users.edit'])->create();

    $this->actingAs($actor)->get(route('admin.users.edit', 999999))->assertNotFound();
});

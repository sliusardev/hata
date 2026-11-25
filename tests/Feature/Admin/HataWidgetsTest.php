<?php

use App\Models\User;
use Hata\Models\Entry;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\PostsType;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
});

function entryQueries(callable $callback): int
{
    DB::enableQueryLog();
    $callback();
    $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'entries'))->count();
    DB::disableQueryLog();

    return $count;
}

it('the content widget counts published and draft records per visible type in one query', function () {
    Entry::factory()->count(2)->create(['type' => 'pages', 'status' => 'published']);
    Entry::factory()->create(['type' => 'pages', 'status' => 'draft']);
    Entry::factory()->create(['type' => 'pages', 'status' => 'published'])->delete();
    $this->actingAs(User::factory()->withRole('admin')->create());

    $component = null;
    $queries = entryQueries(function () use (&$component) {
        $component = Livewire::test('hata::admin.widgets.content');
    });

    $component->assertSee('Pages')->assertSeeInOrder(['2', '1'])->assertSee(route('admin.content.index', 'pages'), false);
    expect($queries)->toBe(1);
});

it('the content widget hides types the user may not view', function () {
    app(ContentTypes::class)->register(new PostsType);
    Entry::factory()->create(['type' => 'posts', 'title' => 'Chair', 'slug' => 'chair', 'path' => 'chair']);
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());

    Livewire::test('hata::admin.widgets.content')
        ->assertSee('Pages')
        ->assertDontSee('Posts');
});

it('recent changes lists the 8 latest edited records with links', function () {
    foreach (range(1, 10) as $i) {
        Entry::factory()->create(['type' => 'pages', 'title' => "Page-{$i}", 'slug' => "p{$i}", 'path' => "p{$i}", 'updated_at' => now()->subMinutes(100 - $i)]);
    }
    $this->actingAs(User::factory()->withRole('admin')->create());

    $component = null;
    $queries = entryQueries(function () use (&$component) {
        $component = Livewire::test('hata::admin.widgets.recent-changes');
    });

    $component->assertSeeInOrder(['Page-10', 'Page-9', 'Page-3'])
        ->assertDontSee('Page-2<', false)
        ->assertDontSee('Page-1<', false);
    $latest = Entry::where('title', 'Page-10')->first();
    $component->assertSee(route('admin.content.edit', ['type' => 'pages', 'entry' => $latest]), false);
    expect($queries)->toBe(1);
});

it('a user who may view no type sees neither widget', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('admin.dashboard'))->assertOk()
        ->assertSee(__('hata::dashboard.empty.heading'))
        ->assertDontSee('data-test="dashboard-grid"', false);
});

it('a user who may view a type sees both widgets on the dashboard', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());

    $this->get(route('admin.dashboard'))->assertOk()
        ->assertSee(__('hata::dashboard.widgets.content.title'))
        ->assertSee(__('hata::dashboard.widgets.recent_changes.title'));
});

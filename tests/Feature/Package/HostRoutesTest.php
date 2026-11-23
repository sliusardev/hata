<?php

use Hata\Models\Entry;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('lets a host route win over the content fallback', function () {
    Route::get('about', fn () => 'host')->middleware('web');   // registered after Hata's routes
    Entry::factory()->page()->published()->create(['title' => 'About Hata', 'slug' => 'about', 'path' => 'about']);

    $this->get('/about')->assertOk()->assertSee('host')->assertDontSee('About Hata');
});

it('answers a content address the host does not define', function () {
    Entry::factory()->page()->published()->create(['title' => 'Contacts', 'slug' => 'contacts', 'path' => 'contacts']);

    $this->get('/contacts')->assertOk()->assertSee('Contacts');
});

it('renders hata\'s flux overrides', function () {
    // A template of its own every time: Blaze folds a component into the compiled template, and a compiled template
    // stays valid while the files it was folded from are unchanged, so a stale one would hide which file is used now.
    $heading = 'Content '.Str::random(12);
    $group = Blade::render("<flux:navlist.group heading=\"{$heading}\">Pages</flux:navlist.group>");
    $icon = Blade::render('<flux:icon.layout-grid />');

    // Hata's group heading is small and tight (Flux's is `px-3` and `text-sm`); Flux has no layout-grid icon at all.
    expect($group)->toContain("<div class=\"text-xs leading-none text-zinc-400\">{$heading}</div>")->not->toContain('px-3 py-2')
        ->and($icon)->toContain('<rect width="7" height="7" x="14" y="14" rx="1" />');
});

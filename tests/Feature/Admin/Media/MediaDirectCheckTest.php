<?php

use App\Models\User;
use Hata\Support\Media\DirectServing;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRolesAndPermissions();
    Storage::fake('public');
    Storage::fake('local');
    config([
        'hata.media.disk' => 'public',
        'filesystems.disks.public.driver' => 'local',
        'filesystems.disks.public.url' => 'https://example.test/storage',
    ]);
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/direct-check-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

/**
 * Make the real DirectServing see a link to the public disk's root, so `state()` can leave `link_missing`.
 */
function linkPublicDiskForCheck(): void
{
    $base = sys_get_temp_dir().'/direct-check-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($base.'/root');
    symlink($base.'/root', $base.'/link');

    config([
        'filesystems.disks.public.root' => $base.'/root',
        'filesystems.links' => [$base.'/link' => $base.'/root'],
    ]);
    app()->forgetInstance(DirectServing::class);
}

function settingsEditor(): User
{
    return User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();
}

it('refuses users without settings.edit', function () {
    linkPublicDiskForCheck();
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->postJson(route('admin.media.direct-check'), ['ok' => true, 'url' => app(DirectServing::class)->probeUrl()])
        ->assertForbidden();

    expect(app(DirectServing::class)->state())->toBe('unchecked');
});

it('validates the body', function () {
    $this->actingAs(settingsEditor());

    $this->postJson(route('admin.media.direct-check'), ['ok' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ok', 'url']);
});

it('stores direct when the probe of the right url succeeded', function () {
    linkPublicDiskForCheck();
    $this->actingAs(settingsEditor());

    $this->postJson(route('admin.media.direct-check'), ['ok' => true, 'url' => app(DirectServing::class)->probeUrl()])
        ->assertOk()
        ->assertExactJson(['state' => 'direct']);

    expect(app(SiteSettings::class)->stored()['media_direct_state'])->toBe('ok');
});

it('stores unreachable when the probe failed', function () {
    linkPublicDiskForCheck();
    $this->actingAs(settingsEditor());

    $this->postJson(route('admin.media.direct-check'), ['ok' => false, 'url' => app(DirectServing::class)->probeUrl()])
        ->assertOk()
        ->assertExactJson(['state' => 'unreachable']);
});

it('does not accept a success reported for another url', function () {
    linkPublicDiskForCheck();
    $this->actingAs(settingsEditor());

    $this->postJson(route('admin.media.direct-check'), ['ok' => true, 'url' => 'https://elsewhere.test/storage/media/probe.png'])
        ->assertOk()
        ->assertExactJson(['state' => 'unchecked']);

    expect(app(SiteSettings::class)->stored())->not->toHaveKey('media_direct_state');
});

it('records a forced check again although the same result was just stored', function () {
    linkPublicDiskForCheck();
    $this->actingAs(settingsEditor());
    $this->freezeSecond();
    $url = app(DirectServing::class)->probeUrl();

    $this->postJson(route('admin.media.direct-check'), ['ok' => false, 'url' => $url])->assertOk();
    $first = app(SiteSettings::class)->value('media_direct_checked_at');
    $this->travel(5)->minutes();

    $this->postJson(route('admin.media.direct-check'), ['ok' => false, 'url' => $url])->assertOk();
    expect(app(SiteSettings::class)->value('media_direct_checked_at'))->toBe($first);

    $this->postJson(route('admin.media.direct-check'), ['ok' => false, 'url' => $url, 'force' => true])->assertOk();
    expect(app(SiteSettings::class)->value('media_direct_checked_at'))->not->toBe($first);
});

describe('csrf', function () {
    beforeEach(function () {
        // The real check: Laravel skips it while running tests, which would hide a missing or wrong header.
        app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    });

    it('accepts the report with the token from the rendered probe config in X-CSRF-TOKEN', function () {
        linkPublicDiskForCheck();
        $this->actingAs(settingsEditor());

        $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $token = Str::betweenFirst((string) $html, '\u0022csrf\u0022:\u0022', '\u0022');
        expect($token)->toBe(session()->token());

        $this->postJson(route('admin.media.direct-check'), ['ok' => true, 'url' => app(DirectServing::class)->probeUrl()], ['X-CSRF-TOKEN' => $token])
            ->assertOk()
            ->assertExactJson(['state' => 'direct']);
    });

    it('refuses the report without a token', function () {
        linkPublicDiskForCheck();
        $this->actingAs(settingsEditor());
        $this->get(route('admin.dashboard'))->assertOk();

        $this->postJson(route('admin.media.direct-check'), ['ok' => true, 'url' => app(DirectServing::class)->probeUrl()])
            ->assertStatus(419);

        expect(app(SiteSettings::class)->stored())->not->toHaveKey('media_direct_state');
    });
});

describe('probe config in the layout', function () {
    it('is rendered for a settings editor while a check is due', function () {
        linkPublicDiskForCheck();
        $this->actingAs(settingsEditor());

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('mediaDirectProbe', false)
            ->assertSee('example.test', false)
            ->assertSee('direct-check', false)
            ->assertSee('\u0022auto\u0022:true', false)
            ->assertSee('\u0022width\u0022:'.app(DirectServing::class)->probeSize()['width'].',', false)
            ->assertSee('\u0022height\u0022:'.app(DirectServing::class)->probeSize()['height'].',', false)
            ->assertSee('\u0022csrf\u0022:\u0022'.session()->token().'\u0022', false)
            ->assertDontSee('\u0022token\u0022', false);
        Storage::disk('public')->assertExists(DirectServing::PROBE_PATH);
    });

    it('is not rendered again within 30 minutes when the report never came, and is after', function () {
        linkPublicDiskForCheck();
        $this->actingAs(settingsEditor());

        $this->get(route('admin.dashboard'))->assertSee('\u0022auto\u0022:true', false);
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('mediaDirectProbe', false);

        $this->travel(31)->minutes();
        $this->get(route('admin.dashboard'))->assertSee('\u0022auto\u0022:true', false);
    });

    it('is not rendered again within 30 minutes of a failed probe', function () {
        linkPublicDiskForCheck();
        $this->actingAs(settingsEditor());
        $this->postJson(route('admin.media.direct-check'), ['ok' => false, 'url' => app(DirectServing::class)->probeUrl()])->assertOk();

        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('mediaDirectProbe', false);
    });

    it('is not rendered without settings.edit', function () {
        linkPublicDiskForCheck();
        $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('mediaDirectProbe', false);
        Storage::disk('public')->assertMissing(DirectServing::PROBE_PATH);
    });

    it('is not rendered when no check is due', function () {
        linkPublicDiskForCheck();
        app(DirectServing::class)->record(true, 'https://example.test/storage');
        $this->actingAs(settingsEditor());

        expect(app(DirectServing::class)->dueForCheck())->toBeFalse();
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('mediaDirectProbe', false);
    });

    it('is not rendered when the site disk does not publish files', function () {
        linkPublicDiskForCheck();
        config(['hata.media.disk' => 'local']);
        $this->actingAs(settingsEditor());

        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('mediaDirectProbe', false);
    });

    it('does not break the page when the probe file cannot be written, and reports it at most once an hour', function () {
        linkPublicDiskForCheck();
        $this->actingAs(settingsEditor());
        Exceptions::fake();
        Storage::shouldReceive('disk')->andThrow(new RuntimeException('read-only'));

        $this->get(route('admin.settings.edit'))->assertOk()->assertDontSee('mediaDirectProbe', false);
        $this->get(route('admin.settings.edit'))->assertOk()->assertDontSee('mediaDirectProbe', false);
        Exceptions::assertReportedCount(1);

        $this->travel(61)->minutes();
        $this->get(route('admin.settings.edit'))->assertOk();
        Exceptions::assertReportedCount(2);
    });
});

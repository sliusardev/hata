<?php

use App\Models\User;
use Hata\Actions\Media\DeleteMedia;
use Hata\Actions\Settings\RemoveShareImage;
use Hata\Actions\Settings\SetShareImage;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Hata\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
    Storage::fake('local');
});

function shareMedia(string $name = 'share.png', string $mime = 'image/png'): Media
{
    $media = Media::factory()->image()->create(['name' => $name, 'mime' => $mime]);
    Storage::disk($media->disk)->put($media->path, 'file');

    return $media;
}

it('stores the share image and records its usage', function () {
    $actor = User::factory()->withRole('admin')->create();
    $media = shareMedia();

    app(SetShareImage::class)->handle($actor, $media->id);

    $setting = Setting::query()->where('key', 'og_image')->firstOrFail();
    expect($setting->value)->toBe($media->id)
        ->and(config('hata.seo.image'))->toBe($media->id)
        ->and(MediaUsage::query()->where('media_id', $media->id)->where('field', 'og_image')->where('usable_id', $setting->id)->count())->toBe(1);
});

it('refuses an SVG and a document', function (string $case) {
    $actor = User::factory()->withRole('admin')->create();
    $id = $case === 'svg' ? shareMedia('logo.svg', 'image/svg+xml')->id : Media::factory()->document()->create()->id;

    expect(fn () => app(SetShareImage::class)->handle($actor, $id))->toThrow(ValidationException::class)
        ->and(Setting::query()->where('key', 'og_image')->exists())->toBeFalse();
})->with(['svg', 'document']);

it('without media.view keeps only the current image', function () {
    $media = shareMedia();
    $other = shareMedia('other.png');
    app(SetShareImage::class)->handle(User::factory()->withRole('admin')->create(), $media->id);
    $actor = User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();

    expect(fn () => app(SetShareImage::class)->handle($actor, $other->id))->toThrow(AuthorizationException::class);

    app(SetShareImage::class)->handle($actor, $media->id);
    expect(config('hata.seo.image'))->toBe($media->id);
});

it('needs settings.edit', function () {
    $actor = User::factory()->withPermissions(['admin.access'])->create();

    expect(fn () => app(SetShareImage::class)->handle($actor, shareMedia()->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveShareImage::class)->handle($actor))->toThrow(AuthorizationException::class);
});

it('removes the image and its usage', function () {
    $actor = User::factory()->withRole('admin')->create();
    $media = shareMedia();
    app(SetShareImage::class)->handle($actor, $media->id);

    app(RemoveShareImage::class)->handle($actor);

    expect(Setting::query()->where('key', 'og_image')->exists())->toBeFalse()
        ->and(MediaUsage::count())->toBe(0)
        ->and(Media::find($media->id))->not->toBeNull();
});

it('is cleared when its file is deleted from the library', function () {
    $actor = User::factory()->withRole('admin')->create();
    $media = shareMedia();
    app(SetShareImage::class)->handle($actor, $media->id);

    app(DeleteMedia::class)->handle($actor, $media);

    expect(Setting::query()->where('key', 'og_image')->exists())->toBeFalse()
        ->and(config('hata.seo.image'))->toBeNull();
});

it('shows the usage in the media details with a link to the Site section', function () {
    $actor = User::factory()->withRole('admin')->create();
    $this->actingAs($actor);
    $media = shareMedia();
    app(SetShareImage::class)->handle($actor, $media->id);

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSee(__('hata::site_settings.seo.usage'))
        ->assertSeeHtml('href="'.route('admin.settings.edit', ['tab' => 'site']).'"');
});

<?php

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('backfills the animated column from the stored files', function () {
    Storage::fake('local');

    $make = fn (string $mime, ?string $contents) => tap(
        Media::factory()->create(['disk' => 'local', 'mime' => $mime]),
        fn (Media $media) => $contents === null || Storage::disk('local')->put($media->path, $contents),
    );

    $animated = $make('image/gif', animatedGif());
    $static = $make('image/png', makeImage('png'));
    $missing = $make('image/gif', null);

    Schema::table('media', fn ($table) => $table->dropColumn('animated'));

    (require database_path('migrations/2026_11_11_164228_add_animated_to_media_table.php'))->up();

    expect(Media::find($animated->id)->animated)->toBeTrue()
        ->and(Media::find($static->id)->animated)->toBeFalse()
        ->and(Media::find($missing->id)->animated)->toBeFalse();
});

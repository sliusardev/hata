<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class UpdateMedia
{
    /**
     * @param  array{title?: ?string, alt?: array<string, string>, folder_id?: ?int}  $data
     */
    public function handle(User $actor, Media $media, array $data): Media
    {
        Gate::forUser($actor)->authorize('update', $media);

        $locales = array_keys((array) config('app.available_locales'));

        if (array_key_exists('alt', $data)) {
            $data['alt'] = array_intersect_key($data['alt'], array_flip($locales));
        }

        $validated = Validator::make($data, [
            'title' => ['nullable', 'string', 'max:255'],
            'alt' => ['array'],
            'alt.*' => ['nullable', 'string', 'max:500'],
            'folder_id' => ['nullable', 'integer', 'exists:media_folders,id'],
        ])->validate();

        $media->fill($validated)->save();

        return $media;
    }
}

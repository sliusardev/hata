<?php

namespace Hata\Actions\Media;

use App\Models\User;
use Hata\Models\Media;
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

        $validated = Validator::make($data, [
            'title' => ['nullable', 'string', 'max:255'],
            'alt' => ['array'],
            'alt.*' => ['nullable', 'string', 'max:500'],
            'folder_id' => ['nullable', 'integer', 'exists:media_folders,id'],
        ])->validate();

        if (array_key_exists('alt', $validated)) {
            $locales = array_keys((array) config('hata.admin.interface_locales'));
            $validated['alt'] = array_map(
                fn (?string $text): string => trim((string) $text),
                array_intersect_key($validated['alt'], array_flip($locales)),
            );
        }

        if (array_key_exists('title', $validated)) {
            $validated['title'] = $validated['title'] === null || trim($validated['title']) === '' ? null : $validated['title'];
        }

        $media->fill($validated)->save();

        return $media;
    }
}

<?php

namespace App\Livewire\Forms;

use App\Models\Media;
use Livewire\Form;

/**
 * The editable fields of the media details panel. Validation happens in UpdateMedia.
 */
class MediaDetailsForm extends Form
{
    public string $title = '';

    /**
     * Alt text per available locale. The browser can send anything here, so payload() keeps only strings.
     *
     * @var array<string, mixed>
     */
    public array $alt = [];

    /**
     * The folder id as the select sends it; '' means "No folder".
     */
    public string $folder_id = '';

    public function fillFromMedia(Media $media): void
    {
        $this->title = $media->title ?? '';
        $this->alt = [];

        foreach (array_keys((array) config('app.available_locales')) as $locale) {
            $this->alt[(string) $locale] = $media->altFor((string) $locale, fallback: false);
        }

        $this->folder_id = $media->folder_id === null ? '' : (string) $media->folder_id;
    }

    /**
     * @return array{title: ?string, alt: array<string, string>, folder_id: ?int}
     */
    public function payload(): array
    {
        return [
            'title' => $this->title,
            'alt' => array_map(fn (mixed $text): string => is_string($text) ? $text : '', $this->alt),
            'folder_id' => $this->folder_id === '' ? null : (int) $this->folder_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $saved
     */
    public function differsFrom(array $saved): bool
    {
        return $this->all() != $saved;
    }
}

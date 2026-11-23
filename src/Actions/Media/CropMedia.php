<?php

namespace Hata\Actions\Media;

use App\Models\User;
use Hata\Exceptions\MediaEditException;
use Hata\Models\Media;
use Hata\Support\Media\MediaSource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CropMedia
{
    private const float TOLERANCE = 1e-9;

    public function __construct(private RebuildMediaFile $rebuilder) {}

    /**
     * Crop, rotate, flip and/or limit the size of an image, in place or as a new copy.
     *
     * With a kept original the edits are stored and always applied to the original, so a new crop replaces the old one.
     * Without one they are baked into the file (`edits` stays null) and the next crop works on the result.
     *
     * @param  array<string, mixed>  $input  `crop` ({x, y, w, h} as fractions 0–1 of the rotated and flipped frame, or null),
     *                                       `rotate` (0/90/180/270), `flip_h`, `flip_v`, `size` ({w, h} or null).
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Media $media, array $input, bool $asCopy = false): Media
    {
        $asCopy
            ? Gate::forUser($actor)->authorize('create', Media::class)
            : Gate::forUser($actor)->authorize('update', $media);

        $data = $this->validate($input);

        if (! $media->isEditable()) {
            throw ValidationException::withMessages(['crop' => __('hata::media.errors.not_editable')]);
        }

        try {
            $source = MediaSource::for($media);
            $edits = $this->pixelEdits($source, $data);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['crop' => __('hata::media.errors.not_editable')]);
        }

        $keepEdits = $source->isOriginal;

        try {
            if ($asCopy) {
                return $this->copy($actor, $media, $source, $edits, $keepEdits);
            }

            return $this->rebuilder->handle($media, $source->contents, $source->mime, $edits, keepEdits: $keepEdits);
        } catch (MediaEditException $e) {
            throw ValidationException::withMessages([
                'crop' => $e->failure === 'too_large' ? __('hata::media.errors.too_large_to_edit') : __('hata::media.errors.not_editable'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $edits
     */
    private function copy(User $actor, Media $media, MediaSource $source, array $edits, bool $keepEdits): Media
    {
        $copy = new Media([
            'folder_id' => $media->folder_id,
            'uploaded_by' => $actor->id,
            'disk' => $media->disk,
            'path' => '',
            // No extension, so the rebuild gives it a unique name with the extension of the result.
            'name' => pathinfo($media->name, PATHINFO_FILENAME),
            'kind' => $media->kind,
            'mime' => $media->mime,
            'size' => 0,
            'original_size' => $media->original_size,
            'uploaded_extension' => $media->uploaded_extension,
            'version' => 0,
            'alt' => $media->alt,
            'title' => $media->title,
        ]);
        $copy->key = (string) Str::ulid();

        return $this->rebuilder->handle(
            $copy,
            $source->contents,
            $source->mime,
            $edits,
            newOriginal: $source->isOriginal ? $source->contents : null,
            keepEdits: $keepEdits,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{crop: array{x: float, y: float, w: float, h: float}|null, rotate: int, flip_h: bool, flip_v: bool, size: array{w: int, h: int}|null}
     *
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $validator = Validator::make($input, [
            'crop' => ['nullable', 'array'],
            'crop.x' => ['required_with:crop', 'numeric'],
            'crop.y' => ['required_with:crop', 'numeric'],
            'crop.w' => ['required_with:crop', 'numeric'],
            'crop.h' => ['required_with:crop', 'numeric'],
            'rotate' => ['nullable', 'integer', 'in:0,90,180,270'],
            'flip_h' => ['nullable', 'boolean'],
            'flip_v' => ['nullable', 'boolean'],
            'size' => ['nullable', 'array'],
            'size.w' => ['required_with:size', 'integer', 'min:1'],
            'size.h' => ['required_with:size', 'integer', 'min:1'],
        ], attributes: [
            'crop' => 'crop',
            'rotate' => 'rotate',
            'size' => 'size',
        ]);

        $validator->after(function ($validator) use ($input): void {
            $crop = $input['crop'] ?? null;

            if ($validator->errors()->isNotEmpty() || ! is_array($crop)) {
                return;
            }

            foreach ([['x', 'w'], ['y', 'h']] as [$offset, $length]) {
                $start = (float) $crop[$offset];
                $span = (float) $crop[$length];

                if ($start < 0 || $start >= 1 || $span <= 0 || $span > 1 - $start + self::TOLERANCE) {
                    $validator->errors()->add('crop', __('hata::media.errors.invalid_crop'));

                    return;
                }
            }
        });

        if ($validator->fails()) {
            $messages = [];

            foreach ($validator->errors()->messages() as $field => $errors) {
                $messages[explode('.', $field)[0]] = [...($messages[explode('.', $field)[0]] ?? []), ...$errors];
            }

            throw ValidationException::withMessages($messages);
        }

        $crop = $input['crop'] ?? null;
        $size = $input['size'] ?? null;

        return [
            'crop' => is_array($crop)
                ? ['x' => (float) $crop['x'], 'y' => (float) $crop['y'], 'w' => (float) $crop['w'], 'h' => (float) $crop['h']]
                : null,
            'rotate' => (int) ($input['rotate'] ?? 0),
            'flip_h' => (bool) ($input['flip_h'] ?? false),
            'flip_v' => (bool) ($input['flip_v'] ?? false),
            'size' => is_array($size) ? ['w' => (int) $size['w'], 'h' => (int) $size['h']] : null,
        ];
    }

    /**
     * A crop given as fractions of the rotated frame, in pixels of the rotated frame of a source of the given
     * (oriented) size. The crop page uses it for the result size, so the two always agree.
     *
     * @param  array{x: float, y: float, w: float, h: float}  $crop
     * @return array{x: int, y: int, w: int, h: int}
     */
    public static function cropPixels(array $crop, int $rotate, int $sourceWidth, int $sourceHeight): array
    {
        [$width, $height] = $rotate % 180 === 90 ? [$sourceHeight, $sourceWidth] : [$sourceWidth, $sourceHeight];

        $x = min((int) round($crop['x'] * $width), $width - 1);
        $y = min((int) round($crop['y'] * $height), $height - 1);

        return [
            'x' => $x,
            'y' => $y,
            'w' => max(1, min((int) round($crop['w'] * $width), $width - $x)),
            'h' => max(1, min((int) round($crop['h'] * $height), $height - $y)),
        ];
    }

    /**
     * The edits with the crop in pixels of the oriented, rotated source.
     *
     * @param  array{crop: array{x: float, y: float, w: float, h: float}|null, rotate: int, flip_h: bool, flip_v: bool, size: array{w: int, h: int}|null}  $data
     * @return array{rotate: int, flip_h: bool, flip_v: bool, crop: array{x: int, y: int, w: int, h: int}|null, size: array{w: int, h: int}|null}
     */
    private function pixelEdits(MediaSource $source, array $data): array
    {
        $crop = null;

        if ($data['crop'] !== null) {
            [$width, $height] = $source->orientedSize();
            $crop = self::cropPixels($data['crop'], $data['rotate'], $width, $height);
        }

        return [
            'rotate' => $data['rotate'],
            'flip_h' => $data['flip_h'],
            'flip_v' => $data['flip_v'],
            'crop' => $crop,
            'size' => $data['size'],
        ];
    }
}

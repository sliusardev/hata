<?php

use App\Actions\Media\CropMedia;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\ImageEdits;
use App\Support\Media\MediaSource;
use App\Support\Media\MemoryGuard;
use App\Support\Toast;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('media.crop.title'), Layout('layouts::focus')] class extends Component {
    /**
     * Aspect ratios of the crop box (null = free). "Social" is a 1200 × 630 cover and also sets that output size.
     */
    public const array RATIOS = [
        'free' => null,
        '1:1' => 1,
        '4:3' => 4 / 3,
        '3:2' => 3 / 2,
        '16:9' => 16 / 9,
        'social' => 1200 / 630,
    ];

    public const array SOCIAL_SIZE = [1200, 630];

    #[Locked]
    public int $mediaId;

    /**
     * The edits as the cropper shows them: rotate (0/90/180/270), flip_h and flip_v (in the rotated frame, as seen),
     * and crop ({x, y, w, h} as fractions of the rotated and flipped frame, or null for the whole image).
     * The cropper writes them from the browser, so every use re-checks them (CropMedia validates them again).
     *
     * @var array{rotate: mixed, flip_h: mixed, flip_v: mixed, crop: mixed}
     */
    public array $edits = ['rotate' => 0, 'flip_h' => false, 'flip_v' => false, 'crop' => null];

    public string $ratio = 'free';

    /**
     * The optional exact output size; an empty side follows the crop's aspect ratio.
     */
    public ?int $width = null;

    public ?int $height = null;

    /**
     * Width and height of the source (the kept original, else the current file) after its EXIF orientation.
     */
    #[Locked]
    public int $sourceWidth = 0;

    #[Locked]
    public int $sourceHeight = 0;

    /**
     * True when the server can't decode the source (too large for memory_limit, or unreadable).
     */
    #[Locked]
    public bool $unavailable = false;

    public bool $showApplyModal = false;

    public function mount(Media $media, MemoryGuard $memory): void
    {
        $this->authorize('update', $media);

        abort_unless($media->isEditable(), 404);

        $this->mediaId = $media->id;

        try {
            $source = MediaSource::for($media);
            // HEIC has no getimagesize() support; orientedSize() below still decodes it where Imagick can.
            $size = @getimagesizefromstring($source->contents);

            if ($size !== false && ! $memory->canDecode($size[0], $size[1])) {
                $this->unavailable = true;

                return;
            }

            [$this->sourceWidth, $this->sourceHeight] = $source->orientedSize();
        } catch (Throwable $exception) {
            report($exception);
            $this->unavailable = true;

            return;
        }

        if ($source->isOriginal) {
            $this->seed($media->edits);
        }
    }

    #[Computed]
    public function media(): Media
    {
        return Media::query()->withCount('usages')->findOrFail($this->mediaId);
    }

    /**
     * The size the current edits produce, the same way CropMedia and OptimizeImage compute it, including the max-side scale.
     *
     * @return array{int, int}|null
     */
    #[Computed]
    public function resultSize(): ?array
    {
        if ($this->unavailable) {
            return null;
        }

        $rotate = $this->rotation();
        $crop = $this->cropFractions();
        $pixels = $crop === null ? null : CropMedia::cropPixels($crop, $rotate, $this->sourceWidth, $this->sourceHeight);

        [$width, $height] = (new ImageEdits($rotate, crop: $pixels, size: $this->outputSize()))->outputSize($this->sourceWidth, $this->sourceHeight);
        $maxSide = $this->maxSide();

        if ($maxSide !== null && max($width, $height) > $maxSide) {
            $factor = $maxSide / max($width, $height);
            [$width, $height] = [max(1, (int) round($width * $factor)), max(1, (int) round($height * $factor))];
        }

        return [$width, $height];
    }

    /**
     * The longest side OptimizeImage will scale the result to, or null when it doesn't scale.
     */
    #[Computed]
    public function maxSide(): ?int
    {
        $maxSide = config('media.optimize.max_side');

        return config('media.optimize.enabled') && is_int($maxSide) && $maxSide > 0 ? $maxSide : null;
    }

    /**
     * Whether the exact size asked for is larger than the max-side scale allows.
     */
    #[Computed]
    public function sizeExceedsMaxSide(): bool
    {
        $size = $this->outputSize();

        return $this->maxSide !== null && $size !== null && max($size['w'], $size['h']) > $this->maxSide;
    }

    public function updatingRatio(mixed $value): void
    {
        if ($this->ratio === 'social' && $value !== 'social' && [$this->width, $this->height] === self::SOCIAL_SIZE) {
            $this->width = null;
            $this->height = null;
        }
    }

    public function updatedRatio(): void
    {
        if (! array_key_exists($this->ratio, self::RATIOS)) {
            $this->ratio = 'free';
        }

        if ($this->ratio === 'social') {
            [$this->width, $this->height] = self::SOCIAL_SIZE;
        }
    }

    public function confirmApply(): void
    {
        $this->resetErrorBag();
        $this->showApplyModal = true;
    }

    public function apply(CropMedia $cropMedia): void
    {
        $this->showApplyModal = false;
        $media = $this->run($cropMedia, asCopy: false);

        if ($media === null) {
            return;
        }

        Toast::success(__('media.crop.messages.applied'));
        $this->redirectToLibrary($media);
    }

    public function saveCopy(CropMedia $cropMedia): void
    {
        $copy = $this->run($cropMedia, asCopy: true);

        if ($copy === null) {
            return;
        }

        Toast::success(__('media.crop.messages.copied', ['name' => $copy->name]));
        $this->redirectToLibrary($copy);
    }

    private function run(CropMedia $cropMedia, bool $asCopy): ?Media
    {
        $this->validate([
            'width' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ], attributes: [
            'width' => __('media.crop.width'),
            'height' => __('media.crop.height'),
        ]);

        try {
            return $cropMedia->handle($this->actor(), $this->media, [
                'crop' => $this->edits['crop'],
                'rotate' => $this->edits['rotate'],
                'flip_h' => $this->edits['flip_h'] === true,
                'flip_v' => $this->edits['flip_v'] === true,
                'size' => $this->outputSize(),
            ], $asCopy);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field === 'size' ? 'size' : 'crop', $messages[0]);
            }
        } catch (AuthorizationException) {
            Flux::toast(variant: 'danger', text: __('media.errors.forbidden'));
        }

        return null;
    }

    private function redirectToLibrary(Media $media): void
    {
        $this->redirectRoute('admin.media.index', ['folder' => $media->folder_id ?? 'none'], navigate: true);
    }

    /**
     * Turn stored pixel edits (of the rotated source frame) back into the cropper's fractions.
     *
     * @param  array<string, mixed>|null  $stored
     */
    private function seed(?array $stored): void
    {
        try {
            $edits = ImageEdits::fromArray($stored);
        } catch (InvalidArgumentException) {
            return;
        }

        if ($edits === null) {
            return;
        }

        [$width, $height] = $edits->rotate % 180 === 90
            ? [$this->sourceHeight, $this->sourceWidth]
            : [$this->sourceWidth, $this->sourceHeight];

        $this->edits = [
            'rotate' => $edits->rotate,
            'flip_h' => $edits->flipH,
            'flip_v' => $edits->flipV,
            'crop' => $edits->crop === null ? null : [
                'x' => round(min($edits->crop['x'] / $width, 1), 6),
                'y' => round(min($edits->crop['y'] / $height, 1), 6),
                'w' => round(min($edits->crop['w'] / $width, 1), 6),
                'h' => round(min($edits->crop['h'] / $height, 1), 6),
            ],
        ];

        if ($edits->size !== null) {
            $this->width = $edits->size['w'];
            $this->height = $edits->size['h'];
            $this->ratio = [$this->width, $this->height] === self::SOCIAL_SIZE ? 'social' : 'free';
        }
    }

    private function rotation(): int
    {
        $rotate = $this->edits['rotate'] ?? 0;

        return is_int($rotate) && in_array($rotate, [0, 90, 180, 270], true) ? $rotate : 0;
    }

    /**
     * The crop from the browser when it is a well-formed set of fractions inside the image, else null.
     *
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function cropFractions(): ?array
    {
        $crop = $this->edits['crop'] ?? null;

        if (! is_array($crop)) {
            return null;
        }

        $values = [];

        foreach (['x', 'y', 'w', 'h'] as $key) {
            if (! is_int($crop[$key] ?? null) && ! is_float($crop[$key] ?? null)) {
                return null;
            }

            $values[$key] = (float) $crop[$key];
        }

        if ($values['x'] < 0 || $values['x'] >= 1 || $values['y'] < 0 || $values['y'] >= 1 || $values['w'] <= 0 || $values['h'] <= 0) {
            return null;
        }

        return ['x' => $values['x'], 'y' => $values['y'], 'w' => min($values['w'], 1 - $values['x']), 'h' => min($values['h'], 1 - $values['y'])];
    }

    /**
     * The exact output size, with an empty side following the aspect ratio of the crop; null when both are empty.
     *
     * @return array{w: int<1, max>, h: int<1, max>}|null
     */
    private function outputSize(): ?array
    {
        $width = $this->width !== null && $this->width > 0 ? $this->width : null;
        $height = $this->height !== null && $this->height > 0 ? $this->height : null;

        if ($width === null && $height === null) {
            return null;
        }

        if ($width === null || $height === null) {
            $rotate = $this->rotation();
            $crop = $this->cropFractions();
            [$frameWidth, $frameHeight] = $rotate % 180 === 90
                ? [$this->sourceHeight, $this->sourceWidth]
                : [$this->sourceWidth, $this->sourceHeight];
            $pixels = $crop === null ? null : CropMedia::cropPixels($crop, $rotate, $this->sourceWidth, $this->sourceHeight);
            $cropWidth = max(1, $pixels['w'] ?? $frameWidth);
            $cropHeight = max(1, $pixels['h'] ?? $frameHeight);

            $width ??= (int) round((int) $height * $cropWidth / $cropHeight);
            $height ??= (int) round($width * $cropHeight / $cropWidth);
        }

        return ['w' => max(1, $width), 'h' => max(1, $height)];
    }

    private function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $media = $this->media;
    $result = $this->resultSize;
    $canCopy = auth()->user()->can('create', App\Models\Media::class);
    $hasOriginal = filled($media->original_path);
    $libraryUrl = route('admin.media.index', ['folder' => $media->folder_id ?? 'none']);
@endphp

<div
    class="flex min-h-dvh flex-col bg-white lg:h-dvh dark:bg-zinc-900"
    x-data="mediaCropper({ ratios: @js($this::RATIOS) })"
    data-test="media-crop"
>
    <header class="flex flex-wrap items-center gap-3 border-b border-zinc-200 px-4 py-3 sm:px-5 dark:border-white/10">
        <flux:button variant="ghost" icon="arrow-left" :href="$libraryUrl" wire:navigate :aria-label="__('media.crop.back')" :tooltip="__('media.crop.back')" />
        <div class="min-w-0">
            <flux:heading size="lg" level="1">{{ __('media.crop.title') }}</flux:heading>
            <flux:text class="truncate">
                {{ $media->name }}@unless ($unavailable) · {{ $sourceWidth }} × {{ $sourceHeight }}@endunless
            </flux:text>
        </div>
        <flux:spacer />
        <div class="flex flex-wrap gap-2">
            <flux:button :href="$libraryUrl" wire:navigate>{{ __('common.actions.cancel') }}</flux:button>
            @unless ($unavailable)
                @if ($canCopy)
                    <flux:button wire:click="saveCopy" x-bind:disabled="! ready" data-test="media-crop-copy">{{ __('media.crop.save_copy') }}</flux:button>
                @endif
                <flux:button variant="primary" wire:click="confirmApply" x-bind:disabled="! ready" data-test="media-crop-apply">{{ __('media.crop.apply') }}</flux:button>
            @endunless
        </div>
    </header>

    @if ($unavailable)
        <div class="p-6">
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('media.errors.too_large_to_edit')" />
        </div>
    @else
        <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <div class="relative min-h-[60vh] flex-1 bg-zinc-800 lg:min-h-0 dark:bg-zinc-950" aria-label="{{ __('media.crop.canvas') }}" role="group">
                <div class="absolute inset-4 sm:inset-8" wire:ignore>
                    <img
                        x-ref="image"
                        src="{{ route('admin.media.source', $media) }}"
                        alt="{{ $media->altFor(app()->getLocale()) }}"
                        class="block max-h-full max-w-full opacity-0"
                    />
                    <div x-show="! ready && ! failed" class="absolute inset-0 flex items-center justify-center">
                        <flux:icon.loading class="text-zinc-300" />
                    </div>
                    <div x-show="failed" x-cloak class="absolute inset-0 flex items-center justify-center p-6">
                        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('media.crop.load_failed')" />
                    </div>
                </div>
            </div>

            <aside
                aria-label="{{ __('media.crop.options') }}"
                class="flex w-full shrink-0 flex-col gap-6 overflow-y-auto border-t border-zinc-200 p-5 lg:w-80 lg:border-s lg:border-t-0 dark:border-white/10"
            >
                <div class="space-y-2.5">
                    <flux:heading size="sm" level="2">{{ __('media.crop.ratio') }}</flux:heading>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (array_keys($this::RATIOS) as $key)
                            <flux:button
                                size="sm"
                                :variant="$ratio === $key ? 'primary' : 'outline'"
                                aria-pressed="{{ $ratio === $key ? 'true' : 'false' }}"
                                x-on:click="setRatio(@js($key))"
                                wire:key="ratio-{{ $key }}"
                            >
                                {{ match ($key) { 'free' => __('media.crop.ratios.free'), 'social' => __('media.crop.ratios.social'), default => $key } }}
                            </flux:button>
                        @endforeach
                    </div>
                    <flux:text size="sm">{{ __('media.crop.social_hint') }}</flux:text>
                </div>

                <div class="space-y-2.5">
                    <flux:heading size="sm" level="2">{{ __('media.crop.size') }}</flux:heading>
                    <div class="flex items-center gap-2">
                        <flux:input type="number" min="1" wire:model.live.debounce.400ms="width" :aria-label="__('media.crop.width')" :placeholder="__('media.crop.width')" />
                        <span aria-hidden="true" class="text-zinc-500 dark:text-zinc-400">×</span>
                        <flux:input type="number" min="1" wire:model.live.debounce.400ms="height" :aria-label="__('media.crop.height')" :placeholder="__('media.crop.height')" />
                    </div>
                    <flux:error name="width" />
                    <flux:error name="height" />
                    <flux:error name="size" />
                    <flux:text size="sm">{{ __('media.crop.size_hint') }}</flux:text>
                    @if ($this->sizeExceedsMaxSide)
                        <flux:text size="sm" data-test="media-crop-max-side-hint">{{ __('media.crop.max_side_hint', ['max' => $this->maxSide]) }}</flux:text>
                    @endif
                </div>

                <div class="space-y-2.5">
                    <flux:heading size="sm" level="2">{{ __('media.crop.transform') }}</flux:heading>
                    <div class="flex gap-2">
                        <flux:button icon="arrow-uturn-left" x-on:click="rotate(-90)" :aria-label="__('media.crop.rotate_left')" :tooltip="__('media.crop.rotate_left')" />
                        <flux:button icon="arrow-uturn-right" x-on:click="rotate(90)" :aria-label="__('media.crop.rotate_right')" :tooltip="__('media.crop.rotate_right')" />
                        <flux:button icon="arrows-right-left" x-on:click="flip('h')" :aria-label="__('media.crop.flip_h')" :tooltip="__('media.crop.flip_h')" />
                        <flux:button icon="arrows-up-down" x-on:click="flip('v')" :aria-label="__('media.crop.flip_v')" :tooltip="__('media.crop.flip_v')" />
                    </div>
                </div>

                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-[13px] dark:border-white/10 dark:bg-white/5" data-test="media-crop-result">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('media.crop.result') }}</dt>
                    <dd class="text-zinc-800 dark:text-white">{{ $result ? __('media.crop.result_size', ['width' => $result[0], 'height' => $result[1]]) : '—' }}</dd>
                </dl>

                <flux:error name="crop" />

                <div class="flex gap-2.5 rounded-lg bg-amber-50 p-3 text-[13px] leading-relaxed text-amber-900 dark:bg-amber-400/10 dark:text-amber-200">
                    <flux:icon name="information-circle" variant="micro" class="mt-0.5 shrink-0" />
                    <span>
                        @if ($media->usages_count > 0)
                            {{ trans_choice('media.crop.used_in', $media->usages_count) }}
                        @endif
                        @if ($canCopy)
                            {{ __('media.crop.copy_hint') }}
                        @endif
                        {{ $hasOriginal ? __('media.crop.original_kept') : __('media.crop.no_original') }}
                    </span>
                </div>
            </aside>
        </div>

        <x-confirm-modal
            wire:model.self="showApplyModal"
            :heading="__('media.crop.confirm.heading', ['name' => $media->name])"
            :confirm-label="__('media.crop.apply')"
            confirm-action="apply"
        >
            {{ trans_choice('media.crop.confirm.body', $media->usages_count) }}
        </x-confirm-modal>
    @endif
</div>

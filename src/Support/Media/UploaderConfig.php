<?php

namespace App\Support\Media;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\User;

/**
 * Config of the mediaUploader Alpine component (resources/js/media-uploader.js), for the library page and the picker.
 */
final class UploaderConfig
{
    /**
     * Browser resizing only runs while optimization is on; uploadCap is PHP's upload cap in bytes (null = none).
     *
     * @param  list<string>|null  $extensions  Extensions the browser may pick; null means every library extension.
     * @return array<string, mixed>
     */
    public static function for(User $actor, ?array $extensions = null): array
    {
        return [
            'canUpload' => $actor->can('create', Media::class),
            'clientResize' => config('media.optimize.enabled') && config('media.optimize.client_resize'),
            'clientSide' => (int) config('media.client_side'),
            'accept' => $extensions ?? array_keys(MediaTypes::ALLOWED),
            'types' => array_map(fn (array $type): string => $type['kind']->value, MediaTypes::ALLOWED),
            'limits' => collect(MediaKind::cases())
                ->mapWithKeys(fn (MediaKind $kind): array => [$kind->value => MediaTypes::limitBytes($kind)])
                ->all(),
            'uploadCap' => app(ServerCapabilities::class)->uploadCapBytes(),
            'locale' => str_replace('_', '-', app()->getLocale()),
            'texts' => [
                'too_large' => __('media.errors.too_large'),
                'unsupported' => __('media.errors.unsupported'),
                'failed' => __('media.upload.failed'),
                'title' => __('media.upload.title'),
                'waiting' => __('media.upload.waiting'),
                'preparing' => __('media.upload.preparing'),
                'progress' => __('media.upload.progress'),
                'compressing' => __('media.upload.compressing'),
            ],
        ];
    }
}

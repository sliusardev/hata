<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk (see config/filesystems.php) that stores the media
    | library files. The default "local" disk is private: files are served
    | through the application, so shared hosting needs no storage:link.
    | The admin panel's Media tab can switch it to "s3" (any S3-compatible
    | storage; connection settings override the AWS_* values of the s3 disk).
    |
    */

    'disk' => env('MEDIA_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Image Optimization
    |--------------------------------------------------------------------------
    |
    | Applied to uploaded raster images. "max_side" is the longest side in
    | pixels, "quality" is 1-100, "format" is the output format. EXIF data is
    | removed when "strip_exif" is on. "client_resize" shrinks images in the
    | browser before upload (to "client_side" pixels). "keep_originals" keeps
    | the uploaded file next to the optimized one.
    |
    */

    'optimize' => [
        'enabled' => true,
        'max_side' => 2560,
        'quality' => 80,
        'format' => 'webp',
        'strip_exif' => true,
        'client_resize' => true,
        'keep_originals' => true,
    ],

    'client_side' => 3840,

    /*
    |--------------------------------------------------------------------------
    | Upload Limits
    |--------------------------------------------------------------------------
    |
    | Maximum file size in megabytes. The Livewire temporary upload rule is
    | raised to the larger of the two. PHP's upload_max_filesize and
    | post_max_size must allow it too.
    |
    */

    'limits' => [
        'image_mb' => 30,
        'document_mb' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnails
    |--------------------------------------------------------------------------
    |
    | Long side of the WebP preview in pixels. Page sizes of the library are
    | constants of the page (GRID_STEP, GRID_MAX, LIST_PER_PAGE).
    |
    */

    'thumb_side' => 400,

];

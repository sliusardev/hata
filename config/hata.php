<?php

use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PagesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Content\Types\TagsType;

/*
| Hata's settings. Publish with `php artisan vendor:publish --tag=hata-config`;
| keys missing from the published file keep the package's defaults. A list
| (such as content.types) replaces the default list as a whole.
*/

return [

    'admin' => [

        /*
        |--------------------------------------------------------------------------
        | Admin Path
        |--------------------------------------------------------------------------
        |
        | The address of the admin panel, one segment of lowercase letters, digits
        | and hyphens (`cp-7f3k`), so the usual `/admin` is not probed by bots. It
        | cannot be a content language code or the first segment of another route.
        | Set it in .env as HATA_ADMIN_PATH and rebuild the route cache
        | (`php artisan optimize`). Route names stay `admin.*`.
        |
        */

        'path' => env('HATA_ADMIN_PATH', 'admin'),

        /*
        |--------------------------------------------------------------------------
        | Interface Languages
        |--------------------------------------------------------------------------
        |
        | Languages of the admin panel and sign-in pages: code => name. Each one
        | needs a hata::* translation (the package ships en and uk; add another in
        | lang/vendor/hata/<code>/). Not the content languages (see below).
        |
        */

        'interface_locales' => [
            'en' => 'English',
            'uk' => 'Українська',
        ],

        /*
        |--------------------------------------------------------------------------
        | Public Registration
        |--------------------------------------------------------------------------
        |
        | When enabled, visitors can create an account. New accounts receive the
        | default role below (none by default). Values stored in the site settings
        | (admin panel) override the ones from .env.
        |
        */

        'registration_enabled' => (bool) env('ADMIN_REGISTRATION_ENABLED', false),

        /*
        |--------------------------------------------------------------------------
        | Default Role Of Registered Users
        |--------------------------------------------------------------------------
        |
        | Name of the role given to users who register themselves. Null means no
        | role. Like the options above, it can be changed in the site settings.
        |
        */

        'default_role' => env('ADMIN_DEFAULT_ROLE'),

        /*
        |--------------------------------------------------------------------------
        | Display Timezone
        |--------------------------------------------------------------------------
        |
        | Dates are stored in app.timezone (UTC) and shown in this timezone.
        |
        */

        'timezone' => env('ADMIN_TIMEZONE', 'UTC'),

        /*
        |--------------------------------------------------------------------------
        | Color Scheme
        |--------------------------------------------------------------------------
        |
        | Colors of the admin panel and the sign-in pages, set in the site settings.
        | See Hata\Support\ColorScheme\ColorScheme.
        |
        */

        'colors' => [
            // A ColorScheme::PRESETS key or "custom" (uses accent and gray below).
            'scheme' => env('ADMIN_COLOR_SCHEME', 'neutral'),
            'accent' => null,
            'gray' => 'neutral',
            'tint_sidebar' => true,
        ],

        /*
        |--------------------------------------------------------------------------
        | Site Logo
        |--------------------------------------------------------------------------
        |
        | Ids of the media library files chosen as the light and dark theme logos
        | in the site settings. Null means the default Hata mark.
        |
        */

        'logo' => [
            'light' => null,
            'dark' => null,
        ],

        /*
        |--------------------------------------------------------------------------
        | Initial Super Admin
        |--------------------------------------------------------------------------
        |
        | Used by HataSeeder (and hata:install) to create the first super admin
        | on hosting without SSH access. Leave ADMIN_EMAIL empty to skip it, and remove the
        | password from the environment after the first deploy.
        |
        */

        'initial_admin' => [
            'name' => env('ADMIN_NAME', 'Administrator'),
            'email' => env('ADMIN_EMAIL'),
            'password' => env('ADMIN_PASSWORD'),
        ],

    ],

    'content' => [

        /*
        |--------------------------------------------------------------------------
        | Content types
        |--------------------------------------------------------------------------
        |
        | The content types of the site, one ContentType class each, registered in
        | this order by ContentServiceProvider. `php artisan make:content-type`
        | writes a new class and adds it here. A module may register its own type
        | from its service provider instead (see docs/specs/content.md).
        |
        */

        'types' => [
            PagesType::class,
            PostsType::class,
            CategoriesType::class,
            TagsType::class,
        ],

        /*
        |--------------------------------------------------------------------------
        | Content languages
        |--------------------------------------------------------------------------
        |
        | The languages of the site's content, chosen in Site settings → Languages
        | and stored as site settings (`content_locales`, `content_default_locale`).
        | With nothing stored the site has one language, the default interface
        | language. `locales` is a list of ['code' => string, 'active' => bool].
        |
        | `interface_default` is written when the site settings are applied: the
        | site's default interface language. Read it instead of `app.locale`, which
        | holds the current request's language once the middleware has run.
        |
        */

        'locales' => null,

        'default_locale' => null,

        'interface_default' => null,

        /*
        |--------------------------------------------------------------------------
        | Trash
        |--------------------------------------------------------------------------
        |
        | Days a trashed record is kept before it is deleted for good. Its path
        | stays reserved meanwhile.
        |
        */

        'trash_days' => 30,

        /*
        |--------------------------------------------------------------------------
        | Reserved slugs
        |--------------------------------------------------------------------------
        |
        | Names content may not use as the first segment of its address, besides
        | every language code and the first segment of every service route (see
        | Hata\Support\Localization\ReservedSlugs): what the web server answers
        | itself from the public folder.
        |
        */

        'reserved_slugs' => [
            'storage', 'build', 'vendor', 'index.php', 'favicon.ico', 'favicon.svg', 'apple-touch-icon.png',
            'robots.txt', 'sitemap.xml', '.well-known',
        ],

    ],

    'media' => [

        /*
        |--------------------------------------------------------------------------
        | Media Disk
        |--------------------------------------------------------------------------
        |
        | Filesystem disk (see config/filesystems.php) that stores the media
        | library files. The default "public" disk serves raster images as
        | static files through the storage:link symlink (originals, SVG and
        | documents stay on the private "local" disk and go through the app).
        | Set "local" to serve everything through the application, which needs
        | no symlink. The admin panel's Media tab can switch it to "s3" (any
        | S3-compatible storage; connection settings override the AWS_* values
        | of the s3 disk).
        |
        */

        'disk' => env('MEDIA_DISK', 'public'),

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

    ],

];

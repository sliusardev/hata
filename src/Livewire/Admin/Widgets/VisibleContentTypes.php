<?php

namespace Hata\Livewire\Admin\Widgets;

use Hata\Contracts\AdminUser;
use Hata\Models\Entry;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypes;
use Illuminate\Support\Facades\Gate;

/**
 * The active content types the signed-in user may list: what both of Hata's dashboard widgets show.
 */
trait VisibleContentTypes
{
    /**
     * @return array<string, ContentType> keyed by type key, in registry order
     */
    public static function visibleTypesFor(AdminUser $user): array
    {
        return array_filter(
            app(ContentTypes::class)->active(),
            fn (ContentType $type): bool => Gate::forUser($user)->allows('viewAny', [Entry::class, $type->key()]),
        );
    }

    public static function mayViewAnyType(AdminUser $user): bool
    {
        return self::visibleTypesFor($user) !== [];
    }
}

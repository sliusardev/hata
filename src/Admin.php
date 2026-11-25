<?php

namespace Hata;

use Hata\Support\Admin\AdminAssets;
use Hata\Support\Admin\AdminMenu;
use Hata\Support\Admin\AdminTopbar;
use Hata\Support\Admin\SettingsSections;

/**
 * The one place a site or module reaches Hata's admin extension points from: `Admin::menu()->add(...)`.
 */
final class Admin
{
    public static function menu(): AdminMenu
    {
        return app(AdminMenu::class);
    }

    public static function topbar(): AdminTopbar
    {
        return app(AdminTopbar::class);
    }

    public static function settings(): SettingsSections
    {
        return app(SettingsSections::class);
    }

    public static function assets(): AdminAssets
    {
        return app(AdminAssets::class);
    }
}

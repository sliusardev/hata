<?php

namespace Hata;

use Hata\Support\Admin\AdminAssets;
use Hata\Support\Admin\AdminMenu;
use Hata\Support\Admin\AdminTopbar;
use Hata\Support\Admin\DashboardWidgets;
use Hata\Support\Admin\Lists\TableColumns;
use Hata\Support\Admin\Lists\TableFilters;
use Hata\Support\Admin\Registry\TargetScope;
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

    public static function dashboard(): DashboardWidgets
    {
        return app(DashboardWidgets::class);
    }

    /**
     * The columns of a list: `Admin::columns('users')->add(new TableColumn(...))`; targets are `users`, `content.*`,
     * `content.@records`, `content.@taxonomies` and `content.<type key>`.
     *
     * @param  string|list<string>  $targets
     */
    public static function columns(string|array $targets): TargetScope
    {
        return app(TableColumns::class)->on($targets);
    }

    /**
     * The filters of a list: `Admin::filters('users')->add(new TableFilter(...))`; the targets are the columns' targets.
     *
     * @param  string|list<string>  $targets
     */
    public static function filters(string|array $targets): TargetScope
    {
        return app(TableFilters::class)->on($targets);
    }

    public static function assets(): AdminAssets
    {
        return app(AdminAssets::class);
    }
}

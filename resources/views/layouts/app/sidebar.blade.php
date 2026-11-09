<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="gap-0! border-e border-zinc-200 bg-zinc-50 p-0! dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header class="h-16 shrink-0 border-b border-zinc-200 px-4 dark:border-zinc-700">
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @php
                $groupClasses = 'grid [&>div:first-child]:px-3 [&>div:first-child]:pt-1 [&>div:first-child]:pb-2 [&>div:first-child]:text-[11px] [&>div:first-child]:font-semibold [&>div:first-child]:uppercase [&>div:first-child]:tracking-wider [&>div:first-child]:text-zinc-400 dark:[&>div:first-child]:text-zinc-500';
            @endphp

            <flux:sidebar.nav class="gap-6 px-3 py-5">
                @can('admin.access')
                    <flux:sidebar.group :heading="__('navigation.platform')" :class="$groupClasses">
                        <flux:sidebar.item icon="home" :icon:variant="request()->routeIs('admin.dashboard') ? 'solid' : 'outline'" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                            {{ __('navigation.dashboard') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    @canany(['users.view', 'roles.view', 'settings.edit'])
                        <flux:sidebar.group :heading="__('navigation.administration')" :class="$groupClasses">
                            @can('users.view')
                                <flux:sidebar.item icon="users" :icon:variant="request()->routeIs('admin.users.*') ? 'solid' : 'outline'" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>
                                    {{ __('navigation.users') }}
                                </flux:sidebar.item>
                            @endcan
                            @can('roles.view')
                                <flux:sidebar.item icon="shield-check" :icon:variant="request()->routeIs('admin.roles.*') ? 'solid' : 'outline'" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>
                                    {{ __('navigation.roles') }}
                                </flux:sidebar.item>
                            @endcan
                            @can('settings.edit')
                                <flux:sidebar.item icon="adjustments-horizontal" :icon:variant="request()->routeIs('admin.settings.*') ? 'solid' : 'outline'" :href="route('admin.settings.edit')" :current="request()->routeIs('admin.settings.*')" wire:navigate>
                                    {{ __('navigation.site_settings') }}
                                </flux:sidebar.item>
                            @endcan
                        </flux:sidebar.group>
                    @endcanany
                @endcan

                <flux:sidebar.group :heading="__('navigation.account')" :class="$groupClasses">
                    <flux:sidebar.item icon="user-circle" :icon:variant="request()->routeIs('profile.edit', 'language.edit', 'security.edit', 'appearance.edit') ? 'solid' : 'outline'" :href="route('profile.edit')" :current="request()->routeIs('profile.edit', 'language.edit', 'security.edit', 'appearance.edit')" wire:navigate>
                        {{ __('navigation.my_profile') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav class="px-3 pb-2">
                <flux:sidebar.item icon="arrow-top-right-on-square" :href="route('home')" target="_blank">
                    {{ __('navigation.view_site') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <div class="hidden border-t border-zinc-200 p-3 lg:block dark:border-zinc-700">
                <x-desktop-user-menu :name="auth()->user()->name" />
            </div>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('navigation.settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('navigation.log_out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        <x-flash-toast />

        @fluxScripts
    </body>
</html>

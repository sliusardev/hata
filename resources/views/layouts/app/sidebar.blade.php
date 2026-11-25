<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('hata::partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="gap-0! border-e border-sidebar-border bg-sidebar p-0!">
            <flux:sidebar.header class="h-16 shrink-0 border-b border-sidebar-border px-4">
                <x-hata::app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @php
                $groupClasses = 'grid [&>div:first-child]:px-3 [&>div:first-child]:pt-1 [&>div:first-child]:pb-2 [&>div:first-child]:text-[11px] [&>div:first-child]:font-semibold [&>div:first-child]:uppercase [&>div:first-child]:tracking-wider [&>div:first-child]:text-zinc-400 dark:[&>div:first-child]:text-zinc-500';
            @endphp

            <flux:sidebar.nav class="gap-6 px-3 py-5">
                @foreach (app(Hata\Support\Admin\AdminMenu::class)->for(auth()->user()) as $group)
                    <flux:sidebar.group :heading="__($group['label'])" :class="$groupClasses">
                        @foreach ($group['items'] as $item)
                            @php $isCurrent = $item->isCurrent(); @endphp
                            <flux:sidebar.item :icon="$item->icon" :icon:variant="$isCurrent ? 'solid' : 'outline'" :href="$item->url()" :current="$isCurrent" wire:navigate wire:key="nav-{{ $item->key }}">
                                {{ __($item->label) }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav class="px-3 pb-2 lg:hidden">
                <flux:sidebar.item icon="arrow-top-right-on-square" :href="route('home')" target="_blank">
                    {{ __('hata::navigation.view_site') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <div class="hidden border-t border-sidebar-border p-3 lg:block">
                <x-hata::desktop-user-menu :name="auth()->user()->name" />
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
                            {{ __('hata::navigation.settings') }}
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
                            {{ __('hata::navigation.log_out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <!-- Desktop top bar -->
        <flux:header class="h-16 border-b border-sidebar-border max-lg:hidden" data-test="admin-topbar">
            @include('hata::partials.admin.topbar')
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        <x-hata::flash-toast />
        @include('hata::partials.media-direct-probe')

        @fluxScripts
    </body>
</html>

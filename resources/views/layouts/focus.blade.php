{{--
    Full-screen admin layout without the sidebar, for editors that need the whole window (the crop page).
    Keeps the toast group and the flash toast of the sidebar layout, so toasts work the same way.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
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

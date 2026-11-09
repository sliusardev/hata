{{--
    The site logo: the logos uploaded in the site settings (light and dark theme), or the
    default Hata mark on an accent tile. Size it with a height class: <x-site-logo class="h-8" />.
    variant="dark" always shows the dark-theme logo (for areas with a dark background).
--}}
@props([
    'variant' => null,
])

@php
    use App\Support\Settings\SiteLogo;
@endphp

@if (SiteLogo::isCustom())
    @if ($variant === 'dark')
        <img src="{{ SiteLogo::url('dark') }}" alt="{{ config('app.name') }}" {{ $attributes->class('w-auto max-w-40 object-contain') }} />
    @else
        <img src="{{ SiteLogo::url('light') }}" alt="{{ config('app.name') }}" {{ $attributes->class('w-auto max-w-40 object-contain dark:hidden') }} />
        <img src="{{ SiteLogo::url('dark') }}" alt="{{ config('app.name') }}" {{ $attributes->class('hidden w-auto max-w-40 object-contain dark:block') }} />
    @endif
@else
    <span {{ $attributes->class([
        'flex aspect-square shrink-0 items-center justify-center rounded-md',
        'bg-white text-neutral-800' => $variant === 'dark',
        'bg-accent-content text-accent-foreground' => $variant !== 'dark',
    ]) }}>
        <x-app-logo-icon class="size-[72%]" />
    </span>
@endif

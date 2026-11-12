{{-- The settings page needs the config for its "Check again" button even when no check is due (then `auto` is false). --}}
@php
    $mediaDirectProbe = null;
    $forceMediaDirectProbe = $forceMediaDirectProbe ?? request()->routeIs('admin.settings.edit');

    if (auth()->user()?->can('settings.edit') && \App\Support\Media\MediaDisks::publishes((string) config('media.disk'))) {
        $directServing = app(\App\Support\Media\DirectServing::class);

        $due = $directServing->dueForCheck();

        if ($due || $forceMediaDirectProbe) {
            try {
                $mediaDirectProbe = [
                    'url' => $directServing->probeUrl(),
                    'token' => $directServing->probeToken(),
                    'report' => route('admin.media.direct-check'),
                    'auto' => $due,
                ];
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
@endphp

@if ($mediaDirectProbe !== null)
    <script>window.mediaDirectProbe = {{ \Illuminate\Support\Js::from($mediaDirectProbe) }};</script>
@endif

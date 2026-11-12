{{--
    The probe config for resources/js/media-direct-probe.js. The settings page needs it for its "Check again" button
    even when no check is due (then `auto` is false). Handing out an automatic probe is remembered as an attempt, so the
    next one waits DirectServing::RETRY_MINUTES even if its report never comes. A probe image that can't be written is
    reported at most once an hour and the config skipped.
--}}
@php
    $mediaDirectProbe = null;
    $forceMediaDirectProbe = $forceMediaDirectProbe ?? request()->routeIs('admin.settings.edit');

    if (auth()->user()?->can('settings.edit')
        && \App\Support\Media\MediaDisks::publishes((string) config('media.disk'))
        && \App\Support\Media\MediaDisks::publicUrl(\App\Support\Media\MediaDisks::PUBLIC) !== null) {
        $directServing = app(\App\Support\Media\DirectServing::class);

        $due = $directServing->dueForCheck();

        if ($due || $forceMediaDirectProbe) {
            try {
                $probeSize = $directServing->probeSize();
                $mediaDirectProbe = [
                    'url' => $directServing->probeUrl(),
                    'width' => $probeSize['width'],
                    'height' => $probeSize['height'],
                    'report' => route('admin.media.direct-check'),
                    'csrf' => csrf_token(),
                    'auto' => $due,
                ];

                if ($due) {
                    $directServing->markAttempt();
                }
            } catch (\Throwable $exception) {
                if (\Illuminate\Support\Facades\Cache::add('media-direct-probe-failed', true, 3600)) {
                    report($exception);
                }
            }
        }
    }
@endphp

@if ($mediaDirectProbe !== null)
    <script>window.mediaDirectProbe = {{ \Illuminate\Support\Js::from($mediaDirectProbe) }};</script>
@endif

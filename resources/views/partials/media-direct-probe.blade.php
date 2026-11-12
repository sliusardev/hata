@php
    $mediaDirectProbe = null;

    if (auth()->user()?->can('settings.edit') && \App\Support\Media\MediaDisks::publishes((string) config('media.disk'))) {
        $directServing = app(\App\Support\Media\DirectServing::class);

        if ($directServing->dueForCheck()) {
            try {
                $mediaDirectProbe = [
                    'url' => $directServing->probeUrl(),
                    'token' => $directServing->probeToken(),
                    'report' => route('admin.media.direct-check'),
                    'auto' => true,
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

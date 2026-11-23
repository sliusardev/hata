<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Media\DirectServing;
use App\Support\Media\MediaDisks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaDirectCheckController extends Controller
{
    /**
     * Store what the browser found when it loaded the probe image, and answer with the resulting state. A result is
     * recorded (against the public disk's base URL) only when the reported URL is the probe URL the server expects, so
     * a report for another address changes nothing. `force` (the "Check again" button) records even a result that
     * repeats one stored less than DirectServing::RETRY_MINUTES ago.
     */
    public function __invoke(Request $request, DirectServing $directServing): JsonResponse
    {
        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'url' => ['required', 'string', 'max:2048'],
            'force' => ['sometimes', 'boolean'],
        ]);

        if ($data['url'] === $directServing->probeUrl()) {
            $directServing->record((bool) $data['ok'], (string) MediaDisks::publicUrl(MediaDisks::PUBLIC), $request->boolean('force'));
        }

        return response()->json(['state' => $directServing->state()]);
    }
}

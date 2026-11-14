<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Support\Maintenance\LogFiles;
use App\Support\Maintenance\LogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceLogChunkController extends Controller
{
    /**
     * One chunk of a log file for the viewer: the end of the file, or the part above `before`.
     *
     * The viewer fetches its content here instead of rendering it in the Livewire page, so a log never
     * travels in the component's responses.
     */
    public function __invoke(Request $request, string $file, LogFiles $logFiles, LogReader $logReader): JsonResponse
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:0']]);

        $path = $logFiles->resolve($file);

        abort_if($path === null, 404);

        $chunk = $logReader->chunk($path, isset($data['before']) ? (int) $data['before'] : null);

        return response()->json([
            ...$chunk,
            'remaining' => $chunk['start'] > 0 ? Media::formatBytes($chunk['start']) : null,
        ])->header('Cache-Control', 'no-store');
    }
}

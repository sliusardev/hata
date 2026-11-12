<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Maintenance\RunMaintenanceAction;
use App\Enums\MaintenanceAction;
use App\Enums\MaintenanceStatus;
use App\Exceptions\MaintenanceException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceRunController extends Controller
{
    /**
     * The session key that carries the id of the run just made to the page that shows it.
     */
    public const string LAST_RUN_KEY = 'maintenance.last_run';

    /**
     * Run one maintenance action and go back to the page, which shows the result.
     *
     * A plain request on purpose, not a Livewire action: `optimize` boots a second application inside the
     * process, which resets Livewire's registry of the components alive in the request (their response then
     * loses its redirect and state), and the cache commands delete the compiled views a component renders
     * from. A controller that only redirects depends on neither.
     */
    public function __invoke(Request $request, RunMaintenanceAction $runMaintenanceAction): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::enum(MaintenanceAction::class)],
            'seeder' => ['nullable', 'string', 'max:255'],
        ]);

        $action = MaintenanceAction::from($data['action']);
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);

        try {
            $run = $runMaintenanceAction->handle($actor, $action, $action === MaintenanceAction::Seed ? ($data['seeder'] ?? '') : null);
        } catch (MaintenanceException $exception) {
            Toast::danger($exception->getMessage());

            return to_route('admin.maintenance');
        }

        $succeeded = $run->status === MaintenanceStatus::Succeeded;
        $text = __($succeeded ? 'maintenance.messages.succeeded' : 'maintenance.messages.failed', ['action' => $run->title()]);

        $succeeded ? Toast::success($text) : Toast::danger($text);

        return to_route('admin.maintenance')->with(self::LAST_RUN_KEY, $run->id);
    }
}

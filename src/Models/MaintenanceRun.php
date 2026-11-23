<?php

namespace App\Models;

use App\Enums\MaintenanceAction;
use App\Enums\MaintenanceStatus;
use Database\Factories\MaintenanceRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of a maintenance action: who started it, what ran and how it ended.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $user_name
 * @property MaintenanceAction $action
 * @property string|null $parameter
 * @property MaintenanceStatus $status
 * @property string|null $slot
 * @property int|null $exit_code
 * @property string|null $output
 * @property bool $output_truncated
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['user_id', 'user_name', 'action', 'parameter', 'status', 'slot', 'exit_code', 'output', 'output_truncated', 'started_at', 'finished_at'])]
class MaintenanceRun extends Model
{
    /** @use HasFactory<MaintenanceRunFactory> */
    use HasFactory;

    /**
     * The value of `slot` while a run is in progress.
     */
    public const string SLOT = 'run';

    /**
     * A run that has not reported back for this long is taken for dead: it stops blocking and reads as interrupted.
     */
    public const int STALE_MINUTES = 30;

    /**
     * Every column but the output, for lists.
     *
     * @var list<string>
     */
    public const array LIST_COLUMNS = ['id', 'user_id', 'user_name', 'action', 'parameter', 'status', 'exit_code', 'output_truncated', 'started_at', 'finished_at'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => MaintenanceAction::class,
            'status' => MaintenanceStatus::class,
            'output_truncated' => 'boolean',
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The status to show: a run still marked as running long after it started is an interrupted one.
     */
    public function shownStatus(): MaintenanceStatus
    {
        return $this->status === MaintenanceStatus::Running && $this->started_at->lt(now()->subMinutes(self::STALE_MINUTES))
            ? MaintenanceStatus::Interrupted
            : $this->status;
    }

    /**
     * The action's name, with the seeder it ran.
     */
    public function title(): string
    {
        return $this->parameter === null ? $this->action->label() : $this->action->label().': '.$this->parameter;
    }

    public function seconds(): ?float
    {
        return $this->finished_at === null ? null : round($this->started_at->diffInMilliseconds($this->finished_at) / 1000, 1);
    }
}

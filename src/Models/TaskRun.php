<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Models;

use Carbon\CarbonInterface;
use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\RunTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property RunStatus $status
 * @property RunTrigger $trigger
 * @property Carbon|null $expected_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $exit_code
 * @property int|null $memory_peak
 * @property string|null $hostname
 * @property string|null $output
 * @property string|null $exception
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MonitoredTask $task
 */
class TaskRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => RunStatus::class,
        'trigger' => RunTrigger::class,
        'expected_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'exit_code' => 'integer',
        'memory_peak' => 'integer',
    ];

    protected $attributes = [
        'trigger' => 'schedule',
    ];

    public function getTable(): string
    {
        return config('chronoview.table_prefix', 'chronoview_') . 'runs';
    }

    public function getConnectionName(): ?string
    {
        return config('chronoview.connection') ?? parent::getConnectionName();
    }

    /**
     * @return BelongsTo<MonitoredTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(MonitoredTask::class, 'task_id');
    }

    /**
     * @param  Builder<static>  $query
     *
     * @return Builder<static>
     */
    public function scopeProblems(Builder $query): Builder
    {
        return $query->whereIn('status', [RunStatus::Failed->value, RunStatus::Missed->value]);
    }

    /**
     * @param  Builder<static>  $query
     *
     * @return Builder<static>
     */
    public function scopeSince(Builder $query, CarbonInterface $since): Builder
    {
        return $query->where(function (Builder $q) use ($since): void {
            $q->where('started_at', '>=', $since)
                ->orWhere(fn (Builder $qq) => $qq->whereNull('started_at')->where('expected_at', '>=', $since));
        });
    }

    public function isProblem(): bool
    {
        return $this->status->isProblem();
    }
}

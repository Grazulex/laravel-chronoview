<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Grazulex\ChronoView\Enums\Health;
use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Enums\TaskType;
use Grazulex\ChronoView\Support\CronDescriber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property TaskType $type
 * @property string|null $command
 * @property string $expression
 * @property string|null $timezone
 * @property string|null $description
 * @property bool $run_in_background
 * @property bool $without_overlapping
 * @property bool $on_one_server
 * @property Carbon|null $paused_at
 * @property Carbon|null $resumed_at
 * @property Carbon|null $last_started_at
 * @property Carbon|null $last_finished_at
 * @property RunStatus|null $last_status
 * @property int $consecutive_failures
 * @property Carbon|null $seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MonitoredTask extends Model
{
    protected $guarded = [];

    protected $casts = [
        'type' => TaskType::class,
        'run_in_background' => 'boolean',
        'without_overlapping' => 'boolean',
        'on_one_server' => 'boolean',
        'paused_at' => 'datetime',
        'resumed_at' => 'datetime',
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
        'last_status' => RunStatus::class,
        'consecutive_failures' => 'integer',
        'seen_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('chronoview.table_prefix', 'chronoview_') . 'tasks';
    }

    public function getConnectionName(): ?string
    {
        return config('chronoview.connection') ?? parent::getConnectionName();
    }

    /**
     * @return HasMany<TaskRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class, 'task_id');
    }

    /**
     * @param  Builder<static>  $query
     *
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('paused_at');
    }

    /**
     * @param  Builder<static>  $query
     *
     * @return Builder<static>
     */
    public function scopePaused(Builder $query): Builder
    {
        return $query->whereNotNull('paused_at');
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public function health(): Health
    {
        if ($this->isPaused()) {
            return Health::Paused;
        }

        return match ($this->last_status) {
            RunStatus::Failed, RunStatus::Missed => Health::Unhealthy,
            RunStatus::Running => Health::Running,
            RunStatus::Success => Health::Healthy,
            default => Health::Unknown,
        };
    }

    /**
     * Last due minute at or before $from, expressed in the application timezone.
     */
    public function previousDueAt(CarbonInterface $from): CarbonImmutable
    {
        $local = CarbonImmutable::instance($from)->setTimezone($this->timezone ?? config('app.timezone', 'UTC'));

        $due = (new CronExpression($this->expression))->getPreviousRunDate($local, 0, true, $this->timezone);

        return CarbonImmutable::instance($due)->setTimezone(config('app.timezone', 'UTC'))->startOfMinute();
    }

    /**
     * Next due minute strictly after $from (defaults to now), in the application timezone.
     */
    public function nextRunAt(?CarbonInterface $from = null): CarbonImmutable
    {
        $from ??= Carbon::now();
        $local = CarbonImmutable::instance($from)->setTimezone($this->timezone ?? config('app.timezone', 'UTC'));

        $next = (new CronExpression($this->expression))->getNextRunDate($local, 0, false, $this->timezone);

        return CarbonImmutable::instance($next)->setTimezone(config('app.timezone', 'UTC'))->startOfMinute();
    }

    public function cronDescription(): string
    {
        return CronDescriber::describe($this->expression);
    }
}

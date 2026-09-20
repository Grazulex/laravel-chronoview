<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class Heartbeat
{
    public function __construct(private readonly ConnectionResolverInterface $db) {}

    public function beat(?string $hostname = null): void
    {
        $this->table()->upsert(
            ['hostname' => $hostname ?? $this->hostname(), 'beat_at' => Carbon::now()],
            ['hostname'],
            ['beat_at'],
        );
    }

    /**
     * @return Collection<int, object{hostname: string, beat_at: CarbonImmutable}&\stdClass>
     */
    public function hosts(): Collection
    {
        return $this->table()->orderBy('hostname')->get()->map(fn (object $row): object => (object) [
            'hostname' => (string) $row->hostname,
            'beat_at' => CarbonImmutable::parse((string) $row->beat_at, config('app.timezone', 'UTC')),
        ]);
    }

    public function isAlive(): bool
    {
        $threshold = Carbon::now()->subSeconds($this->timeout());

        return $this->hosts()->contains(fn (object $host): bool => $host->beat_at->greaterThanOrEqualTo($threshold));
    }

    /**
     * @return Collection<int, object{hostname: string, beat_at: CarbonImmutable}&\stdClass>
     */
    public function deadHosts(): Collection
    {
        $threshold = Carbon::now()->subSeconds($this->timeout());

        return $this->hosts()->filter(fn (object $host): bool => $host->beat_at->lessThan($threshold))->values();
    }

    public function forget(string $hostname): void
    {
        $this->table()->where('hostname', $hostname)->delete();
    }

    public function hostname(): string
    {
        return gethostname() ?: 'unknown';
    }

    public function timeout(): int
    {
        return (int) config('chronoview.check.heartbeat_timeout', 180);
    }

    private function table(): Builder
    {
        return $this->db->connection(config('chronoview.connection'))
            ->table(config('chronoview.table_prefix', 'chronoview_') . 'heartbeats');
    }
}

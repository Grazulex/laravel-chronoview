<?php

declare(strict_types=1);

use Grazulex\ChronoView\Support\Heartbeat;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->heartbeat = app(Heartbeat::class);
});

it('is not alive before any beat', function (): void {
    expect($this->heartbeat->isAlive())->toBeFalse()
        ->and($this->heartbeat->hosts())->toBeEmpty();
});

it('records one beat per host and upserts it', function (): void {
    $this->heartbeat->beat('web-1');
    Carbon::setTestNow('2026-09-20 10:01:00');
    $this->heartbeat->beat('web-1');
    $this->heartbeat->beat('web-2');

    $hosts = $this->heartbeat->hosts();

    expect($hosts)->toHaveCount(2)
        ->and($hosts->firstWhere('hostname', 'web-1')->beat_at->toDateTimeString())->toBe('2026-09-20 10:01:00')
        ->and($this->heartbeat->isAlive())->toBeTrue();
});

it('is down once every host is older than the timeout', function (): void {
    config()->set('chronoview.check.heartbeat_timeout', 180);
    $this->heartbeat->beat('web-1');

    Carbon::setTestNow('2026-09-20 10:02:59');
    expect($this->heartbeat->isAlive())->toBeTrue();

    Carbon::setTestNow('2026-09-20 10:03:01');
    expect($this->heartbeat->isAlive())->toBeFalse()
        ->and($this->heartbeat->deadHosts()->pluck('hostname')->all())->toBe(['web-1']);
});

it('stays alive while at least one host beats', function (): void {
    $this->heartbeat->beat('web-1');
    Carbon::setTestNow('2026-09-20 10:10:00');
    $this->heartbeat->beat('web-2');

    expect($this->heartbeat->isAlive())->toBeTrue()
        ->and($this->heartbeat->deadHosts()->pluck('hostname')->all())->toBe(['web-1']);
});

it('defaults to the current hostname', function (): void {
    $this->heartbeat->beat();

    expect($this->heartbeat->hosts()->first()->hostname)->toBe(gethostname());
});

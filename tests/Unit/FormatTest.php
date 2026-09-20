<?php

declare(strict_types=1);

use Grazulex\ChronoView\Support\Format;
use Illuminate\Support\Carbon;

it('formats durations', function (?int $ms, string $expected): void {
    expect(Format::duration($ms))->toBe($expected);
})->with([
    [null, '—'],
    [0, '0 ms'],
    [850, '850 ms'],
    [1500, '1.5 s'],
    [59_999, '60.0 s'],
    [125_000, '2 min 05 s'],
    [3_720_000, '1 h 02 min'],
]);

it('formats bytes', function (): void {
    expect(Format::bytes(null))->toBe('—')
        ->and(Format::bytes(512))->toBe('512 B')
        ->and(Format::bytes(2048))->toBe('2.0 KB')
        ->and(Format::bytes(5 * 1024 * 1024))->toBe('5.0 MB');
});

it('formats dates', function (): void {
    Carbon::setTestNow('2026-09-20 10:00:00');

    expect(Format::exact(null))->toBe('—')
        ->and(Format::exact(Carbon::parse('2026-09-20 09:58:00')))->toBe('2026-09-20 09:58:00')
        ->and(Format::ago(Carbon::parse('2026-09-20 09:58:00')))->toBe('2 minutes ago')
        ->and(Format::ago(null))->toBe('—');
});

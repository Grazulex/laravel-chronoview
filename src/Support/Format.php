<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Support;

use Carbon\CarbonInterface;

final class Format
{
    public static function duration(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . ' ms';
        }
        if ($ms < 60_000) {
            return number_format($ms / 1000, 1) . ' s';
        }
        if ($ms < 3_600_000) {
            return sprintf('%d min %02d s', intdiv($ms, 60_000), intdiv($ms % 60_000, 1000));
        }

        return sprintf('%d h %02d min', intdiv($ms, 3_600_000), intdiv($ms % 3_600_000, 60_000));
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }

    public static function ago(?CarbonInterface $date): string
    {
        return $date?->diffForHumans() ?? '—';
    }

    public static function exact(?CarbonInterface $date): string
    {
        return $date?->format('Y-m-d H:i:s') ?? '—';
    }
}

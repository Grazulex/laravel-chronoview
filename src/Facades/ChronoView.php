<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static static auth(\Closure $callback)
 * @method static bool check(\Illuminate\Http\Request $request)
 * @method static array<int, string> allowedEmails()
 * @method static array{runs: int, success_rate: float|null, failed: int, missed: int, running: int, avg_duration_ms: int|null} stats(int $hours = 24)
 * @method static string path()
 * @method static int refresh()
 *
 * @see \Grazulex\ChronoView\ChronoView
 */
final class ChronoView extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Grazulex\ChronoView\ChronoView::class;
    }
}

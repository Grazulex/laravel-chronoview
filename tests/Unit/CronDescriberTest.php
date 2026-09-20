<?php

declare(strict_types=1);

use Grazulex\ChronoView\Support\CronDescriber;

it('describes common Laravel frequencies', function (string $expression, string $expected): void {
    expect(CronDescriber::describe($expression))->toBe($expected);
})->with([
    ['* * * * *', 'Every minute'],
    ['*/2 * * * *', 'Every 2 minutes'],
    ['*/5 * * * *', 'Every 5 minutes'],
    ['*/15 * * * *', 'Every 15 minutes'],
    ['0 * * * *', 'Hourly'],
    ['17 * * * *', 'Hourly at :17'],
    ['0 */2 * * *', 'Every 2 hours'],
    ['0 0 * * *', 'Daily at 00:00'],
    ['30 13 * * *', 'Daily at 13:30'],
    ['0 1,13 * * *', 'Daily at 01:00 and 13:00'],
    ['0 0 * * 0', 'Weekly on Sunday at 00:00'],
    ['0 8 * * 1', 'Weekly on Monday at 08:00'],
    ['0 9 * * 1-5', 'Weekdays at 09:00'],
    ['0 0 1 * *', 'Monthly on day 1 at 00:00'],
    ['0 0 1 1 *', 'Yearly on January 1 at 00:00'],
    ['0 0 1 */3 *', 'Quarterly on day 1 at 00:00'],
    ['5 4 * 3 2', '5 4 * 3 2'],
]);

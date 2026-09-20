<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Support;

final class CronDescriber
{
    private const array DAYS = [
        '0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday',
        '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday',
    ];

    private const array MONTHS = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    public static function describe(string $expression): string
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($parts) !== 5) {
            return $expression;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        if ($day === '*' && $month === '*' && $weekday === '*') {
            if ($hour === '*') {
                if ($minute === '*') {
                    return 'Every minute';
                }
                if (preg_match('/^\*\/(\d+)$/', $minute, $m) === 1) {
                    return "Every {$m[1]} minutes";
                }
                if (ctype_digit($minute)) {
                    return $minute === '0' ? 'Hourly' : 'Hourly at :' . self::pad($minute);
                }
            }

            if (ctype_digit($minute)) {
                if (preg_match('/^\*\/(\d+)$/', $hour, $m) === 1) {
                    return "Every {$m[1]} hours";
                }
                $times = self::times($minute, $hour);
                if ($times !== null) {
                    return 'Daily at ' . $times;
                }
            }
        }

        if ($day === '*' && $month === '*' && ctype_digit($minute)) {
            $times = self::times($minute, $hour);

            if ($times !== null) {
                if ($weekday === '1-5') {
                    return 'Weekdays at ' . $times;
                }
                if (isset(self::DAYS[$weekday])) {
                    return 'Weekly on ' . self::DAYS[$weekday] . ' at ' . $times;
                }
            }
        }

        if ($weekday === '*' && ctype_digit($minute) && ctype_digit($day)) {
            $times = self::times($minute, $hour);

            if ($times !== null) {
                if ($month === '*') {
                    return "Monthly on day {$day} at " . $times;
                }
                if ($month === '*/3') {
                    return "Quarterly on day {$day} at " . $times;
                }
                if (ctype_digit($month) && isset(self::MONTHS[(int) $month])) {
                    return 'Yearly on ' . self::MONTHS[(int) $month] . " {$day} at " . $times;
                }
            }
        }

        return $expression;
    }

    /**
     * "HH:MM" or "HH:MM and HH:MM" for a digit minute and a digit or comma list hour.
     */
    private static function times(string $minute, string $hour): ?string
    {
        if (preg_match('/^\d+(,\d+)*$/', $hour) !== 1) {
            return null;
        }

        $labels = array_map(
            fn (string $h): string => self::pad($h) . ':' . self::pad($minute),
            explode(',', $hour),
        );

        return implode(' and ', $labels);
    }

    private static function pad(string $value): string
    {
        return str_pad($value, 2, '0', STR_PAD_LEFT);
    }
}

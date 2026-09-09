<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Org calendar helpers. H5 CONFIRMED: Europe/Ljubljana. */
final class OrgDate
{
    public const TIMEZONE = 'Europe/Ljubljana';

    public static function today(?DateTimeInterface $now = null): string
    {
        $dt = $now
            ? CarbonImmutable::instance(\Carbon\Carbon::parse($now))->timezone(self::TIMEZONE)
            : CarbonImmutable::now(self::TIMEZONE);

        return $dt->format('Y-m-d');
    }

    /** Calendar date of an instant in org TZ. Date-only strings pass through. */
    public static function toOrgCalendarDate(string $iso): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) === 1) {
            return $iso;
        }

        return CarbonImmutable::parse($iso)->timezone(self::TIMEZONE)->format('Y-m-d');
    }

    public static function addCalendarDays(string $isoDate, int $days): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $isoDate, 'UTC')
            ->startOfDay()
            ->addDays($days)
            ->format('Y-m-d');
    }

    public static function calendarDaysBetween(string $fromIso, string $toIso): int
    {
        $a = CarbonImmutable::createFromFormat('Y-m-d', $fromIso, 'UTC')->startOfDay();
        $b = CarbonImmutable::createFromFormat('Y-m-d', $toIso, 'UTC')->startOfDay();

        return (int) $a->diffInDays($b, false);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

/**
 * Inclusive range of UTC days of the statistics, at most 366 days.
 */
final readonly class DateRange
{
    /** Longest range. */
    public const MAX_DAYS = 366;

    private function __construct(public DateTimeImmutable $from, public DateTimeImmutable $to)
    {
    }

    /**
     * From two dates (only their UTC day counts).
     *
     * @throws InvalidShortLinkArgumentException when `to` is before `from` or the range is longer than 366 days
     */
    public static function of(DateTimeInterface $from, DateTimeInterface $to): self
    {
        $start = self::day($from);
        $end   = self::day($to);
        if ($end < $start) {
            throw new InvalidShortLinkArgumentException('A date range ends on or after its start.');
        }
        if ((int) $start->diff($end)->days + 1 > self::MAX_DAYS) {
            throw new InvalidShortLinkArgumentException('A date range has at most 366 days.');
        }

        return new self($start, $end);
    }

    /**
     * The last `$days` days up to and including the day of `$today`.
     */
    public static function lastDays(int $days, DateTimeInterface $today): self
    {
        $end = self::day($today);

        return self::of($end->modify('-' . ($days - 1) . ' days'), $end);
    }

    /**
     * Every day of the range as `Y-m-d`.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];
        for ($day = $this->from; $day <= $this->to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    private static function day(DateTimeInterface $value): DateTimeImmutable
    {
        return new DateTimeImmutable(DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), new DateTimeZone('UTC'));
    }
}

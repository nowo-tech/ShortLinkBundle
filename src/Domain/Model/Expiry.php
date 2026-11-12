<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Expiry of a link: an instant in UTC, second precision. The link answers 404 from that instant on.
 */
final readonly class Expiry
{
    private function __construct(public DateTimeImmutable $at)
    {
    }

    /**
     * From any date-time (converted to UTC, microseconds dropped).
     */
    public static function fromDateTime(DateTimeInterface $at): self
    {
        $utc = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));

        return new self($utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s')));
    }

    /**
     * Whether the link has expired at this instant (`expires_at <= now`).
     */
    public function isReachedAt(DateTimeInterface $now): bool
    {
        return $this->at <= $now;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Uid;

use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkIdGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Default id generator: UUIDv7 (time-ordered) from symfony/uid, as a lowercase RFC 4122 string.
 */
final readonly class UuidV7IdGenerator implements ShortLinkIdGeneratorInterface
{
    /**
     * A new UUIDv7.
     */
    public function generate(): ShortLinkId
    {
        return ShortLinkId::fromString(Uuid::v7()->toRfc4122());
    }
}

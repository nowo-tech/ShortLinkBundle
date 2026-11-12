<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Store;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * Writes raw click rows (DBAL, one statement, never the host's EntityManager).
 *
 * @internal
 */
interface ClickStoreInterface
{
    /**
     * Inserts one click row; null columns were not stored (GPC or `click_log.store`).
     */
    public function insert(ShortLinkId $linkId, TenantKey $tenant, DateTimeImmutable $clickedAt, ?string $ipPrefix, ?string $uaFamily, ?string $referrerHost, ?string $country, bool $machine): void;
}

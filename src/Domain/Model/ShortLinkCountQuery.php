<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function array_values;
use function count;

/**
 * What {@see \Nowo\ShortLinkBundle\Domain\Service\ShortLinkUsageReaderInterface::count()} counts: the links of one kind
 * in some tenants, optionally in one state. Tenants are deduplicated by their exact key.
 */
final readonly class ShortLinkCountQuery
{
    /** Most tenants one query may name. */
    public const MAX_TENANTS = 10000;

    /** @var list<TenantKey> the tenants, each once, in the order given */
    public array $tenants;

    /**
     * @param list<TenantKey> $tenants the tenants to count (none = every count is zero)
     * @param ShortLinkState|null $state null = every link that is not deleted (active, disabled and expired ones);
     *                                   `Deleted` = the tombstones still in the tenant
     *
     * @throws InvalidShortLinkArgumentException when more than {@see self::MAX_TENANTS} tenants are named
     */
    public function __construct(array $tenants, public LinkKind $kind, public ?ShortLinkState $state = null)
    {
        $unique = [];
        foreach ($tenants as $tenant) {
            $unique[$tenant->value] ??= $tenant;
        }
        if (count($unique) > self::MAX_TENANTS) {
            throw new InvalidShortLinkArgumentException('A count names at most 10000 tenants.');
        }
        $this->tenants = array_values($unique);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Generator;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkUsageReaderInterface;

use function array_sum;

/**
 * Answer of {@see ShortLinkUsageReaderInterface::count()}: the total and the count
 * of every tenant asked for (zero included), keyed by the exact tenant key.
 */
final readonly class ShortLinkCounts
{
    /** Sum of every tenant's count. */
    public int $total;

    /**
     * `$byTenant` is a PHP array: a tenant key that is a decimal integer (`"123"`) is stored as the `int` key `123`.
     * Read one tenant with {@see forTenant()}, and iterate {@see perTenant()}, whose keys are always strings (0.7.1,
     * audit YD2-L1), when a key goes on to a `string` parameter under `strict_types`.
     *
     * @param array<string, int> $byTenant count per tenant key
     */
    public function __construct(public array $byTenant)
    {
        $this->total = array_sum($byTenant);
    }

    /**
     * The count of one tenant (zero for a tenant that was not asked for).
     */
    public function forTenant(TenantKey $tenant): int
    {
        return $this->byTenant[$tenant->value] ?? 0;
    }

    /**
     * Every tenant asked for with its count, in the order of {@see $byTenant}, keyed by the exact tenant key as a
     * string: unlike the keys of `byTenant`, a key such as `"123"` is never an int (0.7.1).
     *
     * @return Generator<string, int>
     */
    public function perTenant(): Generator
    {
        foreach ($this->byTenant as $tenant => $count) {
            yield (string) $tenant => $count;
        }
    }
}

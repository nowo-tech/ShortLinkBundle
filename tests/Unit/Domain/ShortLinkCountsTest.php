<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Domain;

use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkCountQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkCounts;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use PHPUnit\Framework\TestCase;

use function array_combine;
use function array_keys;
use function array_map;
use function iterator_to_array;

/**
 * 0.7.1 (audit YD2-L1): a tenant key that is a decimal integer ("123") is an int key of a PHP array; `perTenant()` and
 * the deduplication of the query keep it the exact string key, under strict_types.
 */
final class ShortLinkCountsTest extends TestCase
{
    public function testNumericTenantKeysStayStringsThroughPerTenant(): void
    {
        // Built from string keys, as the usage reader does (array_fill_keys over the tenant keys).
        $counts = new ShortLinkCounts(array_combine(['123', 'org-a', '0123', '-1'], [2, 1, 4, 0]));

        self::assertSame(7, $counts->total);
        self::assertSame([123, 'org-a', '0123', -1], array_keys($counts->byTenant), 'PHP turns decimal-integer keys into ints');

        $seen = [];
        foreach ($counts->perTenant() as $tenant => $links) {
            // Under strict_types an int key would be a TypeError here.
            $seen[] = [TenantKey::fromString($tenant)->value, $links];
        }
        self::assertSame([['123', 2], ['org-a', 1], ['0123', 4], ['-1', 0]], $seen);
        self::assertSame(2, $counts->forTenant(TenantKey::fromString('123')));
        self::assertSame(4, $counts->forTenant(TenantKey::fromString('0123')), '"0123" is not "123"');
        self::assertSame(0, $counts->forTenant(TenantKey::fromString('124')));
        self::assertSame([], iterator_to_array((new ShortLinkCounts([]))->perTenant()));
    }

    public function testTheQueryDeduplicatesNumericKeysExactly(): void
    {
        $query = new ShortLinkCountQuery([TenantKey::fromString('123'), TenantKey::fromString('0123'), TenantKey::fromString('123'), TenantKey::fromString('org')], LinkKind::manual());

        self::assertSame(['123', '0123', 'org'], array_map(static fn (TenantKey $tenant): string => $tenant->value, $query->tenants));
    }
}

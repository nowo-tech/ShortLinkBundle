<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkCountQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkCounts;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkUsageReaderInterface;
use Psr\Clock\ClockInterface;

use function array_chunk;
use function array_fill_keys;
use function array_key_exists;
use function array_map;
use function is_scalar;
use function sprintf;

/**
 * Usage counts on the link table, plain DBAL on the link connection (never the ORM unit of work). The states follow
 * the list filter of the manager. Grouped keys are re-checked exactly in PHP (rule LQ). Stateless (worker mode).
 *
 * @internal
 */
final readonly class DbalUsageReader implements ShortLinkUsageReaderInterface
{
    /** Tenants per grouped query. */
    private const CHUNK = 500;

    /**
     * Holds the tables and the clock.
     */
    public function __construct(private ClickTables $tables, private ?ClockInterface $clock = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function count(ShortLinkCountQuery $query): ShortLinkCounts
    {
        $keys   = array_map(static fn (TenantKey $tenant): string => $tenant->value, $query->tenants);
        $counts = array_fill_keys($keys, 0);
        [$state, $stateParams, $stateTypes] = $this->state($query->state);
        $connection                         = $this->tables->linkConnection();
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $rows = $connection->fetchAllAssociative(
                sprintf('SELECT tenant_key, COUNT(*) AS links FROM %s WHERE tenant_key IN (?) AND kind = ? AND %s GROUP BY tenant_key', $this->tables->links, $state),
                [$chunk, $query->kind->value, ...$stateParams],
                [ArrayParameterType::STRING, ...$stateTypes],
            );
            foreach ($rows as $row) {
                $key = $this->text($row['tenant_key']);
                if (array_key_exists($key, $counts)) {
                    $counts[$key] += (int) $this->text($row['links']);
                }
            }
        }

        return new ShortLinkCounts($counts);
    }

    /**
     * SQL condition, parameters and parameter types of a state.
     *
     * @return array{string, list<bool|string>, list<ParameterType>}
     */
    private function state(?ShortLinkState $state): array
    {
        $now = ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return match ($state) {
            null                     => ['deleted_at IS NULL', [], []],
            ShortLinkState::Active   => ['deleted_at IS NULL AND active = ? AND (expires_at IS NULL OR expires_at > ?)', [true, $now], [ParameterType::BOOLEAN, ParameterType::STRING]],
            ShortLinkState::Disabled => ['deleted_at IS NULL AND active = ?', [false], [ParameterType::BOOLEAN]],
            ShortLinkState::Expired  => ['deleted_at IS NULL AND active = ? AND expires_at IS NOT NULL AND expires_at <= ?', [true, $now], [ParameterType::BOOLEAN, ParameterType::STRING]],
            ShortLinkState::Deleted  => ['deleted_at IS NOT NULL', [], []],
        };
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

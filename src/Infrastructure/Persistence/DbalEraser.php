<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkEraserInterface;
use Psr\Clock\ClockInterface;

use function array_column;
use function array_combine;
use function array_filter;
use function array_keys;
use function in_array;
use function is_scalar;
use function sprintf;
use function substr;

/**
 * GDPR erasure and export (contract §10) on the bundle tables, plain DBAL. Erased links stay as blanked tombstones so
 * their codes cannot be taken over until `code.quarantine` ends; tenant erasure moves them to the tenant `~erased`
 * (never a valid tenant key, so they are never read back as a link). Stateless (worker mode).
 *
 * @internal
 */
final readonly class DbalEraser implements ShortLinkEraserInterface
{
    /** Columns blanked on an erased tombstone (the code, kind, id and dates stay: the code stays taken). */
    private const BLANK = "target_url = '', target_host = '', title = NULL, source_ref = NULL, source_label = NULL, source_position = NULL, metadata = NULL, active = ?, disabled_reason = NULL, created_by = NULL, updated_by = NULL, updated_at = ?";

    /** Tenant key of erased tombstones (outside the TenantKey pattern on purpose). */
    public const ERASED_TENANT = '~erased';

    /**
     * Holds the tables and the clock.
     */
    public function __construct(private ClickTables $tables, private ?ClockInterface $clock = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function eraseTenant(TenantKey $tenant): int
    {
        return $this->atomically(function () use ($tenant): int {
            $now   = $this->now();
            $links = $this->tables->linkConnection();
            $links->executeStatement(sprintf('UPDATE %s SET deleted_at = ? WHERE tenant_key = ? AND deleted_at IS NULL', $this->tables->links), [$now, $tenant->value]);
            $count = (int) $links->executeStatement(
                sprintf('UPDATE %s SET tenant_key = ?, %s WHERE tenant_key = ?', $this->tables->links, self::BLANK),
                [self::ERASED_TENANT, false, $now, $tenant->value],
                [1 => Types::BOOLEAN],
            );
            $clicks = $this->tables->clickConnection();
            $count += (int) $clicks->executeStatement(sprintf('DELETE FROM %s WHERE tenant_key = ?', $this->tables->clicks), [$tenant->value]);

            return $count + (int) $clicks->executeStatement(sprintf('DELETE FROM %s WHERE tenant_key = ?', $this->tables->daily), [$tenant->value]);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function eraseSource(TenantKey $tenant, LinkKind $kind, string $sourceRef): int
    {
        $ids = $this->sourceLinks($tenant, $kind, $sourceRef);
        if ($ids === []) {
            return 0;
        }
        $linkIds = array_column($ids, 'id');

        return $this->atomically(function () use ($tenant, $linkIds): int {
            $now   = $this->now();
            $links = $this->tables->linkConnection();
            $links->executeStatement(
                sprintf('UPDATE %s SET deleted_at = ? WHERE tenant_key = ? AND id IN (?) AND deleted_at IS NULL', $this->tables->links),
                [$now, $tenant->value, $linkIds],
                [2 => ArrayParameterType::STRING],
            );
            $count = (int) $links->executeStatement(
                sprintf('UPDATE %s SET %s WHERE tenant_key = ? AND id IN (?)', $this->tables->links, self::BLANK),
                [false, $now, $tenant->value, $linkIds],
                [0 => Types::BOOLEAN, 3 => ArrayParameterType::STRING],
            );
            $clicks = $this->tables->clickConnection();
            $count += (int) $clicks->executeStatement(sprintf('DELETE FROM %s WHERE tenant_key = ? AND link_id IN (?)', $this->tables->clicks), [$tenant->value, $linkIds], [1 => ArrayParameterType::STRING]);

            return $count + (int) $clicks->executeStatement(sprintf('DELETE FROM %s WHERE tenant_key = ? AND link_id IN (?)', $this->tables->daily), [$tenant->value, $linkIds], [1 => ArrayParameterType::STRING]);
        });
    }

    /**
     * Runs the erasure in one transaction when the link and click tables share a connection; otherwise tombstones come
     * first, so a click arriving in between is dropped by the recorder (the link is no longer live).
     *
     * @param Closure(): int $work
     */
    private function atomically(Closure $work): int
    {
        return $this->tables->sharesConnection() ? (int) $this->tables->linkConnection()->transactional($work) : $work();
    }

    /**
     * {@inheritDoc}
     */
    public function anonymiseActor(ActorRef $actor): int
    {
        $links = $this->tables->linkConnection();
        $count = (int) $links->executeStatement(sprintf('UPDATE %s SET created_by = NULL WHERE created_by = ?', $this->tables->links), [$actor->value]);

        return $count + (int) $links->executeStatement(sprintf('UPDATE %s SET updated_by = NULL WHERE updated_by = ?', $this->tables->links), [$actor->value]);
    }

    /**
     * {@inheritDoc}
     */
    public function exportSource(TenantKey $tenant, LinkKind $kind, string $sourceRef): array
    {
        $links = $this->sourceLinks($tenant, $kind, $sourceRef);
        if ($links === []) {
            return ['clicks' => [], 'daily' => []];
        }
        $byId   = array_combine(array_column($links, 'id'), $links);
        $clicks = $this->tables->clickConnection();
        $mine   = fn (array $row): bool => isset($byId[$this->text($row['link_id'])]) && $this->text($row['tenant_key']) === $tenant->value;
        $raw    = $clicks->fetchAllAssociative(
            sprintf('SELECT link_id, tenant_key, clicked_at, ip_prefix, ua_family, referrer_host, country, machine FROM %s WHERE tenant_key = ? AND link_id IN (?) ORDER BY id', $this->tables->clicks),
            [$tenant->value, array_keys($byId)],
            [1 => ArrayParameterType::STRING],
        );
        $rollups = $clicks->fetchAllAssociative(
            sprintf('SELECT link_id, tenant_key, day, dimension, value, clicks, machine_clicks FROM %s WHERE tenant_key = ? AND link_id IN (?) ORDER BY day, link_id, dimension, value', $this->tables->daily),
            [$tenant->value, array_keys($byId)],
            [1 => ArrayParameterType::STRING],
        );
        $export = ['clicks' => [], 'daily' => []];
        foreach (array_filter($raw, $mine) as $row) {
            $link               = $byId[$this->text($row['link_id'])];
            $export['clicks'][] = [
                'code'          => $link['code'],
                'label'         => $link['label'],
                'position'      => $link['position'],
                'clicked_at'    => substr($this->text($row['clicked_at']), 0, 19),
                'ip_prefix'     => $this->nullable($row['ip_prefix']),
                'ua_family'     => $this->nullable($row['ua_family']),
                'referrer_host' => $this->nullable($row['referrer_host']),
                'country'       => $this->nullable($row['country']),
                'machine'       => in_array($row['machine'], [true, 1, '1', 't', 'true'], true),
            ];
        }
        foreach (array_filter($rollups, $mine) as $row) {
            $link              = $byId[$this->text($row['link_id'])];
            $export['daily'][] = [
                'code'           => $link['code'],
                'label'          => $link['label'],
                'position'       => $link['position'],
                'day'            => substr($this->text($row['day']), 0, 10),
                'dimension'      => $this->text($row['dimension']),
                'value'          => $this->text($row['value']),
                'clicks'         => (int) $this->text($row['clicks']),
                'machine_clicks' => (int) $this->text($row['machine_clicks']),
            ];
        }

        return $export;
    }

    /**
     * Links of one source, re-checked exactly in PHP.
     *
     * @return list<array{id: string, code: string, label: string|null, position: int|null}>
     */
    private function sourceLinks(TenantKey $tenant, LinkKind $kind, string $sourceRef): array
    {
        $rows = $this->tables->linkConnection()->fetchAllAssociative(
            sprintf('SELECT id, tenant_key, code, source_ref, source_label, source_position FROM %s WHERE tenant_key = ? AND kind = ? AND source_ref = ?', $this->tables->links),
            [$tenant->value, $kind->value, $sourceRef],
        );
        $links = [];
        foreach ($rows as $row) {
            if ($this->text($row['tenant_key']) === $tenant->value && $this->text($row['source_ref']) === $sourceRef) {
                $links[] = [
                    'id'       => $this->text($row['id']),
                    'code'     => $this->text($row['code']),
                    'label'    => $this->nullable($row['source_label']),
                    'position' => $row['source_position'] === null ? null : (int) $this->text($row['source_position']),
                ];
            }
        }

        return $links;
    }

    private function now(): string
    {
        return ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function nullable(mixed $value): ?string
    {
        return $value === null ? null : $this->text($value);
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

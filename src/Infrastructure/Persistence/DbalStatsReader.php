<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Nowo\ShortLinkBundle\Domain\Model\ClickDimension;
use Nowo\ShortLinkBundle\Domain\Model\DailyClicks;
use Nowo\ShortLinkBundle\Domain\Model\DateRange;
use Nowo\ShortLinkBundle\Domain\Model\DimensionClicks;
use Nowo\ShortLinkBundle\Domain\Model\LinkClickTotals;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkStatsReaderInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function in_array;
use function is_scalar;
use function max;
use function min;
use function sprintf;
use function substr;
use function uasort;

/**
 * Statistics from the daily rollup (rolled days) and the raw clicks (every other day), aggregated in PHP so the SQL
 * stays portable. Every query filters on the tenant. Stateless (worker mode).
 *
 * @internal
 */
final readonly class DbalStatsReader implements ShortLinkStatsReaderInterface
{
    /**
     * Holds the tables and the maintenance (rolled days).
     */
    public function __construct(private ClickTables $tables, private DbalClickMaintenance $maintenance)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function daily(TenantKey $tenant, ShortLinkId $id, DateRange $range): array
    {
        $byDay = [];
        foreach ($this->cells($tenant, [$id->value], 'total', $range) as [, $day, , $human, $machine]) {
            $cell        = $byDay[$day] ?? [0, 0];
            $byDay[$day] = [$cell[0] + $human, $cell[1] + $machine];
        }

        return array_map(static fn (string $day): DailyClicks => new DailyClicks($day, $byDay[$day][0] ?? 0, $byDay[$day][1] ?? 0), $range->days());
    }

    /**
     * {@inheritDoc}
     */
    public function breakdown(TenantKey $tenant, ShortLinkId $id, ClickDimension $dimension, DateRange $range, int $limit = 20): array
    {
        $values = [];
        foreach ($this->cells($tenant, [$id->value], $dimension->value, $range) as [, , $value, $human, $machine]) {
            $cell           = $values[$value] ?? [0, 0];
            $values[$value] = [$cell[0] + $human, $cell[1] + $machine];
        }
        uasort($values, static fn (array $a, array $b): int => ($b[0] + $b[1]) <=> ($a[0] + $a[1]));
        $rows = [];
        foreach (array_slice($values, 0, max(1, $limit), true) as $value => [$human, $machine]) {
            $rows[] = new DimensionClicks((string) $value, $human, $machine);
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     */
    public function totals(TenantKey $tenant, array $ids, ?DateRange $range = null): array
    {
        $totals = [];
        foreach ($ids as $id) {
            $totals[$id->value] = [0, 0, null, null];
        }
        if ($ids === []) {
            return [];
        }
        foreach ($this->cells($tenant, array_map(static fn (ShortLinkId $id): string => $id->value, $ids), 'total', $range) as [$link, $day, , $human, $machine, $first, $last]) {
            $cell          = $totals[$link];
            $totals[$link] = [$cell[0] + $human, $cell[1] + $machine, $cell[2] === null ? $first ?? $day : min($cell[2], $first ?? $day), $cell[3] === null ? $last ?? $day : max($cell[3], $last ?? $day)];
        }
        $result = [];
        foreach ($ids as $id) {
            [$human, $machine, $first, $last] = $totals[$id->value];
            $result[$id->value]               = new LinkClickTotals($id, $human, $machine, $this->instant($first), $this->instant($last));
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function bySource(TenantKey $tenant, LinkKind $kind, string $sourceRef): array
    {
        $links = $this->tables->linkConnection()->fetchAllAssociative(
            sprintf('SELECT id, tenant_key, code, code_custom, source_ref, source_label, source_position FROM %s WHERE tenant_key = ? AND kind = ? AND source_ref = ? ORDER BY source_position, id', $this->tables->links),
            [$tenant->value, $kind->value, $sourceRef],
        );
        $rows   = array_values(array_filter($links, fn (array $row): bool => $this->text($row['tenant_key']) === $tenant->value && $this->text($row['source_ref']) === $sourceRef));
        $ids    = array_map(fn (array $row): ShortLinkId => ShortLinkId::fromString($this->text($row['id'])), $rows);
        $totals = $this->totals($tenant, $ids);
        $result = [];
        foreach ($rows as $row) {
            $total    = $totals[$this->text($row['id'])];
            $result[] = new LinkClickTotals(
                $total->id,
                $total->clicks,
                $total->machineClicks,
                $total->firstClickAt,
                $total->lastClickAt,
                ShortCode::fromStored($this->text($row['code']), in_array($row['code_custom'], [true, 1, '1', 't', 'true'], true)),
                new LinkSource($sourceRef, $row['source_label'] === null ? null : $this->text($row['source_label']), $row['source_position'] === null ? null : (int) $this->text($row['source_position'])),
            );
        }

        return $result;
    }

    /**
     * Cells `[link, day, value, human, machine, first instant|null, last instant|null]` of one dimension: rollup rows of
     * rolled days, raw rows of the others. A null range = every stored day.
     *
     * @param list<string> $linkIds
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: string|null, 6: string|null}>
     */
    private function cells(TenantKey $tenant, array $linkIds, string $dimension, ?DateRange $range): array
    {
        $clicks = $this->tables->clickConnection();
        $rolled = $this->maintenance->rolledDays($clicks);
        $cells  = [];

        $sql    = sprintf('SELECT link_id, tenant_key, day, value, clicks, machine_clicks FROM %s WHERE tenant_key = ? AND dimension = ? AND link_id IN (?)', $this->tables->daily);
        $params = [$tenant->value, $dimension, $linkIds];
        $types  = [2 => ArrayParameterType::STRING];
        if ($range instanceof DateRange) {
            $sql .= ' AND day >= ? AND day <= ?';
            $params[] = $range->from->format('Y-m-d');
            $params[] = $range->to->format('Y-m-d');
        }
        foreach ($clicks->fetchAllAssociative($sql, $params, $types) as $row) {
            $day = substr($this->text($row['day']), 0, 10);
            if ($this->text($row['tenant_key']) === $tenant->value && isset($rolled[$day])) {
                $cells[] = [$this->text($row['link_id']), $day, $this->text($row['value']), (int) $this->text($row['clicks']), (int) $this->text($row['machine_clicks']), null, null];
            }
        }

        $column = match ($dimension) {
            'referrer' => 'referrer_host',
            'country'  => 'country',
            'ua'       => 'ua_family',
            default    => 'NULL',
        };
        $sql    = sprintf('SELECT link_id, tenant_key, clicked_at, %s AS value, machine FROM %s WHERE tenant_key = ? AND link_id IN (?)', $column, $this->tables->clicks);
        $params = [$tenant->value, $linkIds];
        $types  = [1 => ArrayParameterType::STRING];
        if ($range instanceof DateRange) {
            $sql .= ' AND clicked_at >= ? AND clicked_at < ?';
            $params[] = $range->from->format('Y-m-d H:i:s');
            $params[] = $range->to->modify('+1 day')->format('Y-m-d H:i:s');
        }
        foreach ($clicks->iterateAssociative($sql, $params, $types) as $row) {
            $at  = $this->text($row['clicked_at']);
            $day = substr($at, 0, 10);
            if ($this->text($row['tenant_key']) !== $tenant->value || isset($rolled[$day])) {
                continue;
            }
            $machine = in_array($row['machine'], [true, 1, '1', 't', 'true'], true);
            $instant = substr($at, 0, 19);
            $cells[] = [$this->text($row['link_id']), $day, $row['value'] === null ? '' : $this->text($row['value']), $machine ? 0 : 1, $machine ? 1 : 0, $instant, $instant];
        }

        return $cells;
    }

    private function instant(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

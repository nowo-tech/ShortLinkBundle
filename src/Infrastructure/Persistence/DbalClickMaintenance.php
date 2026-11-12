<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nowo\ShortLinkBundle\Domain\Model\DimensionClicks;

use function array_fill_keys;
use function array_keys;
use function array_slice;
use function arsort;
use function count;
use function in_array;
use function is_scalar;
use function sprintf;
use function substr;

/**
 * Rollup and retention of the click tables (contract §5.3), portable across SQLite, MySQL, MariaDB and PostgreSQL:
 * rows are read per UTC day and aggregated in PHP (no date functions in SQL), each day is rewritten in one transaction
 * (delete + insert + mark rolled), so running it twice gives the same rows. Stateless (worker mode).
 *
 * @internal
 */
final readonly class DbalClickMaintenance
{
    /** Rows deleted per statement by the purge (keyset batches). */
    public const BATCH = 1000;

    /**
     * Holds the tables.
     */
    public function __construct(private ClickTables $tables)
    {
    }

    /**
     * Rolls every day that has raw rows and is not rolled yet, up to yesterday, plus the last `$lagDays` days again
     * (late Messenger writes). Returns the rolled days (`Y-m-d`), oldest first.
     *
     * @param int $maxDays most days rolled in one run
     *
     * @return list<string>
     */
    public function rollup(DateTimeImmutable $now, int $lagDays, int $topN, int $maxDays = 400): array
    {
        $clicks    = $this->tables->clickConnection();
        $today     = $this->day($now);
        $yesterday = $today->modify('-1 day');
        $oldest    = $clicks->fetchOne(sprintf('SELECT MIN(clicked_at) FROM %s', $this->tables->clicks));
        if (!is_scalar($oldest) || $oldest === '') {
            return [];
        }
        $rolled = $this->rolledDays($clicks);
        $redo   = $today->modify('-' . $lagDays . ' days');
        $done   = [];
        for ($day = $this->day(new DateTimeImmutable((string) $oldest, new DateTimeZone('UTC'))); $day <= $yesterday && count($done) < $maxDays; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            if (isset($rolled[$key]) && $day < $redo) {
                continue;
            }
            $this->rollDay($clicks, $day, $topN, $now);
            $done[] = $key;
        }

        return $done;
    }

    /**
     * Retention (contract §5.3, §10): raw rows older than `$retention` of rolled days only, rollups older than
     * `$rollupRetention`, tombstones older than `$quarantine`. A null duration keeps those rows.
     *
     * @return array{clicks: int, rollups: int, tombstones: int}
     */
    public function purge(DateTimeImmutable $now, ?string $retention, ?string $quarantine, ?string $rollupRetention): array
    {
        $result = ['clicks' => 0, 'rollups' => 0, 'tombstones' => 0];
        $clicks = $this->tables->clickConnection();
        if ($retention !== null) {
            $result['clicks'] = $this->purgeClicks($clicks, $this->utc($now->sub(new DateInterval($retention))));
        }
        if ($rollupRetention !== null) {
            $cutoff            = $this->day($now->sub(new DateInterval($rollupRetention)))->format('Y-m-d');
            $result['rollups'] = (int) $clicks->executeStatement(sprintf('DELETE FROM %s WHERE day < ?', $this->tables->daily), [$cutoff]);
            $clicks->executeStatement(sprintf('DELETE FROM %s WHERE day < ?', $this->tables->days), [$cutoff]);
        }
        if ($quarantine !== null) {
            $result['tombstones'] = (int) $this->tables->linkConnection()->executeStatement(
                sprintf('DELETE FROM %s WHERE deleted_at IS NOT NULL AND deleted_at < ?', $this->tables->links),
                [$this->utc($now->sub(new DateInterval($quarantine)))],
            );
        }

        return $result;
    }

    /**
     * Days already rolled.
     *
     * @return array<string, true>
     */
    public function rolledDays(Connection $clicks): array
    {
        $days = [];
        foreach ($clicks->fetchFirstColumn(sprintf('SELECT day FROM %s', $this->tables->days)) as $day) {
            $days[substr((string) (is_scalar($day) ? $day : ''), 0, 10)] = true;
        }

        return $days;
    }

    private function rollDay(Connection $clicks, DateTimeImmutable $day, int $topN, DateTimeImmutable $now): void
    {
        /** @var array<string, array{tenant: string, dims: array<string, array<string, array{0: int, 1: int}>>}> $links */
        $links = [];
        $rows  = $clicks->iterateAssociative(
            sprintf('SELECT link_id, tenant_key, ip_prefix, ua_family, referrer_host, country, machine FROM %s WHERE clicked_at >= ? AND clicked_at < ?', $this->tables->clicks),
            [$this->utc($day), $this->utc($day->modify('+1 day'))],
        );
        foreach ($rows as $row) {
            $link    = (string) (is_scalar($row['link_id']) ? $row['link_id'] : '');
            $machine = in_array($row['machine'], [true, 1, '1', 't', 'true'], true) ? 1 : 0;
            $links[$link] ??= ['tenant' => (string) (is_scalar($row['tenant_key']) ? $row['tenant_key'] : ''), 'dims' => []];
            foreach (['total' => null, 'referrer' => $row['referrer_host'], 'country' => $row['country'], 'ua' => $row['ua_family']] as $dimension => $value) {
                $value = $value === null ? '' : (string) (is_scalar($value) ? $value : '');
                $cell  = $links[$link]['dims'][$dimension][$value] ?? [0, 0];
                ++$cell[$machine];
                $links[$link]['dims'][$dimension][$value] = $cell;
            }
        }

        $key = $day->format('Y-m-d');
        $clicks->transactional(function (Connection $c) use ($links, $key, $topN, $now): void {
            $c->executeStatement(sprintf('DELETE FROM %s WHERE day = ?', $this->tables->daily), [$key]);
            foreach ($links as $linkId => $link) {
                $link['dims']['referrer'] = $this->topReferrers($link['dims']['referrer'] ?? [], $topN);
                foreach ($link['dims'] as $dimension => $values) {
                    foreach ($values as $value => [$human, $machine]) {
                        $c->insert($this->tables->daily, [
                            'link_id'        => $linkId,
                            'tenant_key'     => $link['tenant'],
                            'day'            => $key,
                            'dimension'      => $dimension,
                            'value'          => (string) $value,
                            'clicks'         => $human,
                            'machine_clicks' => $machine,
                        ]);
                    }
                }
            }
            $c->executeStatement(sprintf('DELETE FROM %s WHERE day = ?', $this->tables->days), [$key]);
            $c->insert($this->tables->days, ['day' => $key, 'rolled_at' => $this->utc($now)]);
        });
    }

    /**
     * Keeps the `$topN` referrer hosts with most clicks (unknown always kept apart) and sums the rest into `~other`.
     *
     * @param array<string, array{0: int, 1: int}> $values
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function topReferrers(array $values, int $topN): array
    {
        $unknown = $values[''] ?? null;
        unset($values['']);
        $totals = [];
        foreach ($values as $value => [$human, $machine]) {
            $totals[(string) $value] = $human + $machine;
        }
        arsort($totals);
        $keep = array_fill_keys(array_slice(array_keys($totals), 0, $topN), true);
        $out  = $unknown === null ? [] : ['' => $unknown];
        foreach ($values as $value => $cell) {
            if (isset($keep[(string) $value])) {
                $out[(string) $value] = $cell;
                continue;
            }
            $other                       = $out[DimensionClicks::OTHER] ?? [0, 0];
            $out[DimensionClicks::OTHER] = [$other[0] + $cell[0], $other[1] + $cell[1]];
        }

        return $out;
    }

    private function purgeClicks(Connection $clicks, string $cutoff): int
    {
        $rolled  = $this->rolledDays($clicks);
        $deleted = 0;
        $after   = 0;
        while (true) {
            $rows = $clicks->fetchAllAssociative(
                $clicks->getDatabasePlatform()->modifyLimitQuery(sprintf('SELECT id, clicked_at FROM %s WHERE clicked_at < ? AND id > ? ORDER BY id', $this->tables->clicks), self::BATCH),
                [$cutoff, $after],
            );
            if ($rows === []) {
                return $deleted;
            }
            $ids = [];
            foreach ($rows as $row) {
                if (isset($rolled[substr((string) (is_scalar($row['clicked_at']) ? $row['clicked_at'] : ''), 0, 10)])) {
                    $ids[] = (int) (is_scalar($row['id']) ? $row['id'] : 0);
                }
            }
            $after = (int) (is_scalar($rows[count($rows) - 1]['id']) ? $rows[count($rows) - 1]['id'] : 0);
            if ($ids !== []) {
                $deleted += (int) $clicks->executeStatement(sprintf('DELETE FROM %s WHERE id IN (?)', $this->tables->clicks), [$ids], [ArrayParameterType::INTEGER]);
            }
        }
    }

    private function day(DateTimeImmutable $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    private function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

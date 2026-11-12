<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\Csv;

use Nowo\ShortLinkBundle\Domain\Model\DailyClicks;
use Nowo\ShortLinkBundle\Domain\Model\DimensionClicks;

use function fclose;
use function fopen;
use function fputcsv;
use function fwrite;
use function in_array;
use function is_resource;
use function ltrim;

/**
 * CSV export of the statistics page (contract §11): `day,clicks,machine_clicks`, then one section per breakdown
 * (`referrer`, `country`, `browser`), or a single section. Every cell is neutralised against spreadsheet formula
 * injection (contract §9): a cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return, also after leading
 * spaces, gets a leading `'`
 * (referrer hosts come from visitors). Pure functions; the controller streams the rows.
 */
final class StatsCsv
{
    /** UTF-8 byte order mark written first with `?excel=1`. */
    public const BOM = "\u{FEFF}";

    /** Characters a spreadsheet reads as the start of a formula. */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * A cell safe to open in a spreadsheet.
     */
    public static function cell(string $value): string
    {
        $start = ltrim($value, ' ');

        return $start !== '' && in_array($start[0], self::FORMULA_STARTS, true) ? "'" . $value : $value;
    }

    /**
     * Rows of the export (header rows included; an empty row between sections).
     *
     * @param list<DailyClicks>|null $daily null = no daily section
     * @param array<string, list<DimensionClicks>> $breakdowns section name => rows
     *
     * @return list<list<string>>
     */
    public static function rows(?array $daily, array $breakdowns): array
    {
        $rows = [];
        if ($daily !== null) {
            $rows[] = ['day', 'clicks', 'machine_clicks'];
            foreach ($daily as $day) {
                $rows[] = [$day->day, (string) $day->clicks, (string) $day->machineClicks];
            }
        }
        foreach ($breakdowns as $section => $values) {
            if ($rows !== []) {
                $rows[] = [];
            }
            $rows[] = [$section, 'clicks', 'machine_clicks'];
            foreach ($values as $value) {
                $rows[] = [$value->value, (string) $value->clicks, (string) $value->machineClicks];
            }
        }

        $safe = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = self::cell($cell);
            }
            $safe[] = $cells;
        }

        return $safe;
    }

    /**
     * Writes the rows to a stream (`php://output` when streaming), with the BOM first when asked.
     *
     * @param list<list<string>> $rows
     */
    public static function write(array $rows, bool $bom, string $target = 'php://output'): void
    {
        $handle = @fopen($target, 'w');
        if (!is_resource($handle)) {
            return;
        }
        if ($bom) {
            fwrite($handle, self::BOM);
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
    }
}

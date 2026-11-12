<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\View;

use Nowo\ShortLinkBundle\Domain\Model\DailyClicks;

use function count;
use function max;
use function round;

/**
 * Geometry of the server-side SVG bar chart of the statistics page (contract §11, Q-O5): one bar per UTC day, people
 * at the bottom and machine traffic stacked above. No script, no inline style (CSP-safe); the template always prints
 * the table of the same numbers as the accessible fallback. Replaced by UiKit's chart component once it ships.
 */
final readonly class DailyChart
{
    /** Height of the drawing area (viewBox units). */
    public const HEIGHT = 120;

    /** Width of one day (bar + gap, viewBox units). */
    public const STEP = 12;

    /** Width of a bar. */
    public const BAR = 10;

    /** Highest daily total (people + machines). */
    public int $max;

    /** Width of the drawing area. */
    public int $width;

    /** @var list<array{day: string, clicks: int, machine: int, x: int, y: float, height: float, machineY: float, machineHeight: float}> */
    public array $bars;

    /**
     * @param list<DailyClicks> $days
     */
    public function __construct(array $days)
    {
        $max = 0;
        foreach ($days as $day) {
            $max = max($max, $day->clicks + $day->machineClicks);
        }
        $scale = $max === 0 ? 0.0 : self::HEIGHT / $max;
        $bars  = [];
        foreach ($days as $index => $day) {
            $height        = round($day->clicks * $scale, 2);
            $machineHeight = round($day->machineClicks * $scale, 2);
            $bars[]        = [
                'day'           => $day->day,
                'clicks'        => $day->clicks,
                'machine'       => $day->machineClicks,
                'x'             => $index * self::STEP,
                'y'             => round(self::HEIGHT - $height, 2),
                'height'        => $height,
                'machineY'      => round(self::HEIGHT - $height - $machineHeight, 2),
                'machineHeight' => $machineHeight,
            ];
        }
        $this->max   = $max;
        $this->width = max(self::STEP, count($days) * self::STEP);
        $this->bars  = $bars;
    }
}

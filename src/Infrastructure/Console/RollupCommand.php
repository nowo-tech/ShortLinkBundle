<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Console;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalClickMaintenance;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function is_numeric;
use function max;
use function sprintf;

/**
 * `nowo:short-link:rollup` (contract §5.3): rolls the raw clicks into the daily table. Run it daily (Beacon: in its
 * maintenance schedule); idempotent.
 */
#[AsCommand(name: 'nowo:short-link:rollup', description: 'Roll raw short-link clicks into daily aggregates')]
final class RollupCommand extends Command
{
    /**
     * @param int $lagDays `click_log.rollup.lag_days`
     * @param int $topN `click_log.rollup.referrer_top_n`
     */
    public function __construct(
        private readonly DbalClickMaintenance $maintenance,
        private readonly int $lagDays = 2,
        private readonly int $topN = 50,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct();
    }

    /**
     * Declares `--max-days`.
     */
    protected function configure(): void
    {
        $this->addOption('max-days', null, InputOption::VALUE_REQUIRED, 'Most days rolled in one run', '400');
    }

    /**
     * Rolls the pending days.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $max  = $input->getOption('max-days');
        $days = $this->maintenance->rollup($this->clock?->now() ?? new DateTimeImmutable(), $this->lagDays, $this->topN, is_numeric($max) ? max(1, (int) $max) : 400);
        $output->writeln(sprintf('Rolled %d day(s).', count($days)));

        return Command::SUCCESS;
    }
}

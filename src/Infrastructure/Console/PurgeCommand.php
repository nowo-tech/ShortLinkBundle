<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Console;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalClickMaintenance;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * `nowo:short-link:purge` (contract §5.3, §10): deletes raw clicks older than `click_log.retention` (rolled days only),
 * rollups older than `click_log.rollup.retention` and tombstones older than `code.quarantine`. Run it daily, after the
 * rollup.
 */
#[AsCommand(name: 'nowo:short-link:purge', description: 'Apply the short-link retention (raw clicks, rollups, tombstones)')]
final class PurgeCommand extends Command
{
    /**
     * @param string|null $retention `click_log.retention`
     * @param string|null $quarantine `code.quarantine`
     * @param string|null $rollupRetention `click_log.rollup.retention`
     */
    public function __construct(
        private readonly DbalClickMaintenance $maintenance,
        private readonly ?string $retention = 'P90D',
        private readonly ?string $quarantine = 'P365D',
        private readonly ?string $rollupRetention = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct();
    }

    /**
     * Purges and prints the counts.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $done = $this->maintenance->purge($this->clock?->now() ?? new DateTimeImmutable(), $this->retention, $this->quarantine, $this->rollupRetention);
        $output->writeln(sprintf('Purged %d click(s), %d rollup row(s), %d tombstone(s).', $done['clicks'], $done['rollups'], $done['tombstones']));

        return Command::SUCCESS;
    }
}

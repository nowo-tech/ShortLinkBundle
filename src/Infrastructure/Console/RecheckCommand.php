<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Console;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkStateChangedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_numeric;
use function max;
use function min;
use function sprintf;

/**
 * `nowo:short-link:recheck` (contract §8, Q-O12): runs the save guard (DNS included when configured) over every active
 * link, in keyset batches. A refused link is disabled with `disabled_reason = recheck_refused` through a narrow update
 * that only applies while the link is still active, not deleted and on the same target (an edit, a delete or an erasure
 * during the run wins); the {@see ShortLinkTargetRefusedEvent} and {@see ShortLinkStateChangedEvent} follow after
 * commit, only when that row changed. DNS failures never disable a link: an unresolvable target is skipped with a
 * warning. Once at least `target.recheck_min_sample` links were checked, the run aborts (exit 1) when more than
 * `target.recheck_max_unresolvable_percent` of all checked targets were unresolvable (a resolver outage; `0` aborts on
 * any). The other refusals of the batch (denylist, private host, self chain) are applied before the check, so an abort
 * never leaves them enabled. `--dry-run` reports without writing. Run it daily.
 */
#[AsCommand(name: 'nowo:short-link:recheck', description: 'Re-check active short-link targets and disable refused ones')]
final class RecheckCommand extends Command
{
    /**
     * @param int $maxUnresolvablePercent abort when more than this share of the checked targets is unresolvable (0-100;
     *                                    0 aborts on any unresolvable target)
     * @param int $minSample the threshold applies once at least this many links were checked
     */
    public function __construct(
        private readonly ShortLinkStoreInterface $store,
        private readonly TargetGuardInterface $guard,
        private readonly EventDispatcherInterface $events,
        private readonly ?ClockInterface $clock = null,
        private readonly int $maxUnresolvablePercent = 20,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $minSample = 20,
    ) {
        parent::__construct();
    }

    /**
     * Declares `--batch` and `--dry-run`.
     */
    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Links read per batch (1-1000)', '500');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the links that would be disabled, change nothing');
    }

    /**
     * Checks every active link.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch    = $input->getOption('batch');
        $size     = is_numeric($batch) ? max(1, min(1000, (int) $batch)) : 500;
        $dryRun   = (bool) $input->getOption('dry-run');
        $cursor   = null;
        $checked  = 0;
        $disabled = 0;
        $skipped  = 0;
        do {
            $page         = $this->store->activeAfter($cursor, $size);
            $cursor       = $page->cursor;
            $refused      = [];
            $unresolvable = 0;
            foreach ($page->links as $link) {
                ++$checked;
                $refusal = $this->guard->check($link->target, GuardStage::Recheck)->refusal;
                if ($refusal === TargetRefusal::Unresolvable) {
                    ++$unresolvable;
                    $this->logger?->warning('nowo_short_link: recheck skipped link {link}: its host could not be resolved.', ['link' => $link->id->value]);
                } elseif ($refusal instanceof TargetRefusal) {
                    $refused[] = [$link, $refusal];
                }
            }
            $skipped += $unresolvable;
            foreach ($refused as [$link, $refusal]) {
                if ($dryRun) {
                    $output->writeln(sprintf('Would disable %s (%s).', $link->id->value, $refusal->value));
                    ++$disabled;
                    continue;
                }
                $disabled += $this->disable($link, $refusal) ? 1 : 0;
            }
            if ($checked >= $this->minSample && $checked > 0 && $skipped * 100 > $this->maxUnresolvablePercent * $checked) {
                $output->writeln(sprintf('Aborted: %d of %d checked targets could not be resolved (DNS outage?). Disabled %d.', $skipped, $checked, $disabled));

                return Command::FAILURE;
            }
        } while ($page->rawCount === $size && $cursor !== null);
        $output->writeln(sprintf('%sChecked %d link(s), disabled %d, skipped %d unresolvable.', $dryRun ? '[dry run] ' : '', $checked, $disabled, $skipped));

        return Command::SUCCESS;
    }

    private function disable(StoredLink $link, TargetRefusal $refusal): bool
    {
        $now = ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));

        return (bool) $this->store->transactional(
            // @igor-ignore - Closure passed to one transactional() call and released when it returns.
            fn (): bool => $this->store->disableIfUnchanged($link, ShortLinkStateChangedEvent::REASON_RECHECK_REFUSED, $now),
            // @igor-ignore - After-commit work of one transactional() call, never stored.
            function (bool $changed) use ($link, $refusal): void {
                if (!$changed) {
                    return;
                }
                $this->events->dispatch(new ShortLinkTargetRefusedEvent($link->tenant, GuardStage::Recheck, $refusal, $link->target->host(), $link->id));
                $this->events->dispatch(new ShortLinkStateChangedEvent($link->id, $link->tenant, ShortLinkState::Active, ShortLinkState::Disabled, ShortLinkStateChangedEvent::REASON_RECHECK_REFUSED, null));
            },
        );
    }
}

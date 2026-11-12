<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Console;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkStateChangedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function is_numeric;
use function max;
use function min;
use function sprintf;

/**
 * `nowo:short-link:recheck` (contract §8, Q-O12): runs the save guard (DNS included when configured) over every active
 * link, in keyset batches; a refused link is disabled with `disabled_reason = recheck_refused`, a
 * {@see ShortLinkStateChangedEvent} and a {@see ShortLinkTargetRefusedEvent} (after commit). Run it daily.
 */
#[AsCommand(name: 'nowo:short-link:recheck', description: 'Re-check active short-link targets and disable refused ones')]
final class RecheckCommand extends Command
{
    /**
     * Wires the store, the guard and the dispatcher.
     */
    public function __construct(
        private readonly ShortLinkStoreInterface $store,
        private readonly TargetGuardInterface $guard,
        private readonly EventDispatcherInterface $events,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct();
    }

    /**
     * Declares `--batch`.
     */
    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Links read per batch (1-1000)', '500');
    }

    /**
     * Checks every active link.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch    = $input->getOption('batch');
        $size     = is_numeric($batch) ? max(1, min(1000, (int) $batch)) : 500;
        $after    = null;
        $checked  = 0;
        $disabled = 0;
        do {
            $links = $this->store->activeAfter($after, $size);
            foreach ($links as $link) {
                ++$checked;
                $after   = $link->id;
                $verdict = $this->guard->check($link->target, GuardStage::Recheck);
                if (!$verdict->refusal instanceof TargetRefusal) {
                    continue;
                }
                $now     = ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
                $updated = $link->withActive(false, ShortLinkStateChangedEvent::REASON_RECHECK_REFUSED, $now, null);
                $refusal = $verdict->refusal;
                $this->store->transactional(
                    // @igor-ignore - Closure passed to one transactional() call and released when it returns.
                    function () use ($updated): void {
                        $this->store->update($updated);
                    },
                    // @igor-ignore - After-commit work of one transactional() call, never stored.
                    function () use ($link, $updated, $now, $refusal): void {
                        $this->events->dispatch(new ShortLinkTargetRefusedEvent($link->tenant, GuardStage::Recheck, $refusal, $link->target->host(), $link->id));
                        $this->events->dispatch(new ShortLinkStateChangedEvent($link->id, $link->tenant, ShortLinkState::Active, $updated->stateAt($now), ShortLinkStateChangedEvent::REASON_RECHECK_REFUSED, null));
                    },
                );
                ++$disabled;
            }
        } while (count($links) === $size && $after instanceof ShortLinkId);
        $output->writeln(sprintf('Checked %d link(s), disabled %d.', $checked, $disabled));

        return Command::SUCCESS;
    }
}

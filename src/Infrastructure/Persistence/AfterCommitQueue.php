<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

use function count;

/**
 * After-commit work of writes that joined a host transaction (contract §3.4, rule 7): held per native connection until the
 * outermost commit ({@see AfterCommitConnection}), dropped on rollback.
 *
 * Worker mode: the held work is request state, so it is dropped at every request / message boundary
 * ({@see AfterCommitQueueResetListener}) and on `kernel.reset`; nothing is ever held across requests. Weak keys: a
 * closed connection takes its held work with it.
 *
 * @internal
 */
final class AfterCommitQueue implements ResetInterface
{
    /** @var WeakMap<object, list<Closure(): void>> */
    private WeakMap $held;

    /**
     * @param LoggerInterface|null $logger failures of after-commit work are logged here, never thrown
     */
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
        $this->held = new WeakMap();
    }

    /**
     * Holds work until the outermost commit of this native connection.
     *
     * @param Closure(): void $work
     */
    public function hold(object $nativeConnection, Closure $work): void
    {
        $this->held[$nativeConnection] = [...($this->held[$nativeConnection] ?? []), $work];
    }

    /**
     * The outermost transaction of this connection committed: runs and forgets its held work.
     */
    public function committed(object $nativeConnection): void
    {
        $work = $this->held[$nativeConnection] ?? [];
        unset($this->held[$nativeConnection]);
        foreach ($work as $item) {
            $this->run($item);
        }
    }

    /**
     * The outermost transaction of this connection rolled back: forgets its held work.
     */
    public function rolledBack(object $nativeConnection): void
    {
        unset($this->held[$nativeConnection]);
    }

    /**
     * Runs work now; a failure is logged (the data is already committed, so the caller must not see it as failed).
     *
     * @param Closure(): void $work
     */
    public function run(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            ($this->logger ?? new NullLogger())->error('nowo_short_link: after-commit work failed: {class}', ['class' => $exception::class, 'exception' => $exception]);
        }
    }

    /**
     * How many work items are held (all connections).
     */
    public function heldCount(): int
    {
        $count = 0;
        foreach ($this->held as $work) {
            $count += count($work);
        }

        return $count;
    }

    /**
     * Drops everything still held (request / message boundary, `kernel.reset`). Held work at a boundary means the
     * surrounding transaction never committed through the bundle's DBAL middleware: logged as a warning.
     */
    public function reset(): void
    {
        $count = $this->heldCount();
        if ($count > 0) {
            ($this->logger ?? new NullLogger())->warning('nowo_short_link: dropped {count} after-commit item(s) of a transaction that did not commit through the bundle middleware.', ['count' => $count]);
        }
        $this->held = new WeakMap();
    }
}

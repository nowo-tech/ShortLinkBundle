<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application;

use Nowo\TransactionContract\TransientStorageFailure;
use Throwable;

/**
 * A write of the bundle that joined an open host transaction failed and the database rolled back the **whole** host
 * transaction, not only the bundle's savepoint (audit UY-1: an InnoDB deadlock victim on MySQL / MariaDB). Nothing of
 * the host transaction was committed.
 *
 * The connection is left inside a rollback-only transaction at the host's nesting level, so the host's later
 * statements never autocommit: its commit() fails and its rollBack() ends the transaction as usual. The host must roll
 * back and retry its whole unit of work, never catch this and continue. A host that continues anyway gets this exception
 * again from every later write of the bundle on that connection (links and clicks), never a silent loss. A lock conflict like any other
 * {@see TransientStorageFailure} (409 `CONFLICT_001` on a route of the bundle when nothing else was committed).
 */
final class HostTransactionLostException extends TransientStorageFailure
{
    /** Message (client-safe; the storage error stays as the previous exception). */
    public const MESSAGE = 'The database rolled back the whole surrounding transaction; nothing of it was committed. Roll back and retry the whole unit of work.';

    /**
     * Wraps the failure that made the database roll the host transaction back (none when the bundle refuses to write
     * into a transaction that is already rollback-only).
     */
    public function __construct(?int $retryAfterSeconds, ?Throwable $previous = null)
    {
        parent::__construct($retryAfterSeconds, self::MESSAGE, $previous);
    }
}

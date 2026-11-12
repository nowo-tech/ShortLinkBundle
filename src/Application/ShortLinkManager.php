<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkCreatedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkDeletedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkStateChangedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkUpdatedEvent;
use Nowo\ShortLinkBundle\Domain\Exception\AccessDeniedException;
use Nowo\ShortLinkBundle\Domain\Exception\CodeAttemptsExceededException;
use Nowo\ShortLinkBundle\Domain\Exception\CodeReservedException;
use Nowo\ShortLinkBundle\Domain\Exception\CodeTakenException;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Exception\QuotaExceededException;
use Nowo\ShortLinkBundle\Domain\Exception\ShortLinkNotFoundException;
use Nowo\ShortLinkBundle\Domain\Exception\TargetRefusedException;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\Expiry;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkTitle;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPage;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UpdateShortLink;
use Nowo\ShortLinkBundle\Domain\Port\CustomCodeLimiterInterface;
use Nowo\ShortLinkBundle\Domain\Port\ReservedCodeProviderInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkIdGeneratorInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkQuotaInterface;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

use function array_key_last;
use function array_slice;
use function count;
use function in_array;
use function ksort;

/**
 * Default {@see ShortLinkManagerInterface} (contract §3.3, §6). Stateless (worker mode): every value comes from the
 * arguments, the ports and the store; nothing is memoised between calls.
 */
final readonly class ShortLinkManager implements ShortLinkManagerInterface
{
    /** Most links `createMany()` accepts at once. */
    public const MAX_BATCH = 200;

    /** Longest random code (contract §3.1). */
    private const MAX_RANDOM_LENGTH = 16;

    /**
     * Wires the ports.
     */
    public function __construct(
        private ShortLinkStoreInterface $store,
        private CodeGenerator $codes,
        private ReservedCodeProviderInterface $reserved,
        private TargetGuardInterface $guard,
        private ShortLinkAccessInterface $access,
        private ShortLinkQuotaInterface $quota,
        private TenantLockInterface $lock,
        private ShortLinkIdGeneratorInterface $ids,
        private ShortUrlBuilderInterface $urls,
        private EventDispatcherInterface $events,
        private ManagerSettings $settings = new ManagerSettings(),
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
        private ?CustomCodeLimiterInterface $codeLimiter = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function create(TenantKey $tenant, CreateShortLink $command): ShortLinkView
    {
        $this->assertGranted(ShortLinkPermission::Manage, $tenant, $command->system);
        $now    = $this->now();
        $kind   = $this->knownKind($command->kind());
        $target = $this->guardedTarget($tenant, $command->target, null);
        $expiry = $this->futureExpiry($command->expiresAt, $now);
        $title  = LinkTitle::fromString($command->title);
        $custom = $command->customCode === null ? null : $this->availableCustomCode($tenant, $kind, $command->customCode, $command->system);
        $actor  = $this->access->currentActor();

        $insert = function () use ($tenant, $command, $kind, $target, $expiry, $title, $custom, $actor, $now): StoredLink {
            $this->assertQuota($tenant, $kind, 1);
            $link = new StoredLink(
                $this->ids->generate(),
                $tenant,
                $custom ?? $this->newRandomCode($tenant),
                $target,
                $title,
                $kind,
                $command->source,
                $command->metadata,
                $command->active,
                $expiry,
                $command->active ? null : ShortLinkStateChangedEvent::REASON_MANUAL,
                $now,
                $now,
                null,
                $actor,
                $actor,
            );
            $this->store->insert($link);

            return $link;
        };
        $created = function (StoredLink $link) use ($command): void {
            $this->events->dispatch($this->createdEvent($link, $command->system));
        };

        $link = $this->settings->isCounted($kind->value)
            ? $this->countedOutsideTransaction($tenant, fn (): StoredLink => $this->store->transactional($insert, $created))
            : $this->store->transactional($insert, $created);

        return $this->view($link, $now);
    }

    /**
     * {@inheritDoc}
     */
    public function createMany(TenantKey $tenant, array $commands): array
    {
        if (count($commands) > self::MAX_BATCH) {
            throw new InvalidShortLinkArgumentException('createMany() accepts at most 200 links.');
        }
        if ($commands === []) {
            return [];
        }
        $now      = $this->now();
        $actor    = $this->access->currentActor();
        $prepared = [];
        $counted  = false;
        foreach ($commands as $command) {
            $this->assertGranted(ShortLinkPermission::Manage, $tenant, $command->system);
            if ($command->customCode !== null) {
                throw new InvalidShortLinkArgumentException('createMany() creates random codes only.');
            }
            $kind       = $this->knownKind($command->kind());
            $counted    = $counted || $this->settings->isCounted($kind->value);
            $prepared[] = [
                $command,
                $kind,
                $this->guardedTarget($tenant, $command->target, null),
                $this->futureExpiry($command->expiresAt, $now),
                LinkTitle::fromString($command->title),
            ];
        }

        $insert = function () use ($tenant, $prepared, $actor, $now): array {
            /** @var array<int, array{StoredLink, bool, bool}> $results link, created now, system */
            $results = [];
            /** @var array<string, int> $seen first index of each source key */
            $seen = [];
            /** @var array<int, int> $duplicates index => first index of the same source */
            $duplicates = [];
            $pending    = [];
            $new        = [];
            foreach ($prepared as $index => [$command, $kind, $target, $expiry, $title]) {
                $source = $command->source;
                $key    = $source === null ? null : $kind->value . "\0" . $source->ref . "\0" . ($source->position ?? '-');
                if ($key !== null && isset($seen[$key])) {
                    $duplicates[$index] = $seen[$key];
                    continue;
                }
                if ($key !== null) {
                    $seen[$key] = $index;
                }
                $existing = $source === null ? null : $this->store->findBySource($tenant, $kind, $source->ref, $source->position);
                if ($existing instanceof StoredLink) {
                    $results[$index] = [$existing, false, $command->system];
                    continue;
                }
                $pending[$index]   = [$command, $kind, $target, $expiry, $title];
                $new[$kind->value] = ($new[$kind->value] ?? 0) + 1;
            }
            foreach ($new as $kind => $count) {
                $this->assertQuota($tenant, LinkKind::fromString($kind), $count);
            }
            foreach ($pending as $index => [$command, $kind, $target, $expiry, $title]) {
                $link = new StoredLink($this->ids->generate(), $tenant, $this->newRandomCode($tenant), $target, $title, $kind, $command->source, $command->metadata, $command->active, $expiry, $command->active ? null : ShortLinkStateChangedEvent::REASON_MANUAL, $now, $now, null, $actor, $actor);
                $this->store->insert($link);
                $results[$index] = [$link, true, $command->system];
            }
            foreach ($duplicates as $index => $first) {
                $results[$index] = [$results[$first][0], false, $results[$first][2]];
            }
            ksort($results);

            return $results;
        };
        $created = function (array $results): void {
            /** @var array<int, array{StoredLink, bool, bool}> $typed */
            $typed = $results;
            foreach ($typed as [$link, $isNew, $system]) {
                if ($isNew) {
                    $this->events->dispatch($this->createdEvent($link, $system));
                }
            }
        };

        /** @var array<int, array{StoredLink, bool, bool}> $results */
        $results = $counted
            ? $this->countedOutsideTransaction($tenant, fn (): array => $this->store->transactional($insert, $created))
            : $this->store->transactional($insert, $created);

        $views = [];
        foreach ($results as [$link]) {
            $views[] = $this->view($link, $now);
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function update(TenantKey $tenant, ShortLinkId $id, UpdateShortLink $command): ShortLinkView
    {
        $this->assertGranted(ShortLinkPermission::Manage, $tenant, $command->system);
        $now     = $this->now();
        $link    = $this->existing($tenant, $id);
        $target  = $this->guardedTarget($tenant, $command->target, $id);
        $expiry  = $this->futureExpiry($command->expiresAt, $now);
        $title   = LinkTitle::fromString($command->title);
        $changed = [];
        if ($target->toString() !== $link->target->toString()) {
            $changed[] = 'target';
        }
        if ($title?->value !== $link->title?->value) {
            $changed[] = 'title';
        }
        if ($expiry?->at != $link->expiry?->at) {
            $changed[] = 'expires_at';
        }
        if ($changed === []) {
            return $this->view($link, $now);
        }
        $actor   = $this->access->currentActor();
        $updated = $link->withEdit($target, $title, $expiry, $now, $actor);
        $this->store->transactional(
            // @igor-ignore - Closure passed to one transactional() call; after-commit work is held at most until the host commit or the request boundary, never stored.
            function () use ($updated): void {
                $this->store->update($updated);
            },
            // @igor-ignore - Closure passed to one transactional() call; after-commit work is held at most until the host commit or the request boundary, never stored.
            function () use ($id, $tenant, $changed, $link, $target, $actor): void {
                $this->events->dispatch(new ShortLinkUpdatedEvent($id, $tenant, $changed, $link->target->host(), $target->host(), $actor));
            },
        );

        return $this->view($updated, $now);
    }

    /**
     * {@inheritDoc}
     */
    public function disable(TenantKey $tenant, ShortLinkId $id): void
    {
        $this->changeState($tenant, $id, false);
    }

    /**
     * {@inheritDoc}
     */
    public function enable(TenantKey $tenant, ShortLinkId $id): void
    {
        $this->changeState($tenant, $id, true);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(TenantKey $tenant, ShortLinkId $id): void
    {
        $this->assertGranted(ShortLinkPermission::Manage, $tenant, false);
        $link  = $this->existing($tenant, $id);
        $actor = $this->access->currentActor();
        $this->store->transactional(
            // @igor-ignore - Closure passed to one transactional() call; after-commit work is held at most until the host commit or the request boundary, never stored.
            function () use ($link, $actor): void {
                $this->store->update($link->asTombstone($this->now(), $actor));
            },
            // @igor-ignore - Closure passed to one transactional() call; after-commit work is held at most until the host commit or the request boundary, never stored.
            function () use ($id, $tenant, $link, $actor): void {
                $this->events->dispatch(new ShortLinkDeletedEvent($id, $tenant, $link->code, $actor));
            },
        );
    }

    /**
     * {@inheritDoc}
     */
    public function find(TenantKey $tenant, ShortLinkId $id): ?ShortLinkView
    {
        $this->assertGranted(ShortLinkPermission::View, $tenant, false);
        $link = $this->store->find($tenant, $id);

        return $link instanceof StoredLink ? $this->view($link, $this->now()) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function list(TenantKey $tenant, ShortLinkListQuery $query): ShortLinkPage
    {
        $this->assertGranted(ShortLinkPermission::View, $tenant, $query->system);
        $now    = $this->now();
        $listed = $this->settings->listedKinds();
        $kinds  = $query->kind instanceof LinkKind ? $query->system || in_array($query->kind->value, $listed, true) ? [$query->kind->value] : [] : ($listed);
        $rows   = $kinds === [] ? [] : $this->store->list($tenant, $query, $kinds, $now);
        $more   = count($rows) > $query->limit;
        $rows   = array_slice($rows, 0, $query->limit);
        $items  = [];
        foreach ($rows as $row) {
            $items[] = $this->view($row, $now);
        }
        $last = array_key_last($rows);

        return new ShortLinkPage($items, $more && $last !== null ? $rows[$last]->id : null);
    }

    /**
     * {@inheritDoc}
     */
    public function shortUrl(ShortCode $code): string
    {
        return $this->urls->build($code);
    }

    private function changeState(TenantKey $tenant, ShortLinkId $id, bool $active): void
    {
        $this->assertGranted(ShortLinkPermission::Manage, $tenant, false);
        $link = $this->existing($tenant, $id);
        if ($link->active === $active) {
            return;
        }
        if ($active) {
            $this->assertTargetAllowed($tenant, $link->target, $id);
        }
        $now     = $this->now();
        $actor   = $this->access->currentActor();
        $from    = $link->stateAt($now);
        $updated = $link->withActive($active, $active ? null : ShortLinkStateChangedEvent::REASON_MANUAL, $now, $actor);
        $this->store->transactional(
            // @igor-ignore - Closure passed to one transactional() call; after-commit work is held at most until the host commit or the request boundary, never stored.
            function () use ($updated): void {
                $this->store->update($updated);
            },
            // @igor-ignore - Closure passed to one transactional() call; after-commit work is held at most until the host commit or the request boundary, never stored.
            function () use ($id, $tenant, $from, $updated, $now, $actor): void {
                $this->events->dispatch(new ShortLinkStateChangedEvent($id, $tenant, $from, $updated->stateAt($now), ShortLinkStateChangedEvent::REASON_MANUAL, $actor));
            },
        );
    }

    /**
     * @throws AccessDeniedException
     */
    private function assertGranted(ShortLinkPermission $permission, TenantKey $tenant, bool $system): void
    {
        if ($system || !$this->settings->enforceAccess) {
            return;
        }
        if (!$this->access->isGranted($permission, $tenant)) {
            throw new AccessDeniedException();
        }
    }

    /**
     * @throws ShortLinkNotFoundException
     */
    private function existing(TenantKey $tenant, ShortLinkId $id): StoredLink
    {
        return $this->store->find($tenant, $id) ?? throw new ShortLinkNotFoundException();
    }

    /**
     * @throws InvalidShortLinkArgumentException
     */
    private function knownKind(LinkKind $kind): LinkKind
    {
        if (!$this->settings->hasKind($kind->value)) {
            throw new InvalidShortLinkArgumentException('Unknown link kind.');
        }

        return $kind;
    }

    /**
     * Normalises a submitted target and runs the save guard.
     *
     * @throws TargetRefusedException
     */
    private function guardedTarget(TenantKey $tenant, string $url, ?ShortLinkId $id): TargetUrl
    {
        $target = TargetUrl::fromString($url, $this->settings->targetMaxLength);
        $this->assertTargetAllowed($tenant, $target, $id);

        return $target;
    }

    /**
     * @throws TargetRefusedException
     */
    private function assertTargetAllowed(TenantKey $tenant, TargetUrl $target, ?ShortLinkId $id): void
    {
        $verdict = $this->guard->check($target, GuardStage::Save);
        if ($verdict->refusal instanceof TargetRefusal) {
            $this->events->dispatch(new ShortLinkTargetRefusedEvent($tenant, GuardStage::Save, $verdict->refusal, $target->host(), $id));

            throw new TargetRefusedException($verdict->refusal);
        }
    }

    /**
     * @throws InvalidShortLinkArgumentException when the expiry is not in the future
     */
    private function futureExpiry(?DateTimeInterface $expiresAt, DateTimeImmutable $now): ?Expiry
    {
        if (!$expiresAt instanceof DateTimeInterface) {
            return null;
        }
        $expiry = Expiry::fromDateTime($expiresAt);
        if ($expiry->isReachedAt($now)) {
            throw new InvalidShortLinkArgumentException('The expiry must be in the future.');
        }

        return $expiry;
    }

    /**
     * A free custom code. People (not `system` callers) are limited per tenant + actor on custom-code lookups
     * (`code.custom_code_rate_limit`, audits L7 / NY-S4 L2): the attempt is consumed before the lookup, so concurrent
     * requests cannot pass together; over the limit no code is looked up until the window ends.
     *
     * @throws CodeAttemptsExceededException|CodeTakenException|InvalidShortLinkArgumentException
     */
    private function availableCustomCode(TenantKey $tenant, LinkKind $kind, string $value, bool $system): ShortCode
    {
        if (!$this->settings->allowsCustomCode($kind->value)) {
            throw new InvalidShortLinkArgumentException('Custom codes are not allowed for this kind of link.');
        }
        $code = ShortCode::custom($value);
        if (!$system && $this->codeLimiter instanceof CustomCodeLimiterInterface && !$this->codeLimiter->attempt($tenant, $this->access->currentActor())) {
            throw new CodeAttemptsExceededException();
        }
        if ($this->reserved->isReserved($code->value)) {
            throw new CodeReservedException();
        }
        if ($this->store->codeExists($code->value)) {
            throw new CodeTakenException();
        }

        return $code;
    }

    /**
     * A random code no row holds: `max_attempts` collisions grow the length by one (up to 16, logged).
     */
    private function newRandomCode(TenantKey $tenant): ShortCode
    {
        $length   = $this->settings->randomLength;
        $attempts = 0;
        while (true) {
            $code = $this->codes->generate($length);
            if (!$this->store->codeExists($code->value)) {
                return $code;
            }
            if (++$attempts >= $this->settings->maxAttempts && $length < self::MAX_RANDOM_LENGTH) {
                $attempts = 0;
                ++$length;
                $this->logger?->warning('nowo_short_link: {attempts} code collisions for tenant {tenant}; random codes grow to {length} characters.', [
                    'attempts' => $this->settings->maxAttempts,
                    'tenant'   => $tenant->value,
                    'length'   => $length,
                ]);
            }
        }
    }

    /**
     * Runs a counted create under the tenant lock. The lock is released when the work returns, so the work must commit
     * there: counted creates inside an open host transaction are refused (the lock would be released before the host
     * commits and another process could pass the same limit check).
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @throws InvalidShortLinkArgumentException inside an open transaction
     *
     * @return T
     */
    private function countedOutsideTransaction(TenantKey $tenant, Closure $work): mixed
    {
        if ($this->store->inTransaction()) {
            throw new InvalidShortLinkArgumentException('Links counted by the plan limit cannot be created inside an open database transaction; create them after your commit.');
        }

        return $this->lock->synchronized($tenant, $work);
    }

    /**
     * @throws QuotaExceededException
     */
    private function assertQuota(TenantKey $tenant, LinkKind $kind, int $adding): void
    {
        if (!$this->settings->isCounted($kind->value)) {
            return;
        }
        $limit = $this->quota->limitFor($tenant, $kind);
        if ($limit !== null && $this->store->countLive($tenant, $kind) + $adding > $limit) {
            throw new QuotaExceededException($limit);
        }
    }

    private function createdEvent(StoredLink $link, bool $system): ShortLinkCreatedEvent
    {
        return new ShortLinkCreatedEvent($link->id, $link->tenant, $link->code, $link->kind, $link->target->host(), $link->createdBy, $system);
    }

    private function view(StoredLink $link, DateTimeImmutable $now): ShortLinkView
    {
        return new ShortLinkView(
            $link->id,
            $link->tenant,
            $link->code,
            $link->target,
            $link->title,
            $link->kind,
            $link->source,
            $link->metadata,
            $link->stateAt($now),
            $link->expiry,
            $link->disabledReason,
            $link->createdAt,
            $link->updatedAt,
            $link->createdBy,
            $link->updatedBy,
            $this->urls->build($link->code),
        );
    }

    /**
     * The clock's instant in UTC, second precision (the columns' precision).
     */
    private function now(): DateTimeImmutable
    {
        $now = ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));

        // @igor-ignore - DateTimeImmutable::setTime() returns a new value; nothing is mutated.
        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }
}

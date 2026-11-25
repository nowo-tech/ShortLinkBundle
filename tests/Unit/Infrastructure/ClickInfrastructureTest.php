<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\ShortLinkBundle\Application\Click\ClickPayload;
use Nowo\ShortLinkBundle\Application\Click\ClickRecorder;
use Nowo\ShortLinkBundle\Application\Click\RedirectResolver;
use Nowo\ShortLinkBundle\Application\Default\BuiltInUserAgentClassifier;
use Nowo\ShortLinkBundle\Application\Default\ConfigDenylist;
use Nowo\ShortLinkBundle\Application\Default\NullCountryResolver;
use Nowo\ShortLinkBundle\Application\Guard\DenylistGuard;
use Nowo\ShortLinkBundle\Application\HostTransactionLostException;
use Nowo\ShortLinkBundle\Application\Store\ClickStoreInterface;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Model\ClickWriteMode;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UserAgentFamily;
use Nowo\ShortLinkBundle\Infrastructure\Http\ClickTerminateListener;
use Nowo\ShortLinkBundle\Infrastructure\Http\RedirectController;
use Nowo\ShortLinkBundle\Infrastructure\Messenger\RecordShortLinkClick;
use Nowo\ShortLinkBundle\Infrastructure\Messenger\RecordShortLinkClickHandler;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalClickStore;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalTransientFailureDetector;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ShortLinkBundle\Tests\Fixtures\ScriptedStore;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Click infrastructure outside a kernel: the click store's connection choice, a failing Messenger bus, the terminate
 * listener without a pending click.
 */
final class ClickInfrastructureTest extends TestCase
{
    public function testTheClickStoreUsesTheConfiguredConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE p_nowo_short_link_click (id INTEGER PRIMARY KEY AUTOINCREMENT, link_id VARCHAR(36), tenant_key VARCHAR(64), clicked_at VARCHAR(20), ip_prefix VARCHAR(43), ua_family VARCHAR(16), referrer_host VARCHAR(253), country VARCHAR(2), machine BOOLEAN)');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        (new DbalClickStore($registry, 'clicks', null, 'p_'))->insert(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), new DateTimeImmutable('2026-11-12 11:00:00+01:00'), null, null, null, null, true);
        self::assertSame('2026-11-12 10:00:00', $connection->fetchOne('SELECT clicked_at FROM p_nowo_short_link_click'));
    }

    /**
     * Audit UY2-4: a click never joins a host transaction that is already rollback-only (it would be lost with it).
     */
    public function testTheClickStoreRefusesARollbackOnlyTransaction(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE nowo_short_link_click (id INTEGER PRIMARY KEY AUTOINCREMENT, link_id VARCHAR(36), tenant_key VARCHAR(64), clicked_at VARCHAR(20), ip_prefix VARCHAR(43), ua_family VARCHAR(16), referrer_host VARCHAR(253), country VARCHAR(2), machine BOOLEAN)');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);
        $connection->beginTransaction();
        $connection->setRollbackOnly();

        try {
            (new DbalClickStore($registry, 'clicks'))->insert(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), new DateTimeImmutable(), null, null, null, null, false);
            self::fail('expected HostTransactionLostException');
        } catch (HostTransactionLostException $exception) {
            self::assertSame(1, $exception->retryAfterSeconds());
        }
        $connection->rollBack();
        self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM nowo_short_link_click'));
    }

    public function testTheClickStoreRefusesWrongNames(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn(new stdClass());
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        foreach ([new DbalClickStore($registry, 'clicks'), new DbalClickStore($registry)] as $store) {
            try {
                $store->insert(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), new DateTimeImmutable(), null, null, null, null, false);
                self::fail('Refused');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAFailingMessengerBusNeverBreaksTheRedirect(): void
    {
        $store = new class extends ScriptedStore {
            public function findActiveByCode(string $code, DateTimeImmutable $now): ResolvedLink
            {
                return new ResolvedLink(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), ShortCode::random($code), TargetUrl::fromString('https://example.com'), LinkKind::manual(), ShortLinkState::Active);
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new RuntimeException('transport down');
            }
        };
        $clicks     = $this->createStub(ClickStoreInterface::class);
        $logger     = new MemoryLogger();
        $controller = new RedirectController(
            new RedirectResolver($store, new DenylistGuard(new ConfigDenylist())),
            new BuiltInUserAgentClassifier(),
            new ClickRecorder($clicks, new NullCountryResolver(), $events),
            new Environment(new ArrayLoader(['@NowoShortLinkBundle/redirect/not_found.html.twig' => 'gone'])),
            writeMode: ClickWriteMode::Messenger,
            bus: $bus,
            logger: $logger,
        );

        $response = $controller(Request::create('/s/aB3xY9k'), 'aB3xY9k');
        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['class']);
    }

    public function testThrowingLogHandlersAreSwallowedOnTheClickPath(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('log handler down');
            }
        };
        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                throw new RuntimeException('listener down');
            }
        };
        $clicks = $this->createStub(ClickStoreInterface::class);
        $clicks->method('insert')->willThrowException(new RuntimeException('table down'));
        $recorder = new ClickRecorder($clicks, new NullCountryResolver(), $events, logger: $logger);
        $listener = new ClickTerminateListener($recorder, $events, $logger);
        $request  = Request::create('/');
        $request->attributes->set(ClickTerminateListener::REFUSED_ATTRIBUTE, new ShortLinkTargetRefusedEvent(TenantKey::default(), GuardStage::Redirect, TargetRefusal::Denylisted, 'bad.example', null));
        $request->attributes->set(ClickTerminateListener::ATTRIBUTE, [new ClickPayload(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), LinkKind::manual(), null, new DateTimeImmutable(), null, UserAgentFamily::Unknown, null, false), null]);

        $listener->onTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));
        self::assertFalse($request->attributes->has(ClickTerminateListener::REFUSED_ATTRIBUTE));
        self::assertFalse($request->attributes->has(ClickTerminateListener::ATTRIBUTE));
    }

    public function testTheTerminateListenerIgnoresRequestsWithoutAClick(): void
    {
        $clicks = $this->createMock(ClickStoreInterface::class);
        $clicks->expects(self::never())->method('insert');
        $events   = $this->createStub(EventDispatcherInterface::class);
        $listener = new ClickTerminateListener(new ClickRecorder($clicks, new NullCountryResolver(), $events), $events);
        $request  = Request::create('/');
        $request->attributes->set(ClickTerminateListener::ATTRIBUTE, ['not a payload']);

        $listener->onTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));
        self::assertFalse($request->attributes->has(ClickTerminateListener::ATTRIBUTE));
    }

    public function testTheClickStoreTellsALockConflictApart(): void
    {
        $locked = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $locked->executeStatement('CREATE TABLE nowo_short_link_click (id INTEGER PRIMARY KEY)');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($locked);
        $store = new DbalClickStore($registry, 'clicks');

        try {
            $store->insert(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), new DateTimeImmutable(), null, null, null, null, false);
            self::fail('a missing column is a real error');
        } catch (TransientStorageFailure) {
            self::fail('not a lock conflict');
        } catch (DriverException) {
            $this->addToAssertionCount(1);
        }

        $deadlock = new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);
        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willThrowException($deadlock);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        try {
            (new DbalClickStore($registry, 'clicks'))->insert(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), new DateTimeImmutable(), null, null, null, null, false);
            self::fail('expected a transient failure');
        } catch (TransientStorageFailure $failure) {
            self::assertSame($deadlock, $failure->getPrevious());
        }
    }

    public function testMessengerClicksRethrowALockConflictSoTheTransportRetries(): void
    {
        $events = $this->createStub(EventDispatcherInterface::class);
        $click  = new ClickPayload(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), LinkKind::manual(), null, new DateTimeImmutable(), null, UserAgentFamily::Unknown, null, false);
        $busy   = TransientStorageFailure::fromPrevious(new RuntimeException('deadlock'), 1);

        $clicks = $this->createStub(ClickStoreInterface::class);
        $clicks->method('insert')->willThrowException(new RuntimeException('wrapped', 0, $busy));
        $logger  = new MemoryLogger();
        $handler = new RecordShortLinkClickHandler(new ClickRecorder($clicks, new NullCountryResolver(), $events, logger: $logger));
        try {
            $handler(new RecordShortLinkClick($click));
            self::fail('expected the lock conflict');
        } catch (RuntimeException $failure) {
            self::assertSame($busy, $failure->getPrevious());
        }
        self::assertCount(1, $logger->records);

        // The redirect paths (sync / terminate) keep logging: the answer is already sent.
        (new ClickRecorder($clicks, new NullCountryResolver(), $events))->record($click, null, true);

        // Other failures stay logged on the Messenger path too (a broken table must not loop the worker).
        $broken = $this->createStub(ClickStoreInterface::class);
        $broken->method('insert')->willThrowException(new RuntimeException('table down'));
        (new RecordShortLinkClickHandler(new ClickRecorder($broken, new NullCountryResolver(), $events)))(new RecordShortLinkClick($click));

        // The liveness check of a Messenger click: a lock conflict is rethrown too.
        $links = new class extends ScriptedStore {
            public function isLive(ShortLinkId $id, TenantKey $tenant): bool
            {
                throw TransientStorageFailure::fromPrevious(new RuntimeException('lock wait'), 1);
            }
        };
        $this->expectException(TransientStorageFailure::class);
        (new RecordShortLinkClickHandler(new ClickRecorder($clicks, new NullCountryResolver(), $events, links: $links)))(new RecordShortLinkClick($click));
    }

    /**
     * Audit UK-SL-4: a raw DBAL lock error of the liveness read (not wrapped by a runner) is retried by the transport too.
     */
    public function testARawDbalLockErrorOfTheLivenessReadIsRethrownForMessenger(): void
    {
        $deadlock = new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);
        $links = new class($deadlock) extends ScriptedStore {
            public function __construct(private readonly DeadlockException $deadlock)
            {
                parent::__construct();
            }

            public function isLive(ShortLinkId $id, TenantKey $tenant): bool
            {
                throw $this->deadlock;
            }
        };
        $clicks = $this->createMock(ClickStoreInterface::class);
        $clicks->expects(self::never())->method('insert');
        $click = new ClickPayload(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), LinkKind::manual(), null, new DateTimeImmutable(), null, UserAgentFamily::Unknown, null, false);

        // A recorder built by hand without a detector only rethrows the contract's TransientStorageFailure: the raw
        // DBAL error is logged and the click dropped (the container always wires the DBAL detector).
        $logger = new MemoryLogger();
        (new RecordShortLinkClickHandler(new ClickRecorder($clicks, new NullCountryResolver(), $this->createStub(EventDispatcherInterface::class), logger: $logger, links: $links)))(new RecordShortLinkClick($click));
        self::assertCount(1, $logger->records);

        $this->expectExceptionObject($deadlock);
        (new RecordShortLinkClickHandler(new ClickRecorder($clicks, new NullCountryResolver(), $this->createStub(EventDispatcherInterface::class), links: $links, transient: new DbalTransientFailureDetector())))(new RecordShortLinkClick($click));
    }
}

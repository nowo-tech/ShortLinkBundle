<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\ShortLinkBundle\Application\Click\ClickRecorder;
use Nowo\ShortLinkBundle\Application\Click\RedirectResolver;
use Nowo\ShortLinkBundle\Application\Default\BuiltInUserAgentClassifier;
use Nowo\ShortLinkBundle\Application\Default\ConfigDenylist;
use Nowo\ShortLinkBundle\Application\Default\NullCountryResolver;
use Nowo\ShortLinkBundle\Application\Guard\DenylistGuard;
use Nowo\ShortLinkBundle\Application\Store\ClickStoreInterface;
use Nowo\ShortLinkBundle\Application\Store\StoredLink;
use Nowo\ShortLinkBundle\Domain\Model\ClickWriteMode;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Http\ClickTerminateListener;
use Nowo\ShortLinkBundle\Infrastructure\Http\RedirectController;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\DbalClickStore;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use Nowo\ShortLinkBundle\Tests\Unit\Application\ScriptedStore;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use stdClass;
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
            public function findByCode(string $code): StoredLink
            {
                $now = new DateTimeImmutable();

                return new StoredLink(ShortLinkId::fromString('0190a6d0-0000-7000-8000-000000000001'), TenantKey::default(), ShortCode::random($code), TargetUrl::fromString('https://example.com'), null, LinkKind::manual(), null, null, true, null, null, $now, $now, null, null, null);
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
            new RedirectResolver($store, new DenylistGuard(new ConfigDenylist()), $events),
            new BuiltInUserAgentClassifier(),
            new ClickRecorder($clicks, new NullCountryResolver(), $events),
            new Environment(new ArrayLoader(['@NowoShortLink/redirect/not_found.html.twig' => 'gone'])),
            writeMode: ClickWriteMode::Messenger,
            bus: $bus,
            logger: $logger,
        );

        $response = $controller(Request::create('/s/aB3xY9k'), 'aB3xY9k');
        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['class']);
    }

    public function testTheTerminateListenerIgnoresRequestsWithoutAClick(): void
    {
        $clicks = $this->createMock(ClickStoreInterface::class);
        $clicks->expects(self::never())->method('insert');
        $events   = $this->createStub(EventDispatcherInterface::class);
        $listener = new ClickTerminateListener(new ClickRecorder($clicks, new NullCountryResolver(), $events));
        $request  = Request::create('/');
        $request->attributes->set(ClickTerminateListener::ATTRIBUTE, ['not a payload']);

        $listener->onTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));
        self::assertFalse($request->attributes->has(ClickTerminateListener::ATTRIBUTE));
    }
}

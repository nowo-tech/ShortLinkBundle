<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkClickedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UserAgentFamily;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestAccess;
use Nowo\ShortLinkBundle\Tests\Fixtures\RecordingCountryResolver;
use Nowo\ShortLinkBundle\Tests\Fixtures\RecordingListener;
use Nowo\ShortLinkBundle\Tests\Fixtures\ThrowingListener;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_replace_recursive;
use function count;
use function dechex;
use function gc_collect_cycles;
use function hrtime;
use function memory_get_usage;
use function sort;
use function str_repeat;

/**
 * The public redirect end to end (contract §7, §9) on the standard host: step order, neutral answers, headers, limits,
 * click rows per store flag and GPC, write modes, failures contained, worker mode.
 */
class RedirectTest extends KernelTestCase
{
    private TestKernel $kernel;

    protected function host(): Host
    {
        return Host::standard();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = [], bool $messenger = false): void
    {
        $host      = $this->host();
        $configure = $host->configure();
        /** @var array<string, mixed> $override */
        $override     = array_replace_recursive(['click_log' => ['write' => 'sync', 'country_resolver' => RecordingCountryResolver::class]], $config);
        $this->kernel = $this->bootKernel(
            $host->bundleConfig($override),
            TestKernel::defaultDatabaseUrl(),
            static function (ContainerConfigurator $container, string $url) use ($configure, $messenger): void {
                $configure($container, $url);
                $container->services()->set(RecordingCountryResolver::class)->public();
                $container->services()->set(ThrowingListener::class)->public()
                    ->tag('kernel.event_listener', ['event' => ShortLinkClickedEvent::class, 'method' => 'onEvent'])
                    ->tag('kernel.event_listener', ['event' => ShortLinkTargetRefusedEvent::class, 'method' => 'onEvent']);
                if ($messenger) {
                    $container->extension('framework', ['messenger' => ['default_bus' => 'messenger.bus.default', 'buses' => ['messenger.bus.default' => []]]]);
                }
            },
            $host->kind === Host::STANDARD,
        );
        $host->resetSchema($this->kernel);
        if ($host->kind === Host::STANDARD) {
            $tokens = $this->container()->get('security.token_storage');
            self::assertInstanceOf(TokenStorageInterface::class, $tokens);
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        } else {
            $access = $this->container()->get(TestAccess::class);
            self::assertInstanceOf(TestAccess::class, $access);
            $access->granted = ['default' => [ShortLinkPermission::View, ShortLinkPermission::Manage]];
        }
    }

    public function testAnActiveLinkRedirectsWithTheProtectiveHeadersAndNoSession(): void
    {
        $this->boot();
        $link     = $this->create('https://example.com/landing?a=1#top');
        $response = $this->get('/s/' . $link->code->value . '?url=https://evil.example&next=//evil.example');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://example.com/landing?a=1#top', $response->headers->get('Location'));
        self::assertSame('', $response->getContent());
        $this->assertProtectiveHeaders($response);
        self::assertSame([], $response->headers->getCookies());
        self::assertSame(1, $this->clickCount());
        self::assertCount(1, $this->listener()->of(ShortLinkClickedEvent::class));
    }

    public function testEveryRefusalAnswersTheSameNeutral404(): void
    {
        $this->boot();
        $manager  = $this->manager();
        $disabled = $this->create('https://example.com/disabled');
        $manager->disable(TenantKey::default(), $disabled->id);
        $expired = $this->create('https://example.com/expired', new DateTimeImmutable('2026-11-12 10:30:00'));
        $deleted = $this->create('https://example.com/deleted');
        $manager->delete(TenantKey::default(), $deleted->id);
        $refused = $this->create('https://example.com/refused');
        $store   = $this->container()->get(ShortLinkStoreInterface::class);
        self::assertInstanceOf(ShortLinkStoreInterface::class, $store);
        $row = $store->find(TenantKey::default(), $refused->id);
        self::assertNotNull($row);
        $store->update($row->withEdit(TargetUrl::fromString('https://later.bad.example/x'), null, null, new DateTimeImmutable(Host::NOW), null));
        $this->clock()->modify('+1 hour');

        $answers = [];
        foreach (['unknown' => 'Zz9Zz9Z', 'bad characters' => 'a_b!', 'too short' => 'ab', 'disabled' => $disabled->code->value, 'expired' => $expired->code->value, 'deleted' => $deleted->code->value, 'refused' => $refused->code->value, 'case' => strtoupper($disabled->code->value) === $disabled->code->value ? strtolower($disabled->code->value) : strtoupper($disabled->code->value)] as $reason => $code) {
            $response = $this->get('/s/' . $code);
            self::assertSame(404, $response->getStatusCode(), $reason);
            $this->assertProtectiveHeaders($response);
            $headers = $response->headers->all();
            unset($headers['date']);
            $answers[$reason] = [$response->getContent(), $headers];
        }
        foreach ($answers as $reason => $answer) {
            self::assertSame($answers['unknown'], $answer, $reason . ' answers byte-identical to an unknown code');
        }
        self::assertStringContainsString('noindex', (string) $answers['unknown'][0]);
        self::assertStringNotContainsString('<script', (string) $answers['unknown'][0]);
        self::assertStringNotContainsString('example.com', (string) $answers['unknown'][0]);
        self::assertSame(0, $this->clickCount());

        $refusals = $this->listener()->of(ShortLinkTargetRefusedEvent::class);
        self::assertCount(1, $refusals);
        self::assertSame(GuardStage::Redirect, $refusals[0]->stage);
        self::assertSame('later.bad.example', $refusals[0]->targetHost);
    }

    public function testHeadIsAnsweredButNeverLoggedAndOtherMethodsAre405(): void
    {
        $this->boot(['redirect' => ['rate_limit' => ['hits' => ['limit' => 1]]]]);
        $link = $this->create('https://example.com');

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(302, $this->request('HEAD', '/s/' . $link->code->value)->getStatusCode());
        }
        self::assertSame(0, $this->clickCount());
        self::assertSame(302, $this->get('/s/' . $link->code->value)->getStatusCode());
        self::assertSame(1, $this->clickCount(), 'HEAD consumed no hit token');

        try {
            $this->request('POST', '/s/' . $link->code->value);
            self::fail('POST is not allowed.');
        } catch (MethodNotAllowedHttpException $exception) {
            self::assertSame(405, $exception->getStatusCode());
            self::assertSame('GET, HEAD', $exception->getHeaders()['Allow'] ?? null);
        }
    }

    public function testTheMissLimiterAnswers429BeforeAnyLookup(): void
    {
        $this->boot(['redirect' => ['rate_limit' => ['misses' => ['limit' => 3]]]]);
        $link = $this->create('https://example.com');

        foreach (['nope123', 'a!', 'nope456'] as $code) {
            self::assertSame(404, $this->get('/s/' . $code)->getStatusCode());
        }
        $blocked = $this->get('/s/' . $link->code->value);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $blocked->headers->get('Retry-After'));
        $this->assertProtectiveHeaders($blocked);
        self::assertSame(302, $this->get('/s/' . $link->code->value, ['REMOTE_ADDR' => '198.51.100.9'])->getStatusCode(), 'another IP is not limited');
    }

    public function testOverTheHitLimitALinkRedirectsUnloggedOrIsBlocked(): void
    {
        $this->boot(['redirect' => ['rate_limit' => ['hits' => ['limit' => 2]]]]);
        $link = $this->create('https://example.com');
        for ($i = 0; $i < 4; ++$i) {
            self::assertSame(302, $this->get('/s/' . $link->code->value)->getStatusCode());
        }
        self::assertSame(2, $this->clickCount());

        $this->boot(['redirect' => ['rate_limit' => ['hits' => ['limit' => 2], 'over_limit_hits' => 'block']]]);
        $link = $this->create('https://example.com');
        $this->get('/s/' . $link->code->value);
        $this->get('/s/' . $link->code->value);
        self::assertSame(429, $this->get('/s/' . $link->code->value)->getStatusCode());
    }

    public function testClickRowsHoldOnlyTruncatedCoarseData(): void
    {
        $this->boot();
        $link                       = $this->create('https://example.com', null, LinkKind::email(), new LinkSource('send-7', 'Button', 2));
        $this->countries()->country = 'ES';

        $this->get('/s/' . $link->code->value, [
            'REMOTE_ADDR'     => '203.0.113.77',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
            'HTTP_REFERER'    => 'https://News.Example.com/article?token=secret',
        ]);
        $this->get('/s/' . $link->code->value, ['REMOTE_ADDR' => '2001:db8:1234:5678::1', 'HTTP_USER_AGENT' => 'Slackbot-LinkExpanding 1.0']);
        $this->get('/s/' . $link->code->value, ['REMOTE_ADDR' => '203.0.113.78', 'HTTP_USER_AGENT' => 'curl/8.5.0', 'HTTP_REFERER' => 'https://a.example/', 'HTTP_SEC_GPC' => '1']);

        $rows = $this->clicks();
        self::assertSame(['203.0.113.0/24', 'firefox', 'news.example.com', 'ES', false], [$rows[0]['ip_prefix'], $rows[0]['ua_family'], $rows[0]['referrer_host'], $rows[0]['country'], (bool) $rows[0]['machine']]);
        self::assertSame(['2001:db8:1234::/48', 'preview', null, true], [$rows[1]['ip_prefix'], $rows[1]['ua_family'], $rows[1]['referrer_host'], (bool) $rows[1]['machine']]);
        self::assertSame([null, null, null, null, true], [$rows[2]['ip_prefix'], $rows[2]['ua_family'], $rows[2]['referrer_host'], $rows[2]['country'], (bool) $rows[2]['machine']], 'GPC: count only');
        self::assertSame(['203.0.113.77', '2001:db8:1234:5678::1'], $this->countries()->asked, 'the resolver sees the raw IP, GPC clicks are never resolved');
        self::assertSame($link->id->value, $rows[0]['link_id']);
        self::assertSame('default', $rows[0]['tenant_key']);
        self::assertIsString($rows[0]['clicked_at']);
        self::assertSame('2026-11-12 10:00:00', (new DateTimeImmutable($rows[0]['clicked_at']))->format('Y-m-d H:i:s'));

        $events = $this->listener()->of(ShortLinkClickedEvent::class);
        self::assertCount(3, $events);
        self::assertSame('send-7', $events[0]->sourceRef);
        self::assertSame(UserAgentFamily::Firefox, $events[0]->uaFamily);
        self::assertSame('ES', $events[0]->country?->value);
        self::assertTrue($events[1]->machine);
        self::assertNull($events[2]->uaFamily);
    }

    public function testStoreFlagsSwitchColumnsOffAndFailuresNeverBreakTheRedirect(): void
    {
        $this->boot(['click_log' => ['store' => ['ip_prefix' => false, 'user_agent_family' => false, 'referrer_host' => false, 'country' => false], 'honour_gpc' => false]]);
        $link                       = $this->create('https://example.com');
        $this->countries()->country = 'ES';
        $this->get('/s/' . $link->code->value, ['HTTP_USER_AGENT' => 'curl/8', 'HTTP_REFERER' => 'https://a.example/', 'HTTP_SEC_GPC' => '1']);
        $rows = $this->clicks();
        self::assertSame([null, null, null, null, true], [$rows[0]['ip_prefix'], $rows[0]['ua_family'], $rows[0]['referrer_host'], $rows[0]['country'], (bool) $rows[0]['machine']]);
        self::assertSame([], $this->countries()->asked);

        $this->boot();
        $link                       = $this->create('https://example.com');
        $this->countries()->failing = true;
        self::assertSame(302, $this->get('/s/' . $link->code->value)->getStatusCode());
        self::assertNull($this->clicks()[0]['country'], 'a failing resolver leaves the country empty');

        $this->connection()->executeStatement('DROP TABLE ' . $this->host()->tablePrefix() . 'nowo_short_link_click');
        self::assertSame(302, $this->get('/s/' . $link->code->value)->getStatusCode(), 'a broken click table never breaks the redirect');
    }

    public function testTerminateModeWritesTheClickAfterTheResponse(): void
    {
        $this->boot(['click_log' => ['write' => 'terminate']]);
        $link                       = $this->create('https://example.com');
        $http                       = $this->http();
        $request                    = Request::create('/s/' . $link->code->value, server: ['REMOTE_ADDR' => '203.0.113.5']);
        $this->countries()->country = 'FR';

        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, $this->clickCount(), 'nothing written before the response is sent');
        self::assertSame([], $this->countries()->asked, 'the country lookup waits for the terminate phase');
        $http->terminate($request, $response);
        self::assertSame(1, $this->clickCount());
        self::assertSame('FR', $this->clicks()[0]['country']);

        $other = Request::create('/echo');
        $http->terminate($other, $http->handle($other, HttpKernelInterface::MAIN_REQUEST, false));
        self::assertSame(1, $this->clickCount(), 'other requests record nothing');
    }

    public function testMessengerModeQueuesTheClickWithoutTheRawIp(): void
    {
        $this->boot(['click_log' => ['write' => 'messenger', 'store' => ['country' => false]]], true);

        $link = $this->create('https://example.com');

        self::assertSame(302, $this->get('/s/' . $link->code->value, ['REMOTE_ADDR' => '203.0.113.5'])->getStatusCode());
        self::assertSame(1, $this->clickCount(), 'handled synchronously by the default bus without a transport');
        self::assertSame('203.0.113.0/24', $this->clicks()[0]['ip_prefix']);
        self::assertSame([], $this->countries()->asked);
    }

    public function testDisabledClickLogAndLimitsAnswerWithoutTouchingThem(): void
    {
        $this->boot(['click_log' => ['enabled' => false], 'redirect' => ['rate_limit' => ['enabled' => false], 'status' => 307]]);
        $link = $this->create('https://example.com');
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(404, $this->get('/s/zzzzzz' . $i)->getStatusCode());
        }
        $response = $this->get('/s/' . $link->code->value);
        self::assertSame(307, $response->getStatusCode());
        self::assertSame(0, $this->clickCount());
    }

    public function testIpv6ClientsAreLimitedPerNetworkAndMappedAddressesAsIpv4(): void
    {
        $this->boot(['redirect' => ['rate_limit' => ['misses' => ['limit' => 10]]]]);
        $link = $this->create('https://example.com');

        for ($i = 1; $i <= 10; ++$i) {
            self::assertSame(404, $this->get('/s/nope' . $i . 'x', ['REMOTE_ADDR' => '2001:db8:aaaa:bbbb::' . dechex($i)])->getStatusCode());
        }
        self::assertSame(429, $this->get('/s/' . $link->code->value, ['REMOTE_ADDR' => '2001:db8:aaaa:bbbb:ffff::1'])->getStatusCode(), 'the whole /64 is one client');
        self::assertSame(302, $this->get('/s/' . $link->code->value, ['REMOTE_ADDR' => '2001:db8:aaaa:bbbc::1'])->getStatusCode(), 'the next /64 is another client');

        for ($i = 1; $i <= 10; ++$i) {
            $this->get('/s/miss' . $i . 'xx', ['REMOTE_ADDR' => '::ffff:198.51.100.7']);
        }
        self::assertSame(429, $this->get('/s/' . $link->code->value, ['REMOTE_ADDR' => '198.51.100.7'])->getStatusCode(), 'IPv4-mapped misses count for the IPv4 address');
        self::assertSame(302, $this->get('/s/' . $link->code->value, ['REMOTE_ADDR' => '198.51.100.8'])->getStatusCode(), 'IPv4 is limited per address');
    }

    public function testThrowingListenersNeverChangeTheAnswerInSyncMode(): void
    {
        $this->boot();
        $link                      = $this->create('https://example.com');
        $refused                   = $this->refusedLink();
        $this->throwing()->failing = true;

        self::assertSame(302, $this->get('/s/' . $link->code->value)->getStatusCode());
        self::assertSame(1, $this->clickCount());
        $unknown = $this->get('/s/Unknown1');
        $answer  = $this->get('/s/' . $refused->code->value);
        self::assertSame(404, $answer->getStatusCode());
        self::assertSame($unknown->getContent(), $answer->getContent());
        self::assertSame(2, $this->throwing()->calls, 'click + refused listeners ran and failed');
    }

    public function testTheRefusedEventWaitsForTerminateAndThrowingListenersCannotStopTheWorker(): void
    {
        $this->boot(['click_log' => ['write' => 'terminate']]);
        $link                      = $this->create('https://example.com');
        $refused                   = $this->refusedLink();
        $this->throwing()->failing = true;
        $http                      = $this->http();

        $request  = Request::create('/s/' . $refused->code->value, server: ['REMOTE_ADDR' => '203.0.113.1']);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->listener()->of(ShortLinkTargetRefusedEvent::class), 'nothing dispatched before the response');
        $http->terminate($request, $response);
        self::assertCount(1, $this->listener()->of(ShortLinkTargetRefusedEvent::class));

        $request  = Request::create('/s/' . $link->code->value, server: ['REMOTE_ADDR' => '203.0.113.1']);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        $http->terminate($request, $response);
        self::assertSame(1, $this->clickCount());
        self::assertSame(2, $this->throwing()->calls);
    }

    public function testUnreadableRowsLongCodesAndGpcWithoutHonourAnswerSafely(): void
    {
        $this->boot(['click_log' => ['honour_gpc' => false]]);
        $link = $this->create('https://example.com');
        $this->connection()->update($this->host()->tablePrefix() . 'nowo_short_link', ['tenant_key' => '~erased'], ['id' => $link->id->value]);
        self::assertSame(404, $this->get('/s/' . $link->code->value)->getStatusCode(), 'an unreadable row is not found');
        self::assertSame(404, $this->get('/s/' . str_repeat('a', 80))->getStatusCode(), 'a long code reaches the neutral 404');

        $other = $this->create('https://example.com/b');
        $this->get('/s/' . $other->code->value, ['REMOTE_ADDR' => '203.0.113.77', 'HTTP_REFERER' => 'https://a.example/', 'HTTP_SEC_GPC' => '1']);
        $rows = $this->clicks();
        self::assertSame(['203.0.113.0/24', 'a.example'], [$rows[0]['ip_prefix'], $rows[0]['referrer_host']], 'honour_gpc: false keeps the configured columns');
    }

    public function testOneKeptAliveKernelServesThousandsOfRedirectsWithoutBleedOrGrowth(): void
    {
        $this->boot(['click_log' => ['write' => 'terminate'], 'redirect' => ['rate_limit' => ['enabled' => false]]]);
        $manager = $this->manager();
        $tenants = [TenantKey::fromString('tenant-a'), TenantKey::fromString('tenant-b')];
        $links   = [];
        foreach ($tenants as $t => $tenant) {
            $active    = $manager->create($tenant, new CreateShortLink('https://' . $tenant->value . '.example/', system: true));
            $disabled  = $manager->create($tenant, new CreateShortLink('https://' . $tenant->value . '.example/off', active: false, system: true));
            $expired   = $manager->create($tenant, new CreateShortLink('https://' . $tenant->value . '.example/old', expiresAt: new DateTimeImmutable('2026-11-12 10:00:30'), system: true));
            $links[$t] = [$active, $disabled, $expired];
        }
        $this->clock()->modify('+1 minute');
        $http      = $this->http();
        $baseline  = 0;
        $durations = [];
        for ($i = 0; $i < 5000; ++$i) {
            $t           = $i % 2;
            $state       = $i % 3;
            $link        = $links[$t][$state];
            $request     = Request::create('/s/' . $link->code->value, server: ['REMOTE_ADDR' => '203.0.113.' . ($i % 200)]);
            $start       = hrtime(true);
            $response    = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            $durations[] = hrtime(true) - $start;
            $http->terminate($request, $response);

            if ($state === 0) {
                self::assertSame(302, $response->getStatusCode());
                self::assertSame('https://' . $tenants[$t]->value . '.example/', $response->headers->get('Location'), 'request ' . $i . ' answers its own tenant\'s target');
            } else {
                self::assertSame(404, $response->getStatusCode());
            }
            if ($i === 500) {
                $this->listener()->clear();
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
            if ($i % 500 === 0) {
                $this->listener()->clear();
            }
        }
        $this->listener()->clear();
        gc_collect_cycles();
        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over 5,000 redirects');
        self::assertSame(1667, $this->clickCount());

        sort($durations);
        $p95 = $durations[(int) (count($durations) * 0.95)] / 1e6;
        self::assertLessThan(20.0, $p95, 'in-kernel p95 (SQLite, array cache) stays under 20 ms: regression guard, not the production number');
    }

    private function refusedLink(): ShortLinkView
    {
        $link  = $this->create('https://example.com/refused');
        $store = $this->container()->get(ShortLinkStoreInterface::class);
        self::assertInstanceOf(ShortLinkStoreInterface::class, $store);
        $row = $store->find(TenantKey::default(), $link->id);
        self::assertNotNull($row);
        $store->update($row->withEdit(TargetUrl::fromString('https://later.bad.example/x'), null, null, new DateTimeImmutable(Host::NOW), null));

        return $link;
    }

    private function throwing(): ThrowingListener
    {
        $listener = $this->container()->get(ThrowingListener::class);
        self::assertInstanceOf(ThrowingListener::class, $listener);

        return $listener;
    }

    private function create(string $target, ?DateTimeImmutable $expiresAt = null, ?LinkKind $kind = null, ?LinkSource $source = null): ShortLinkView
    {
        return $this->manager()->create(TenantKey::default(), new CreateShortLink($target, expiresAt: $expiresAt, kind: $kind, source: $source, system: true));
    }

    /**
     * @param array<string, string> $server
     */
    private function get(string $uri, array $server = []): Response
    {
        return $this->request('GET', $uri, $server);
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $method, string $uri, array $server = []): Response
    {
        $request  = Request::create($uri, $method, server: $server + ['REMOTE_ADDR' => '203.0.113.10']);
        $http     = $this->http();
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        self::assertFalse($request->hasSession() && $request->getSession()->isStarted(), 'no session is started');
        $http->terminate($request, $response);

        return $response;
    }

    private function assertProtectiveHeaders(Response $response): void
    {
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    private function http(): HttpKernelInterface&TerminableInterface
    {
        $http = $this->kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        return $http;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function manager(): ShortLinkManagerInterface
    {
        $manager = $this->container()->get(ShortLinkManagerInterface::class);
        self::assertInstanceOf(ShortLinkManagerInterface::class, $manager);

        return $manager;
    }

    private function listener(): RecordingListener
    {
        $listener = $this->container()->get(RecordingListener::class);
        self::assertInstanceOf(RecordingListener::class, $listener);

        return $listener;
    }

    private function countries(): RecordingCountryResolver
    {
        $countries = $this->container()->get(RecordingCountryResolver::class);
        self::assertInstanceOf(RecordingCountryResolver::class, $countries);

        return $countries;
    }

    private function clock(): MockClock
    {
        $clock = $this->container()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    private function connection(): Connection
    {
        return $this->host()->connection($this->kernel);
    }

    private function clickCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->host()->tablePrefix() . 'nowo_short_link_click');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clicks(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT * FROM ' . $this->host()->tablePrefix() . 'nowo_short_link_click ORDER BY id');
    }
}

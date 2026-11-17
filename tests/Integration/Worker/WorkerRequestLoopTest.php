<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Worker;

use Nowo\ShortLinkBundle\Application\Guard\OwnHostGuard;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function json_decode;
use function memory_get_usage;

use const JSON_THROW_ON_ERROR;

/**
 * FrankenPHP worker mode with the kernel kept alive and NOT reset (`FRANKENPHP_RESET_KERNEL=false`): one booted
 * kernel serves many requests through `http_kernel`, no services resetter runs. Each request must see only its own
 * data and memory must stay flat. S0 skeleton: later slices add their stateful paths to this loop.
 */
final class WorkerRequestLoopTest extends KernelTestCase
{
    private const REQUESTS = 2000;

    public function testOneKeptAliveKernelServesManyRequestsWithoutLeakingStateOrMemory(): void
    {
        $kernel = $this->bootKernel(['redirect' => ['path_prefix' => '/go']]);
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        $baseline = 0;
        for ($i = 0; $i < self::REQUESTS; ++$i) {
            $tenant   = $i % 2 === 0 ? 'tenant-a' : 'tenant-b';
            $request  = Request::create('/echo', 'GET', server: ['HTTP_X_TEST_TENANT' => $tenant, 'REMOTE_ADDR' => '203.0.113.9']);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            $http->terminate($request, $response);

            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame(['tenant' => $tenant, 'redirect_prefix' => '/go'], $body, 'request ' . $i . ' sees only its own data');

            if ($i === 100) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();

        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over ' . self::REQUESTS . ' requests');
    }

    public function testARequestWithoutTenantAfterOneWithTenantSeesNone(): void
    {
        $kernel = $this->bootKernel(['redirect' => ['path_prefix' => '/go']]);
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);

        $http->handle(Request::create('/echo', 'GET', server: ['HTTP_X_TEST_TENANT' => 'tenant-a']), HttpKernelInterface::MAIN_REQUEST, false);
        $response = $http->handle(Request::create('/echo'), HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(['tenant' => null, 'redirect_prefix' => '/go'], json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR));
    }

    /**
     * `target.allow_own_host` in a kept-alive worker: the own origin comes from configuration only, so a request with
     * a spoofed Host (a private address the inner guard refuses) never turns that host into an own one, during the
     * request or after it, and the verdicts outside any request (CLI recheck, Messenger) are the same.
     */
    public function testAllowOwnHostNeverTrustsTheRequestHostAcrossKeptAliveRequests(): void
    {
        $kernel    = $this->bootKernel(['public_base_url' => 'http://localhost:8000', 'target' => ['allow_own_host' => true]]);
        $container = $kernel->getContainer();
        $http      = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        $guard = $container->get(TargetGuardInterface::class);
        self::assertInstanceOf(OwnHostGuard::class, $guard);

        $verdicts = static function () use ($guard): array {
            return [
                'own'      => $guard->check(TargetUrl::fromString('http://localhost:8000/mail/open'), GuardStage::Redirect)->refusal,
                'loop'     => $guard->check(TargetUrl::fromString('http://localhost:8000/s/abc'), GuardStage::Redirect)->refusal,
                'spoofed'  => $guard->check(TargetUrl::fromString('http://10.0.0.5/admin'), GuardStage::Redirect)->refusal,
                'loopback' => $guard->check(TargetUrl::fromString('http://localhost:9000/'), GuardStage::Redirect)->refusal,
            ];
        };
        $expected = ['own' => null, 'loop' => TargetRefusal::SelfLoop, 'spoofed' => TargetRefusal::PrivateHost, 'loopback' => TargetRefusal::PrivateHost];
        self::assertSame($expected, $verdicts(), 'no request (CLI / Messenger)');

        $requests = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        for ($i = 0; $i < 50; ++$i) {
            $request = Request::create('http://10.0.0.5:9000/echo');
            $requests->push($request);
            self::assertSame($expected, $verdicts(), 'inside request ' . $i);
            $requests->pop();
            $response = $http->handle(Request::create('http://10.0.0.5/echo'), HttpKernelInterface::MAIN_REQUEST, false);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($expected, $verdicts(), 'after request ' . $i);
        }
        self::assertSame($guard, $container->get(TargetGuardInterface::class), 'one shared stateless instance');
    }
}

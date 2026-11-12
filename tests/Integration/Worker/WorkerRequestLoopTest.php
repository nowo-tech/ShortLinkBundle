<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Worker;

use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
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
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Nowo\ShortLinkBundle\Domain\Exception\AccessDeniedException;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\TenantLockInterface;
use Nowo\ShortLinkBundle\Infrastructure\Lock\SymfonyTenantLock;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function dirname;

use const PHP_BINARY;

/**
 * Standard Symfony host: attribute mapping, default entity manager, single tenant `default`, ROLE_ADMIN through
 * SecurityBundle, symfony/lock for exact quotas.
 */
final class StandardHostTest extends ShortLinkFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->tenant      = TenantKey::default();
        $this->otherTenant = TenantKey::fromString('other');
        parent::setUp();
    }

    public function testTheQuotaLockUsesSymfonyLock(): void
    {
        self::assertInstanceOf(SymfonyTenantLock::class, $this->container()->get(TenantLockInterface::class));
    }

    public function testTheQuotaIsExactUnderParallelCreates(): void
    {
        $url = TestKernel::defaultDatabaseUrl();
        if (str_starts_with($url, 'sqlite')) {
            self::markTestSkipped('Parallel writers need a server database (MySQL / MariaDB / PostgreSQL job).');
        }
        $start = TestKernel::baseDir() . '/parallel-start';
        @unlink($start);
        $children = [];
        for ($i = 0; $i < 10; ++$i) {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/bin/concurrent-create.php', $url, 'parallel_' . $i, $start], timeout: 120);
            $process->start();
            $children[] = $process;
        }
        touch($start);
        $results = [];
        foreach ($children as $process) {
            $process->wait();
            $results[] = $process->getOutput() . $process->getErrorOutput();
        }
        sort($results);

        self::assertSame(['created', 'created', 'created', 'quota', 'quota', 'quota', 'quota', 'quota', 'quota', 'quota'], $results);
        self::assertSame(3, $this->store()->countLive($this->tenant, LinkKind::manual()));
    }

    public function testAUserWithoutTheRoleIsDenied(): void
    {
        $this->tokens()->setToken(new UsernamePasswordToken(new InMemoryUser('viewer', null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        $this->expectException(AccessDeniedException::class);
        $this->manager()->list($this->tenant, new ShortLinkListQuery());
    }

    protected function host(): Host
    {
        return Host::standard();
    }

    protected function grant(array $tenants, ?string $actor = 'actor-1'): void
    {
        $this->tokens()->setToken(new UsernamePasswordToken(new InMemoryUser((string) $actor, null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
    }

    protected function revoke(): void
    {
        $this->tokens()->setToken(null);
    }

    protected function setLimit(int $limit): void
    {
        $this->boot(['quota' => ['default_limit' => $limit]]);
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }
}

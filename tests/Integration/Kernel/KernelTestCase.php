<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Kernel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function count;
use function preg_replace;

/**
 * Boots a fresh {@see TestKernel} per call (own cache directory), shut down and removed after each test.
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(TestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config `nowo_short_link` configuration
     */
    protected function bootKernel(array $config = [], ?string $databaseUrl = null): TestKernel
    {
        $id              = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel          = new TestKernel($id, $config, $databaseUrl);
        $this->kernels[] = $kernel;
        $kernel->boot();

        return $kernel;
    }
}

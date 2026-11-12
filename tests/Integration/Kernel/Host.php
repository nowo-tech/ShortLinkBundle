<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkCreatedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkDeletedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkStateChangedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkTargetRefusedEvent;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkUpdatedEvent;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestAccess;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestQuota;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestTenantContext;
use Nowo\ShortLinkBundle\Tests\Fixtures\RecordingListener;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function array_replace_recursive;
use function assert;

/**
 * The two host kinds of the functional suite (REQ-ARCH-001): a standard Symfony app (attribute mapping, default entity
 * manager, single tenant, role-based access through SecurityBundle, symfony/lock) and a DDD app (XML mapping on a
 * dedicated entity manager `ddd` with its own connection, another table prefix, tenant context / access / quota ports
 * implemented by the host, no lock).
 */
final readonly class Host
{
    public const STANDARD = 'standard';

    public const DDD = 'ddd';

    /** Fixed start time of the test clock. */
    public const NOW = '2026-11-12 10:00:00';

    /** The five domain events the recording listener follows. */
    public const EVENTS = [ShortLinkCreatedEvent::class, ShortLinkUpdatedEvent::class, ShortLinkStateChangedEvent::class, ShortLinkDeletedEvent::class, ShortLinkTargetRefusedEvent::class];

    private function __construct(public string $kind)
    {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    public static function ddd(): self
    {
        return new self(self::DDD);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public function bundleConfig(array $override = []): array
    {
        $common = [
            'public_base_url' => 'https://links.example',
            'target'          => ['save_resolve_dns' => false, 'own_hosts' => ['links.example'], 'denylist' => ['hosts' => ['.bad.example']]],
        ];
        $kind = $this->kind === self::STANDARD
            ? ['doctrine' => ['mapping' => 'attribute'], 'quota' => ['default_limit' => 5]]
            : [
                'doctrine' => ['entity_manager' => 'ddd', 'table_prefix' => 'ddd_', 'mapping' => 'xml'],
                'tenancy'  => ['context' => TestTenantContext::class],
                'access'   => ['provider' => TestAccess::class],
                'quota'    => ['provider' => TestQuota::class],
            ];

        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($common, $kind, $override);

        return $merged;
    }

    /**
     * @return Closure(ContainerConfigurator, string): void
     */
    public function configure(): Closure
    {
        $kind = $this->kind;

        return static function (ContainerConfigurator $container, string $url) use ($kind): void {
            if ($kind === self::STANDARD) {
                $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => []]);
                $container->extension('framework', ['lock' => 'flock']);
                $container->extension('security', [
                    'providers' => ['users' => ['memory' => ['users' => ['admin' => ['roles' => ['ROLE_ADMIN']]]]]],
                    'firewalls' => ['main' => ['lazy' => true, 'provider' => 'users']],
                ]);
            } else {
                $container->extension('doctrine', [
                    'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => $url], 'ddd' => ['url' => $url]]],
                    'orm'  => ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['connection' => 'default'], 'ddd' => ['connection' => 'ddd']]],
                ]);
                $container->services()->set(TestTenantContext::class)->public();
                $container->services()->set(TestAccess::class)->public();
                $container->services()->set(TestQuota::class)->public();
            }
            $container->services()->set('clock', MockClock::class)->args([self::NOW, 'UTC'])->public();
            $listener = $container->services()->set(RecordingListener::class)->public();
            foreach (self::EVENTS as $event) {
                $listener->tag('kernel.event_listener', ['event' => $event, 'method' => 'onEvent']);
            }
        };
    }

    public function entityManagerName(): string
    {
        return $this->kind === self::STANDARD ? 'default' : 'ddd';
    }

    public function tablePrefix(): string
    {
        return $this->kind === self::STANDARD ? '' : 'ddd_';
    }

    public function entityManager(TestKernel $kernel): EntityManagerInterface
    {
        $doctrine = $kernel->getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $em = $doctrine->getManager($this->entityManagerName());
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    public function connection(TestKernel $kernel): Connection
    {
        return $this->entityManager($kernel)->getConnection();
    }

    /**
     * Drops and creates the bundle tables (entity + click tables) on the configured database.
     */
    public function resetSchema(TestKernel $kernel): void
    {
        $em         = $this->entityManager($kernel);
        $connection = $em->getConnection();
        $manager    = $connection->createSchemaManager();
        $prefix     = $this->tablePrefix();
        foreach ([ClickTablesSchemaListener::CLICK_TABLE, ClickTablesSchemaListener::DAILY_TABLE, ClickTablesSchemaListener::ROLLUP_DAY_TABLE, 'nowo_short_link'] as $table) {
            if ($manager->tablesExist([$prefix . $table])) {
                $connection->executeStatement('DROP TABLE ' . $prefix . $table);
            }
        }
        $tool = new SchemaTool($em);
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * What `doctrine:schema:update` would still run for this entity manager.
     *
     * @return list<string>
     */
    public function updateSchemaSql(TestKernel $kernel): array
    {
        $em = $this->entityManager($kernel);

        return (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata());
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Unit\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use LogicException;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\BinaryCollation;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\Entity\ShortLinkRecord;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ShortLinkMetadataListener;
use Nowo\ShortLinkBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\ShortLinkBundle\Infrastructure\Routing\ShortUrlBuilder;
use Nowo\ShortLinkBundle\Infrastructure\Security\RoleBasedAccess;
use Nowo\ShortLinkBundle\Infrastructure\Uid\UuidV7IdGenerator;
use Nowo\ShortLinkBundle\Tests\Fixtures\MemoryLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function dirname;
use function str_repeat;

/**
 * Infrastructure defaults outside a kernel.
 */
final class InfrastructureTest extends TestCase
{
    public function testIdentifierColumnsGetANoPadBinaryCollationWhereTheServerHasOne(): void
    {
        self::assertSame('utf8mb4_0900_bin', BinaryCollation::for(new MySQL80Platform()));
        self::assertSame('utf8mb4_0900_bin', BinaryCollation::for(new MySQL84Platform()));
        self::assertSame('utf8mb4_nopad_bin', BinaryCollation::for(new MariaDBPlatform()));
        self::assertSame('utf8mb4_bin', BinaryCollation::for(new MySQLPlatform()), 'MySQL 5.7 has no NO PAD binary collation');
        self::assertNull(BinaryCollation::for(new PostgreSQLPlatform()));
    }

    public function testTheMetadataListenerPrefixesAndSetsBinaryCollationsOnMySql(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0']);
        $metadata   = $this->metadata();
        (new ShortLinkMetadataListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->entityManager(static fn (): Connection => $connection)));

        self::assertSame('app_nowo_short_link', $metadata->getTableName());
        self::assertArrayHasKey('app_uniq_short_link_code', $metadata->table['uniqueConstraints'] ?? []);
        self::assertArrayHasKey('app_idx_short_link_tenant_list', $metadata->table['indexes'] ?? []);
        foreach (['id', 'tenantKey', 'code', 'sourceRef', 'createdBy', 'updatedBy'] as $field) {
            self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'], $metadata->fieldMappings[$field]->options, $field);
        }
        self::assertNull($metadata->fieldMappings['title']->options);
    }

    public function testTheMetadataListenerIgnoresOtherClassesAndNeedsThePlatform(): void
    {
        $other = new ClassMetadata(stdClass::class);
        (new ShortLinkMetadataListener())->loadClassMetadata(new LoadClassMetadataEventArgs($other, $this->entityManager(static fn (): never => throw new RuntimeException('unused'))));
        self::assertSame(stdClass::class, $other->getName(), 'other classes are left alone (the connection is never asked)');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('server_version');
        (new ShortLinkMetadataListener())->loadClassMetadata(new LoadClassMetadataEventArgs($this->metadata(), $this->entityManager(static fn (): never => throw new RuntimeException('down'))));
    }

    public function testTheClickTablesGetBinaryCollationsOnMySqlAndOnlyOnTheBundleEntityManager(): void
    {
        $schema = new Schema();
        (new ClickTablesSchemaListener('p_'))->addTables($schema, 'utf8mb4_bin');
        self::assertSame('utf8mb4_bin', $schema->getTable('p_nowo_short_link_click')->getColumn('link_id')->getPlatformOption('collation'));
        self::assertTrue($schema->getTable('p_nowo_short_link_click_daily')->hasIndex('p_idx_short_link_daily_tenant'));
        self::assertTrue($schema->hasTable('p_nowo_short_link_rollup_day'));

        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(true);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($factory);
        $other = new Schema();
        (new ClickTablesSchemaListener())->postGenerateSchema(new GenerateSchemaEventArgs($em, $other));
        self::assertSame([], $other->getTables());
    }

    public function testShortUrlsUsePublicBaseUrlOrTheRequestContext(): void
    {
        $code = ShortCode::random('aB3xY9k');
        self::assertSame('https://beacon.example/s/aB3xY9k', (new ShortUrlBuilder('https://beacon.example/', '/s'))->build($code));
        self::assertSame('https://ctx.example/app/go/aB3xY9k', (new ShortUrlBuilder('', '/go', new RequestContext('/app/', 'GET', 'ctx.example', 'https')))->build($code));
        self::assertSame('http://ctx.example:8231/s/aB3xY9k', (new ShortUrlBuilder(null, '/s', new RequestContext('', 'GET', 'ctx.example', 'http', 8231)))->build($code));
        self::assertSame('https://ctx.example:8443/s/aB3xY9k', (new ShortUrlBuilder(null, '/s', new RequestContext('', 'GET', 'ctx.example', 'https', 80, 8443)))->build($code));

        foreach ([new ShortUrlBuilder('ftp://x', '/s'), new ShortUrlBuilder(null, '/s'), new ShortUrlBuilder(null, '/s', new RequestContext(host: ''))] as $builder) {
            try {
                $builder->build($code);
                self::fail('No base URL.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRoleBasedAccess(): void
    {
        self::assertFalse((new RoleBasedAccess(null, null))->isGranted(ShortLinkPermission::View, TenantKey::default()));
        self::assertNull((new RoleBasedAccess(null, null))->currentActor());

        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $role === 'ROLE_VIEW');
        $tokens = new TokenStorage();
        $access = new RoleBasedAccess($checker, $tokens, 'ROLE_VIEW', 'ROLE_MANAGE');
        self::assertTrue($access->isGranted(ShortLinkPermission::View, TenantKey::default()));
        self::assertFalse($access->isGranted(ShortLinkPermission::Manage, TenantKey::default()));
        self::assertNull($access->currentActor());
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null), 'main'));
        self::assertSame('alice', $access->currentActor()?->value);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser(str_repeat('a', 129), null), 'main'));
        self::assertNull($access->currentActor());

        $throwing = $this->createStub(AuthorizationCheckerInterface::class);
        $throwing->method('isGranted')->willThrowException(new AuthenticationCredentialsNotFoundException());
        self::assertFalse((new RoleBasedAccess($throwing, null))->isGranted(ShortLinkPermission::View, TenantKey::default()));
    }

    public function testIdsAreLowercaseUuidV7(): void
    {
        $id = (new UuidV7IdGenerator())->generate();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id->value);
    }

    public function testAfterCommitFailuresAreLoggedNotThrown(): void
    {
        $logger = new MemoryLogger();
        (new AfterCommitQueue($logger))->run(static fn (): never => throw new RuntimeException('listener down'));
        self::assertCount(1, $logger->records);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['class']);

        $queue      = new AfterCommitQueue($logger);
        $connection = new stdClass();
        $queue->hold($connection, static function (): void {
        });
        self::assertSame(1, $queue->heldCount());
        $queue->reset();
        self::assertSame(0, $queue->heldCount());
        self::assertCount(2, $logger->records, 'dropped held work is logged');
    }

    public function testTheAttributeTwinHasTheSameColumns(): void
    {
        $record = new ShortLinkRecord();
        self::assertSame('manual', $record->kind);
        self::assertTrue($record->active);
    }

    /**
     * @return ClassMetadata<ShortLinkRecord>
     */
    private function metadata(): ClassMetadata
    {
        $metadata = new ClassMetadata(ShortLinkRecord::class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        (new SimplifiedXmlDriver([dirname(__DIR__, 3) . '/src/Resources/config/doctrine-mapping' => 'Nowo\ShortLinkBundle\Infrastructure\Doctrine\Entity']))->loadMetadataForClass(ShortLinkRecord::class, $metadata);

        return $metadata;
    }

    private function entityManager(Closure $connection): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturnCallback($connection);

        return $em;
    }
}

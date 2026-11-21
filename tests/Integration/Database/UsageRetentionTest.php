<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use LogicException;
use Nowo\ShortLinkBundle\Application\Store\ClickStoreInterface;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkCountQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\SourceRetentionQuery;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkRetentionInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkUsageReaderInterface;
use Nowo\ShortLinkBundle\Tests\Fixtures\ConcurrencyProbe;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestAccess;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_map;
use function is_scalar;
use function memory_get_usage;
use function range;
use function sprintf;
use function str_starts_with;

/**
 * The usage and retention ports (0.7.0) on the standard host; the DDD subclass runs the same suite on the id host
 * (XML mapping on a dedicated entity manager, table prefix `ddd_`, host ports). Lock races need a server database.
 */
class UsageRetentionTest extends KernelTestCase
{
    private TestKernel $kernel;

    private TenantKey $tenantA;

    private TenantKey $tenantB;

    protected function host(): Host
    {
        return Host::standard();
    }

    protected function setUp(): void
    {
        $this->tenantA = TenantKey::fromString('0190a6d0-aaaa-7000-8000-00000000000a');
        $this->tenantB = TenantKey::fromString('0190a6d0-bbbb-7000-8000-00000000000b');
        $host          = $this->host();
        $this->kernel  = $this->bootKernel($host->bundleConfig(), TestKernel::defaultDatabaseUrl(), $host->configure(), $host->kind === Host::STANDARD);
        $host->resetSchema($this->kernel);
        if ($host->kind === Host::STANDARD) {
            $tokens = $this->container()->get('security.token_storage');
            self::assertInstanceOf(TokenStorageInterface::class, $tokens);
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('actor-1', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        } else {
            $access = $this->container()->get(TestAccess::class);
            self::assertInstanceOf(TestAccess::class, $access);
            $access->granted = [$this->tenantA->value => [ShortLinkPermission::View, ShortLinkPermission::Manage], $this->tenantB->value => [ShortLinkPermission::View, ShortLinkPermission::Manage]];
            $access->actor   = ActorRef::fromString('actor-1');
        }
    }

    public function testCountsLinksByTenantKindAndState(): void
    {
        $this->create($this->tenantA, 'https://example.com/active');
        $this->create($this->tenantA, 'https://example.com/disabled', active: false);
        $this->create($this->tenantA, 'https://example.com/expires', expiresAt: new DateTimeImmutable('2026-11-13 10:00:00', new DateTimeZone('UTC')));
        $deleted = $this->create($this->tenantA, 'https://example.com/deleted');
        $this->manager()->delete($this->tenantA, $deleted->id);
        $this->manager()->createMany($this->tenantA, [new CreateShortLink('https://example.com/mail', kind: LinkKind::email(), source: new LinkSource('send-1', null, 0), system: true)]);
        $this->create($this->tenantB, 'https://example.com/b');
        $this->clock()->modify('+2 days');

        $unknown = TenantKey::fromString('org-without-links');
        $all     = $this->usage()->count(new ShortLinkCountQuery([$this->tenantA, $this->tenantB, $unknown, $this->tenantA], LinkKind::manual()));
        self::assertSame([$this->tenantA->value => 3, $this->tenantB->value => 1, $unknown->value => 0], $all->byTenant, 'not deleted: active, disabled and expired; each tenant once');
        self::assertSame(4, $all->total);
        self::assertSame(3, $all->forTenant($this->tenantA));
        self::assertSame(0, $all->forTenant(TenantKey::fromString('not-asked')));

        $states = [];
        foreach ([ShortLinkState::Active, ShortLinkState::Disabled, ShortLinkState::Expired, ShortLinkState::Deleted] as $state) {
            $states[$state->value] = $this->usage()->count(new ShortLinkCountQuery([$this->tenantA], LinkKind::manual(), $state))->total;
        }
        self::assertSame(['active' => 1, 'disabled' => 1, 'expired' => 1, 'deleted' => 1], $states);
        self::assertSame(1, $this->usage()->count(new ShortLinkCountQuery([$this->tenantA, $this->tenantB], LinkKind::email()))->total, 'e-mail links never count as manual ones');
        self::assertSame(0, $this->usage()->count(new ShortLinkCountQuery([], LinkKind::manual()))->total);

        $many = array_map(static fn (int $i): TenantKey => TenantKey::fromString(sprintf('org-%04d', $i)), range(1, 1200));
        $wide = $this->usage()->count(new ShortLinkCountQuery([...$many, $this->tenantB], LinkKind::manual()));
        self::assertCount(1201, $wide->byTenant, 'more tenants than one grouped query takes');
        self::assertSame(1, $wide->total);

        try {
            new ShortLinkCountQuery(array_map(static fn (int $i): TenantKey => TenantKey::fromString('t-' . $i), range(0, ShortLinkCountQuery::MAX_TENANTS)), LinkKind::manual());
            self::fail('Too many tenants.');
        } catch (InvalidShortLinkArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * 0.7.1 (audit YD2-L1): a decimal-integer tenant key ("123") is an int key of `byTenant` (PHP), but `perTenant()`
     * hands it back as the exact string, so a strict_types host can pass it on to a string parameter.
     */
    public function testANumericTenantKeyStaysAStringThroughPerTenant(): void
    {
        $numeric = TenantKey::fromString('123');
        $padded  = TenantKey::fromString('0123');
        if ($this->host()->kind !== Host::STANDARD) {
            $access = $this->container()->get(TestAccess::class);
            self::assertInstanceOf(TestAccess::class, $access);
            $access->granted[$numeric->value] = [ShortLinkPermission::View, ShortLinkPermission::Manage];
            $access->granted[$padded->value]  = [ShortLinkPermission::View, ShortLinkPermission::Manage];
        }
        $this->create($numeric, 'https://example.com/n1');
        $this->create($numeric, 'https://example.com/n2');
        $this->create($padded, 'https://example.com/p1');

        $counts = $this->usage()->count(new ShortLinkCountQuery([$numeric, $padded, $numeric, $this->tenantA], LinkKind::manual()));
        $seen   = [];
        foreach ($counts->perTenant() as $tenant => $links) {
            $seen[TenantKey::fromString($tenant)->value . '|'] = $links;
        }
        self::assertSame(['123|' => 2, '0123|' => 1, $this->tenantA->value . '|' => 0], $seen);
        self::assertSame(3, $counts->total);
        self::assertSame(2, $counts->forTenant($numeric));
        self::assertSame(1, $counts->forTenant($padded));
    }

    public function testOneKeptAliveKernelCountsManyTenantsWithoutBleedOrGrowth(): void
    {
        $this->create($this->tenantA, 'https://example.com/a');
        $this->create($this->tenantA, 'https://example.com/a2');
        $usage = $this->usage();
        $query = [new ShortLinkCountQuery([$this->tenantA], LinkKind::manual()), new ShortLinkCountQuery([$this->tenantB], LinkKind::manual())];
        for ($i = 0; $i < 50; ++$i) {
            $usage->count($query[$i % 2]);
        }
        $memory = memory_get_usage();
        for ($i = 0; $i < 500; ++$i) {
            self::assertSame($i % 2 === 0 ? 2 : 0, $usage->count($query[$i % 2])->total);
        }
        self::assertLessThan(512 * 1024, memory_get_usage() - $memory, 'no state kept between calls');
    }

    public function testSourcesOlderThanABoundArePagedInRefOrder(): void
    {
        $this->clock()->modify('-100 days');
        foreach (['send-e', 'send-a', 'send-c', 'send-b', 'send-d'] as $ref) {
            $this->send($this->tenantA, $ref);
        }
        $this->send($this->tenantB, 'send-x');
        $this->manager()->createMany($this->tenantA, [new CreateShortLink('https://example.com/manual', kind: LinkKind::manual(), source: new LinkSource('send-m', null, 0), system: true)]);
        $old = $this->send($this->tenantA, 'send-del');
        $this->manager()->delete($this->tenantA, $old[0]->id);
        $this->clock()->modify('+100 days');
        $this->send($this->tenantA, 'send-new');
        $this->manager()->createMany($this->tenantA, [new CreateShortLink('https://example.com/late', kind: LinkKind::email(), source: new LinkSource('send-a', 'Late', 7), system: true)]);

        $query = new SourceRetentionQuery($this->tenantA, LinkKind::email(), $this->retentionBound(), 3);
        $pages = [];
        $after = null;
        do {
            $page    = $this->retention()->sourcesCreatedBefore($query, $after);
            $pages[] = $page->sourceRefs;
            $after   = $page->next;
        } while ($after !== null);
        self::assertSame([['send-a', 'send-b', 'send-c'], ['send-d', 'send-del', 'send-e']], $pages, 'deleted links still carry their source; the newer link of send-a does not hide its old ones');
        self::assertSame(['send-x'], $this->retention()->sourcesCreatedBefore(new SourceRetentionQuery($this->tenantB, LinkKind::email(), $this->retentionBound()))->sourceRefs);
        self::assertSame([], $this->retention()->sourcesCreatedBefore(new SourceRetentionQuery($this->tenantA, LinkKind::email(), new DateTimeImmutable('2026-01-01')))->sourceRefs);

        foreach (['', 'send a', "send-a'"] as $cursor) {
            try {
                $this->retention()->sourcesCreatedBefore($query, $cursor);
                self::fail('Refused cursor.');
            } catch (InvalidShortLinkArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([0, SourceRetentionQuery::MAX_BATCH_SIZE + 1] as $size) {
            try {
                new SourceRetentionQuery($this->tenantA, LinkKind::email(), $this->retentionBound(), $size);
                self::fail('Refused batch size.');
            } catch (InvalidShortLinkArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testErasingSourcesInBatchesIsIdempotent(): void
    {
        $this->clock()->modify('-100 days');
        $views = [];
        foreach (['send-a', 'send-b', 'send-c', 'send-d'] as $ref) {
            $views[$ref] = $this->send($this->tenantA, $ref);
        }
        $other = $this->send($this->tenantB, 'send-a');
        $this->clock()->modify('+100 days');
        $fresh = $this->send($this->tenantA, 'send-new');
        $late  = $this->manager()->createMany($this->tenantA, [new CreateShortLink('https://example.com/late', kind: LinkKind::email(), source: new LinkSource('send-b', 'Late', 7), system: true)]);
        foreach ([$views['send-a'][0], $views['send-d'][1], $other[0], $fresh[0]] as $view) {
            $this->click($view);
        }
        $query = new SourceRetentionQuery($this->tenantA, LinkKind::email(), $this->retentionBound(), 2);

        $first = $this->retention()->eraseSourcesCreatedBefore($query, 1);
        self::assertSame([2, 1, false], [$first->sources, $first->batches, $first->complete], 'stopped at maxBatches with sources left');
        self::assertSame(6, $first->rows, 'five blanked links (the newer link of send-b too) + one click');
        $rest = $this->retention()->eraseSourcesCreatedBefore($query);
        self::assertSame([2, 1, true], [$rest->sources, $rest->batches, $rest->complete], 'a full last batch, then an empty listing');
        self::assertSame(5, $rest->rows);
        $again = $this->retention()->eraseSourcesCreatedBefore($query);
        self::assertSame([0, 0, 0, true], [$again->sources, $again->rows, $again->batches, $again->complete], 'a re-run erases nothing');

        foreach ($views as $pair) {
            foreach ($pair as $view) {
                $row = $this->linkRow($view);
                self::assertSame(['', null, null], [$row['target_url'], $row['source_ref'], $row['created_by']], 'blanked like eraseSource()');
                self::assertNotNull($row['deleted_at']);
            }
        }
        self::assertNull($this->linkRow($late[0])['source_ref'], 'a newer link of an old source goes with it, like eraseSource()');
        self::assertSame('send-new', $this->linkRow($fresh[0])['source_ref'], 'a newer source is kept');
        self::assertSame('send-a', $this->linkRow($other[0])['source_ref'], 'another tenant is never touched');
        self::assertSame(2, $this->clickCount(), 'the clicks of the kept links remain');
        self::assertSame([], $this->retention()->sourcesCreatedBefore($query)->sourceRefs);

        $this->clock()->modify('-100 days');
        $this->send($this->tenantA, 'send-z');
        $this->clock()->modify('+100 days');
        $last = $this->retention()->eraseSourcesCreatedBefore($query);
        self::assertSame([1, 1, true], [$last->sources, $last->batches, $last->complete], 'a short batch is the last one');
    }

    public function testErasingRefusesAnOpenTransactionAndBadArguments(): void
    {
        $query = new SourceRetentionQuery($this->tenantA, LinkKind::email(), $this->retentionBound());
        try {
            $this->retention()->eraseSourcesCreatedBefore($query, 0);
            self::fail('At least one batch.');
        } catch (InvalidShortLinkArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(LogicException::class);
        $this->connection()->transactional(fn (): mixed => $this->retention()->eraseSourcesCreatedBefore($query));
    }

    /**
     * REQ-RUNTIME-003 rule 6: another connection holds a link of the first batch; the erasure waits (1 s session lock
     * wait), gives up with a transient failure whose code is CONFLICT_001, nothing erased. With a committed batch before
     * the conflict the failure is a committed ConcurrencyConflict (CONFLICT_002); a retry after the release finishes.
     */
    public function testALockConflictAnswersConflict001BeforeAnyBatchAndConflict002After(): void
    {
        $this->skipOnSqlite();
        $this->clock()->modify('-100 days');
        $sendA = $this->send($this->tenantA, 'send-a');
        $sendB = $this->send($this->tenantA, 'send-b');
        $this->clock()->modify('+100 days');
        $other = $this->secondConnection();
        ConcurrencyProbe::shortLockWaits($this->connection());
        $query = new SourceRetentionQuery($this->tenantA, LinkKind::email(), $this->retentionBound(), 1);

        $other->beginTransaction();
        $this->lockRow($other, $sendA[0]);
        try {
            $this->retention()->eraseSourcesCreatedBefore($query);
            self::fail('The first batch waits for the other connection.');
        } catch (TransientStorageFailure $failure) {
            self::assertNotInstanceOf(ConcurrencyConflict::class, $failure);
            self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure));
        } finally {
            $other->rollBack();
        }
        self::assertSame('send-a', $this->linkRow($sendA[0])['source_ref'], 'nothing erased');
        self::assertNull($this->linkRow($sendA[0])['deleted_at']);

        $other->beginTransaction();
        $this->lockRow($other, $sendB[0]);
        try {
            $this->retention()->eraseSourcesCreatedBefore($query);
            self::fail('The second batch waits for the other connection.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertTrue($conflict->committed);
            self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($conflict));
        } finally {
            $other->rollBack();
        }
        self::assertNull($this->linkRow($sendA[0])['source_ref'], 'the first batch stays committed');
        self::assertSame('send-b', $this->linkRow($sendB[0])['source_ref']);

        $retry = $this->retention()->eraseSourcesCreatedBefore($query);
        self::assertSame([1, true], [$retry->sources, $retry->complete], 'the retry erases only what is left');
        self::assertNull($this->linkRow($sendB[0])['source_ref']);
    }

    private function retentionBound(): DateTimeImmutable
    {
        return $this->clock()->now()->modify('-90 days');
    }

    /**
     * @return list<ShortLinkView>
     */
    private function send(TenantKey $tenant, string $ref): array
    {
        return $this->manager()->createMany($tenant, [
            new CreateShortLink('https://example.com/logo', kind: LinkKind::email(), source: new LinkSource($ref, 'Logo', 0), system: true),
            new CreateShortLink('https://example.com/button', kind: LinkKind::email(), source: new LinkSource($ref, 'Button', 1), system: true),
        ]);
    }

    private function create(TenantKey $tenant, string $target, bool $active = true, ?DateTimeImmutable $expiresAt = null): ShortLinkView
    {
        return $this->manager()->create($tenant, new CreateShortLink($target, expiresAt: $expiresAt, active: $active, system: true));
    }

    private function click(ShortLinkView $view): void
    {
        $clicks = $this->container()->get(ClickStoreInterface::class);
        self::assertInstanceOf(ClickStoreInterface::class, $clicks);
        $clicks->insert($view->id, $view->tenant, $this->clock()->now(), null, null, null, null, false);
    }

    private function clickCount(): int
    {
        return (int) $this->text($this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->host()->tablePrefix() . 'nowo_short_link_click'));
    }

    /**
     * @return array<string, mixed>
     */
    private function linkRow(ShortLinkView $view): array
    {
        $row = $this->connection()->fetchAssociative(sprintf('SELECT target_url, source_ref, created_by, deleted_at FROM %snowo_short_link WHERE id = ?', $this->host()->tablePrefix()), [$view->id->value]);
        self::assertIsArray($row);

        return $row;
    }

    private function lockRow(Connection $connection, ShortLinkView $view): void
    {
        $connection->fetchOne(sprintf('SELECT id FROM %snowo_short_link WHERE id = ? FOR UPDATE', $this->host()->tablePrefix()), [$view->id->value]);
    }

    private function secondConnection(): Connection
    {
        $host = $this->host();

        return $host->connection($this->bootKernel($host->bundleConfig(), TestKernel::defaultDatabaseUrl(), $host->configure(), $host->kind === Host::STANDARD));
    }

    private function skipOnSqlite(): void
    {
        if (str_starts_with(TestKernel::defaultDatabaseUrl(), 'sqlite')) {
            self::markTestSkipped('Two connections and real locks need a server database (MySQL / MariaDB / PostgreSQL job).');
        }
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function manager(): ShortLinkManagerInterface
    {
        $service = $this->container()->get(ShortLinkManagerInterface::class);
        self::assertInstanceOf(ShortLinkManagerInterface::class, $service);

        return $service;
    }

    private function usage(): ShortLinkUsageReaderInterface
    {
        $service = $this->container()->get(ShortLinkUsageReaderInterface::class);
        self::assertInstanceOf(ShortLinkUsageReaderInterface::class, $service);

        return $service;
    }

    private function retention(): ShortLinkRetentionInterface
    {
        $service = $this->container()->get(ShortLinkRetentionInterface::class);
        self::assertInstanceOf(ShortLinkRetentionInterface::class, $service);

        return $service;
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
}

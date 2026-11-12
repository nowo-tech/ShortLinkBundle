<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ShortLinkBundle\Application\Store\ClickStoreInterface;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;

/**
 * DBAL writer of the raw click table (contract §5.2): one INSERT on `doctrine.connection` (or the bundle entity
 * manager's connection), never inside the host's unit of work. Stateless (worker mode).
 *
 * @internal
 */
final readonly class DbalClickStore implements ClickStoreInterface
{
    private string $table;

    /**
     * @param string|null $connection `doctrine.connection`; null = the entity manager's connection
     * @param string|null $entityManager `doctrine.entity_manager`; null = the default entity manager
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $connection = null,
        private ?string $entityManager = null,
        string $tablePrefix = '',
    ) {
        $this->table = $tablePrefix . ClickTablesSchemaListener::CLICK_TABLE;
    }

    /**
     * {@inheritDoc}
     */
    public function insert(ShortLinkId $linkId, TenantKey $tenant, DateTimeImmutable $clickedAt, ?string $ipPrefix, ?string $uaFamily, ?string $referrerHost, ?string $country, bool $machine): void
    {
        $this->connection()->insert($this->table, [
            'link_id'       => $linkId->value,
            'tenant_key'    => $tenant->value,
            'clicked_at'    => $clickedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'ip_prefix'     => $ipPrefix,
            'ua_family'     => $uaFamily,
            'referrer_host' => $referrerHost,
            'country'       => $country,
            'machine'       => $machine,
        ], ['machine' => Types::BOOLEAN]);
    }

    private function connection(): Connection
    {
        if ($this->connection !== null) {
            $connection = $this->registry->getConnection($this->connection);
            if (!$connection instanceof Connection) {
                throw new LogicException('nowo_short_link.doctrine.connection must name a Doctrine DBAL connection.');
            }

            return $connection;
        }
        $manager = $this->registry->getManager($this->entityManager);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('nowo_short_link.doctrine.entity_manager must name a Doctrine ORM entity manager.');
        }

        return $manager->getConnection();
    }
}

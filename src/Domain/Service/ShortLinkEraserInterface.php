<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Service;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * GDPR and tenant lifecycle (contract §10). Erased links become tombstones: their codes stay taken until the
 * quarantine ends (the purge command then deletes them), so printed or mailed links cannot be taken over.
 */
interface ShortLinkEraserInterface
{
    /**
     * Deletes every click and rollup row of the tenant and turns its links into blanked tombstones (tenant `~erased`,
     * no target, title, source, metadata or actors). Returns the number of rows deleted or blanked.
     */
    public function eraseTenant(TenantKey $tenant): int;

    /**
     * Deletes the click and rollup rows of the links of one source (e.g. an e-mail send) and tombstones those links.
     * Returns the number of rows deleted or tombstoned.
     */
    public function eraseSource(TenantKey $tenant, LinkKind $kind, string $sourceRef): int;

    /**
     * Removes a person from `created_by` / `updated_by` everywhere. Returns the number of links changed.
     */
    public function anonymiseActor(ActorRef $actor): int;

    /**
     * Personal-data export of one source: one row per stored click.
     *
     * @return list<array{code: string, label: string|null, position: int|null, clicked_at: string, ip_prefix: string|null, ua_family: string|null, referrer_host: string|null, country: string|null, machine: bool}>
     */
    public function exportSource(TenantKey $tenant, LinkKind $kind, string $sourceRef): array;
}

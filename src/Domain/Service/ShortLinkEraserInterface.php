<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Service;

use Nowo\ShortLinkBundle\Domain\Event\ShortLinkDeletedEvent;
use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;

/**
 * GDPR and tenant lifecycle (contract §10). Erased links become blanked tombstones (no target, title, source, metadata
 * or actors): their codes stay taken until the quarantine ends (the purge command then deletes them), so printed or
 * mailed links cannot be taken over. The eraser dispatches no {@see ShortLinkDeletedEvent}:
 * the host that erases knows what it erased.
 */
interface ShortLinkEraserInterface
{
    /**
     * Turns every link of the tenant into a blanked tombstone of the tenant `~erased`, then deletes every click and
     * rollup row of the tenant (one transaction when both tables share a connection). Returns the number of rows deleted or blanked.
     */
    public function eraseTenant(TenantKey $tenant): int;

    /**
     * Tombstones the links of one source (e.g. an e-mail send), blanked like a tenant erasure but in their tenant, then
     * deletes their click and rollup rows.
     * Returns the number of rows deleted or tombstoned.
     */
    public function eraseSource(TenantKey $tenant, LinkKind $kind, string $sourceRef): int;

    /**
     * Removes a person from `created_by` / `updated_by` everywhere. Returns the number of links changed.
     */
    public function anonymiseActor(ActorRef $actor): int;

    /**
     * Personal-data export of one source: its links (code, label, position, target, title, creation time), one row per
     * stored raw click, and the daily rollup rows of its links
     * (referrer host, country and browser family per day stay there after the raw clicks are purged).
     *
     * @return array{links: list<array{code: string, label: string|null, position: int|null, target: string, title: string|null, created_at: string}>, clicks: list<array{code: string, label: string|null, position: int|null, clicked_at: string, ip_prefix: string|null, ua_family: string|null, referrer_host: string|null, country: string|null, machine: bool}>, daily: list<array{code: string, label: string|null, position: int|null, day: string, dimension: string, value: string, clicks: int, machine_clicks: int}>}
     */
    public function exportSource(TenantKey $tenant, LinkKind $kind, string $sourceRef): array;
}

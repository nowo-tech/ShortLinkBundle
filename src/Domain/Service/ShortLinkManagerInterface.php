<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Service;

use Nowo\ShortLinkBundle\Domain\Exception\AccessDeniedException;
use Nowo\ShortLinkBundle\Domain\Exception\CodeTakenException;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Exception\QuotaExceededException;
use Nowo\ShortLinkBundle\Domain\Exception\ShortLinkNotFoundException;
use Nowo\ShortLinkBundle\Domain\Exception\TargetRefusedException;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPage;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UpdateShortLink;

/**
 * Creates and manages the links of a tenant (contract §3.3). Every method takes the tenant explicitly and every query
 * filters on it: another tenant's id answers as missing. Events are dispatched after commit.
 */
interface ShortLinkManagerInterface
{
    /**
     * Creates one link.
     *
     * @throws AccessDeniedException|CodeTakenException|InvalidShortLinkArgumentException|QuotaExceededException|TargetRefusedException
     */
    public function create(TenantKey $tenant, CreateShortLink $command): ShortLinkView;

    /**
     * Creates generated links in bulk (e-mail sends): one transaction, random codes only, the quota checked once for
     * the batch. A command whose source (kind, ref, position) already has a live link returns that link instead.
     *
     * @param list<CreateShortLink> $commands at most 200
     *
     * @throws AccessDeniedException|InvalidShortLinkArgumentException|QuotaExceededException|TargetRefusedException
     *
     * @return list<ShortLinkView> same order as the commands
     */
    public function createMany(TenantKey $tenant, array $commands): array;

    /**
     * Replaces the target, title and expiry (the code is immutable).
     *
     * @throws AccessDeniedException|InvalidShortLinkArgumentException|ShortLinkNotFoundException|TargetRefusedException
     */
    public function update(TenantKey $tenant, ShortLinkId $id, UpdateShortLink $command): ShortLinkView;

    /**
     * Disables a link (the redirect answers 404).
     *
     * @throws AccessDeniedException|ShortLinkNotFoundException
     */
    public function disable(TenantKey $tenant, ShortLinkId $id): void;

    /**
     * Enables a link again; the save guard runs again on its target.
     *
     * @throws AccessDeniedException|ShortLinkNotFoundException|TargetRefusedException
     */
    public function enable(TenantKey $tenant, ShortLinkId $id): void;

    /**
     * Turns a link into a tombstone: the redirect answers 404 and the code stays taken for `code.quarantine`.
     *
     * @throws AccessDeniedException|ShortLinkNotFoundException
     */
    public function delete(TenantKey $tenant, ShortLinkId $id): void;

    /**
     * One live (non-deleted) link of the tenant, or null.
     *
     * @throws AccessDeniedException
     */
    public function find(TenantKey $tenant, ShortLinkId $id): ?ShortLinkView;

    /**
     * A keyset page of live links, newest first.
     *
     * @throws AccessDeniedException
     */
    public function list(TenantKey $tenant, ShortLinkListQuery $query): ShortLinkPage;

    /**
     * Absolute short URL of a code (`public_base_url`, else the router request context).
     */
    public function shortUrl(ShortCode $code): string;
}

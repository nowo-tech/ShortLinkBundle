<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application;

use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkQuotaInterface;

/**
 * Quota display of the UI: the plan limit of a counted kind and the live links that use it. Stateless (worker mode).
 *
 * @internal
 */
final readonly class QuotaReader
{
    /**
     * Holds the store, the quota port and the settings.
     */
    public function __construct(
        private ShortLinkStoreInterface $store,
        private ShortLinkQuotaInterface $quota,
        private ManagerSettings $settings,
    ) {
    }

    /**
     * Usage of the kind in the tenant; null when the kind is not counted or the plan has no limit.
     */
    public function usage(TenantKey $tenant, LinkKind $kind): ?QuotaUsage
    {
        if (!$this->settings->isCounted($kind->value)) {
            return null;
        }
        $limit = $this->quota->limitFor($tenant, $kind);

        return $limit === null ? null : new QuotaUsage($this->store->countLive($tenant, $kind), $limit);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\RateLimit;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Port\CustomCodeLimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function hash;

/**
 * Default {@see CustomCodeLimiterInterface}: a symfony/rate-limiter fixed window per tenant + actor
 * (`code.custom_code_rate_limit`, stored in `redirect.rate_limit.cache_pool`; the factory gets the host's lock factory
 * when symfony/lock is installed, so the consume is atomic across processes). Every attempt consumes one token before
 * the lookup. The key is a hash (the cache never holds a user id or tenant in clear); callers without an actor share
 * one window per tenant. Stateless (worker mode): the window lives in the cache pool.
 */
final readonly class RateLimiterCustomCodeLimiter implements CustomCodeLimiterInterface
{
    /**
     * Holds the limiter factory.
     */
    public function __construct(private RateLimiterFactoryInterface $limiter)
    {
    }

    /**
     * Consumes one token of the person's window; false when none was left.
     */
    public function attempt(TenantKey $tenant, ?ActorRef $actor): bool
    {
        $key = hash('sha256', 'tenant:' . $tenant->value . "\0" . ($actor instanceof ActorRef ? 'actor:' . $actor->value : 'anonymous'));

        return $this->limiter->create($key)->consume()->isAccepted();
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\RateLimit;

use Nowo\ShortLinkBundle\Domain\Model\ActorRef;
use Nowo\ShortLinkBundle\Domain\Port\CodeTakenLimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function hash;

/**
 * Default {@see CodeTakenLimiterInterface}: a symfony/rate-limiter fixed window per person (`code.taken_rate_limit`,
 * stored in `redirect.rate_limit.cache_pool`). The key is a hash of the actor id (anonymous callers share one key), so
 * the cache never holds a user id in clear. Stateless (worker mode): the window lives in the cache pool.
 */
final readonly class RateLimiterCodeTakenLimiter implements CodeTakenLimiterInterface
{
    /**
     * Holds the limiter factory.
     */
    public function __construct(private RateLimiterFactoryInterface $limiter)
    {
    }

    /**
     * Whether a token is left in the person's window (peek, consumes nothing).
     */
    public function allows(?ActorRef $actor): bool
    {
        return $this->limiter->create($this->key($actor))->consume(0)->getRemainingTokens() > 0;
    }

    /**
     * Consumes one token of the person's window.
     */
    public function hit(?ActorRef $actor): void
    {
        $this->limiter->create($this->key($actor))->consume();
    }

    private function key(?ActorRef $actor): string
    {
        return hash('sha256', $actor instanceof ActorRef ? 'actor:' . $actor->value : 'anonymous');
    }
}

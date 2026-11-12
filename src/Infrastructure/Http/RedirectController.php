<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ShortLinkBundle\Application\Click\ClickPayload;
use Nowo\ShortLinkBundle\Application\Click\ClickRecorder;
use Nowo\ShortLinkBundle\Application\Click\RedirectResolver;
use Nowo\ShortLinkBundle\Domain\Model\ClickWriteMode;
use Nowo\ShortLinkBundle\Domain\Model\ClientIpPrefix;
use Nowo\ShortLinkBundle\Domain\Model\ReferrerHost;
use Nowo\ShortLinkBundle\Domain\Model\ResolvedLink;
use Nowo\ShortLinkBundle\Domain\Port\UserAgentClassifierInterface;
use Nowo\ShortLinkBundle\Infrastructure\Messenger\RecordShortLinkClick;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Throwable;
use Twig\Environment;

use function hash;
use function max;
use function preg_match;

/**
 * Public redirect `GET|HEAD {redirect.path_prefix}/{code}` (contract §7). Stateless: no session, no cookie, no
 * security token needed. Order, first failure answers:
 *
 *  1. code outside `[A-Za-z0-9-]{3,32}` → neutral 404 (no database hit), a miss;
 *  2. miss limiter of the client IP exhausted → 429 with `Retry-After`;
 *  3–5. lookup + exact re-check, state, redirect guard (no DNS) → neutral 404, a miss;
 *  6. hit limiter of the client IP → over the limit: redirect without logging (`redirect_unlogged`) or 429 (`block`);
 *  7. 302 / 307 to the stored target; the click is recorded after the response (HEAD is never logged).
 *
 * Every answer carries `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex,
 * nofollow` and `X-Content-Type-Options: nosniff`. The 404 body is the same bytes for every reason. Query parameters
 * never influence the answer. Stateless service (worker mode): the click payload lives on the request only.
 */
final readonly class RedirectController
{
    /** Codes the route may carry (random and custom). */
    public const CODE_PATTERN = '/^[A-Za-z0-9-]{3,32}$/';

    /**
     * @param RateLimiterFactoryInterface|null $misses per-IP limiter of misses; null = `rate_limit.enabled: false`
     * @param RateLimiterFactoryInterface|null $hits per-IP limiter of hits; null = `rate_limit.enabled: false`
     * @param bool $blockOverHits `over_limit_hits: block`
     * @param ClickWriteMode|null $writeMode null = `click_log.enabled: false`
     * @param int $status 302 or 307
     */
    public function __construct(
        private RedirectResolver $resolver,
        private UserAgentClassifierInterface $classifier,
        private ClickRecorder $recorder,
        private Environment $twig,
        private ?RateLimiterFactoryInterface $misses = null,
        private ?RateLimiterFactoryInterface $hits = null,
        private bool $blockOverHits = false,
        private ?ClickWriteMode $writeMode = ClickWriteMode::Terminate,
        private ?MessageBusInterface $bus = null,
        private int $status = Response::HTTP_FOUND,
        private string $notFoundTemplate = '@NowoShortLink/redirect/not_found.html.twig',
        private int $ipv4Prefix = 24,
        private int $ipv6Prefix = 48,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Answers one short code.
     */
    public function __invoke(Request $request, string $code): Response
    {
        $ipKey = hash('sha256', $request->getClientIp() ?? 'unknown');
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            $this->misses?->create($ipKey)->consume();

            return $this->notFound();
        }
        $missLimiter = $this->misses?->create($ipKey);
        $peek        = $missLimiter?->consume(0);
        if ($peek instanceof RateLimit && $peek->getRemainingTokens() === 0) {
            return $this->tooMany($peek->getRetryAfter());
        }

        $link = $this->resolver->resolve($code);
        if (!$link instanceof ResolvedLink) {
            $missLimiter?->consume();

            return $this->notFound();
        }

        $head   = $request->isMethod('HEAD');
        $logged = !$head && $this->writeMode instanceof ClickWriteMode;
        if (!$head && $this->hits instanceof RateLimiterFactoryInterface) {
            $hit = $this->hits->create($ipKey)->consume();
            if (!$hit->isAccepted()) {
                if ($this->blockOverHits) {
                    return $this->tooMany($hit->getRetryAfter());
                }
                $logged = false;
            }
        }

        if ($logged) {
            $this->recordLater($request, $link);
        }

        return $this->answer('', $this->status, ['Location' => $link->target->toString()]);
    }

    private function recordLater(Request $request, ResolvedLink $link): void
    {
        $gpc     = $request->headers->get('Sec-GPC') === '1';
        $payload = new ClickPayload(
            $link->id,
            $link->tenant,
            $link->kind,
            $link->sourceRef,
            ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC')),
            $gpc ? null : ClientIpPrefix::fromIp($request->getClientIp(), $this->ipv4Prefix, $this->ipv6Prefix),
            $this->classifier->classify($request->headers->get('User-Agent')),
            $gpc ? null : ReferrerHost::fromReferer($request->headers->get('Referer')),
            $gpc,
        );
        if ($this->writeMode === ClickWriteMode::Sync) {
            $this->recorder->record($payload, $request->getClientIp());
        } elseif ($this->writeMode === ClickWriteMode::Messenger) {
            try {
                $this->bus?->dispatch(new RecordShortLinkClick($payload));
            } catch (Throwable $exception) {
                $this->logger?->warning('nowo_short_link: click of link {link} not queued: {class}', ['link' => $link->id->value, 'class' => $exception::class]);
            }
        } else {
            $request->attributes->set(ClickTerminateListener::ATTRIBUTE, [$payload, $request->getClientIp()]);
        }
    }

    private function notFound(): Response
    {
        return $this->answer($this->twig->render($this->notFoundTemplate, ['status' => Response::HTTP_NOT_FOUND]), Response::HTTP_NOT_FOUND);
    }

    private function tooMany(DateTimeImmutable $retryAfter): Response
    {
        $seconds = max(1, $retryAfter->getTimestamp() - (new DateTimeImmutable())->getTimestamp());

        return $this->answer($this->twig->render($this->notFoundTemplate, ['status' => Response::HTTP_TOO_MANY_REQUESTS]), Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $seconds]);
    }

    /**
     * A new response with the protective headers of every answer (contract §7.2).
     *
     * @param array<string, string> $headers
     */
    private function answer(string $body, int $status, array $headers = []): Response
    {
        return new Response($body, $status, $headers + [
            'Cache-Control'          => 'no-store, private',
            'Referrer-Policy'        => 'no-referrer',
            'X-Robots-Tag'           => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type'           => 'text/html; charset=UTF-8',
        ]);
    }
}

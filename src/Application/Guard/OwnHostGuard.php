<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Guard;

use Nowo\ShortLinkBundle\Domain\Exception\TargetRefusedException;
use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;

use function in_array;
use function parse_url;
use function rtrim;
use function str_starts_with;
use function strtolower;

/**
 * `target.allow_own_host` (Beacon re-audit C4): targets on this application's own origins are allowed without the
 * inner guard (so an intranet or `localhost` application can link to its own pages, which OutboundUrlGuard refuses as
 * private), except under the redirect paths, which stay refused as `SelfLoop` (no chains or loops through `/s/`).
 *
 * Own origins come from configuration only, never from the request: `scheme://host:port` of `public_base_url` (exact
 * scheme, host and port) and, for each exact host of `target.own_hosts`, `http://host:80` and `https://host:443`.
 * The Host header is never read (it is spoofable without `framework.trusted_hosts`, and a CLI recheck or a Messenger
 * worker has no request): a target gets the same verdict in a request, on the CLI and in a kept-alive worker. Every
 * other target goes to the inner guard unchanged, so a non-default port, a suffix (`own.example.evil`), a look-alike
 * IDN (punycode differs) or a different scheme than `public_base_url` is checked as a foreign host. Userinfo,
 * scheme-relative and other malformed targets never reach a guard ({@see TargetUrl}).
 */
final readonly class OwnHostGuard implements TargetGuardInterface
{
    /**
     * @param TargetGuardInterface $inner the guard for every target that is not on an own origin
     * @param list<string> $ownHosts `target.own_hosts` (exact hosts; a `.suffix` entry never matches)
     * @param list<string> $selfPaths absolute paths refused on own origins, e.g. `/s/`
     * @param string|null $publicBaseUrl `public_base_url` (may come from an env variable)
     */
    public function __construct(
        private TargetGuardInterface $inner,
        private array $ownHosts,
        private array $selfPaths,
        private ?string $publicBaseUrl = null,
    ) {
    }

    /**
     * Allowed on an own origin outside the redirect paths, `SelfLoop` under them, the inner verdict elsewhere.
     */
    public function check(TargetUrl $target, GuardStage $stage): TargetVerdict
    {
        if (!in_array(self::origin($target), $this->ownOrigins(), true)) {
            return $this->inner->check($target, $stage);
        }
        $path = strtolower(SelfLoopGuard::normalise($target->path()));
        foreach ($this->selfPaths as $selfPath) {
            $selfPath = strtolower($selfPath);
            if (str_starts_with($path, $selfPath) || $path === rtrim($selfPath, '/')) {
                return TargetVerdict::refuse(TargetRefusal::SelfLoop);
            }
        }

        return TargetVerdict::allow();
    }

    /**
     * `scheme://host:port` of a normalised target (the default port filled in).
     */
    public static function origin(TargetUrl $target): string
    {
        /** @var array{scheme: string, host: string, port?: int} $parts a normalised target always parses */
        $parts  = parse_url($target->toString());
        $scheme = $parts['scheme'];

        return $scheme . '://' . $parts['host'] . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * The own origins, computed on each call from configuration only (nothing request-scoped is kept).
     *
     * @return list<string>
     */
    private function ownOrigins(): array
    {
        $origins = [];
        foreach ($this->ownHosts as $host) {
            $host = strtolower(rtrim($host, '.'));
            if ($host !== '' && !str_starts_with($host, '.')) {
                $origins[] = 'http://' . $host . ':80';
                $origins[] = 'https://' . $host . ':443';
            }
        }
        if ($this->publicBaseUrl !== null && $this->publicBaseUrl !== '') {
            try {
                $origins[] = self::origin(TargetUrl::fromString($this->publicBaseUrl));
            } catch (TargetRefusedException) {
                // An unusable public_base_url adds no own origin (fails closed to the inner guard).
            }
        }

        return $origins;
    }
}

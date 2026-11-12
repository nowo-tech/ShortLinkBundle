<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Guard;

use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;

use function array_pop;
use function chr;
use function explode;
use function hexdec;
use function implode;
use function in_array;
use function is_string;
use function parse_url;
use function preg_match;
use function preg_replace_callback;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strtolower;

use const PHP_URL_HOST;

/**
 * Refuses targets on this application's own hosts under the redirect paths: no redirect chains or loops through
 * `/s/`. Own hosts are `target.own_hosts` plus the host of `public_base_url`; self paths are `target.self_paths` plus
 * `redirect.path_prefix` + `/`. Before the comparison the target path is normalised the way a server routes it:
 * percent-encoded unreserved characters are decoded (`%73` → `s`) and dot segments removed (`/x/../s/` → `/s/`).
 */
final readonly class SelfLoopGuard implements TargetGuardInterface
{
    /**
     * @param list<string> $ownHosts lowercased hosts
     * @param list<string> $selfPaths absolute paths, e.g. `/s/`
     * @param string|null $publicBaseUrl `public_base_url` (may come from an env variable; its host counts as own)
     */
    public function __construct(private array $ownHosts, private array $selfPaths, private ?string $publicBaseUrl = null)
    {
    }

    /**
     * Refused with `SelfLoop` when the target is one of our own short links.
     */
    public function check(TargetUrl $target, GuardStage $stage): TargetVerdict
    {
        $base = $this->publicBaseUrl === null ? null : parse_url($this->publicBaseUrl, PHP_URL_HOST);
        $own  = [...$this->ownHosts, ...(is_string($base) ? [strtolower(rtrim($base, '.'))] : [])];
        if (!in_array($target->host(), $own, true)) {
            return TargetVerdict::allow();
        }
        $path = self::normalise($target->path());
        foreach ($this->selfPaths as $selfPath) {
            if (str_starts_with($path, $selfPath) || $path === rtrim($selfPath, '/')) {
                return TargetVerdict::refuse(TargetRefusal::SelfLoop);
            }
        }

        return TargetVerdict::allow();
    }

    /**
     * Decodes percent-encoded unreserved characters and removes dot segments (RFC 3986 §2.3, §5.2.4).
     */
    public static function normalise(string $path): string
    {
        $decoded = (string) preg_replace_callback('/%([0-9A-Fa-f]{2})/', static function (array $m): string {
            $char = chr((int) hexdec($m[1]));

            return preg_match('/^[A-Za-z0-9._~-]$/D', $char) === 1 ? $char : '%' . $m[1];
        }, $path);
        $output = [];
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '..') {
                array_pop($output);
                if ($output === []) {
                    $output[] = '';
                }
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
        }
        $result = implode('/', $output);
        if (str_ends_with($decoded, '/..') || str_ends_with($decoded, '/.')) {
            $result .= '/';
        }

        return str_starts_with($result, '/') ? $result : '/' . $result;
    }
}

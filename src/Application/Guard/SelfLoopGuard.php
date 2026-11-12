<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Guard;

use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TargetVerdict;
use Nowo\ShortLinkBundle\Domain\Port\TargetGuardInterface;

use function in_array;
use function rtrim;
use function str_starts_with;

/**
 * Refuses targets on this application's own hosts under the redirect paths (`target.own_hosts` +
 * `target.self_paths`): no redirect chains or loops through `/s/`.
 */
final readonly class SelfLoopGuard implements TargetGuardInterface
{
    /**
     * @param list<string> $ownHosts lowercased hosts
     * @param list<string> $selfPaths absolute paths, e.g. `/s/`
     */
    public function __construct(private array $ownHosts, private array $selfPaths)
    {
    }

    /**
     * Refused with `SelfLoop` when the target is one of our own short links.
     */
    public function check(TargetUrl $target, GuardStage $stage): TargetVerdict
    {
        if (!in_array($target->host(), $this->ownHosts, true)) {
            return TargetVerdict::allow();
        }
        $path = $target->path();
        foreach ($this->selfPaths as $selfPath) {
            if (str_starts_with($path, $selfPath) || $path === rtrim($selfPath, '/')) {
                return TargetVerdict::refuse(TargetRefusal::SelfLoop);
            }
        }

        return TargetVerdict::allow();
    }
}

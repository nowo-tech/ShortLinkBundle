<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application\Default;

use Nowo\ShortLinkBundle\Domain\Model\GuardStage;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Port\TargetDenylistInterface;

use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Default denylist: `target.denylist.hosts`. An entry is an exact host (`bad.example`) or a suffix (`.bad.example`,
 * which also matches `bad.example` itself). Kept in memory (immutable): cheap on the redirect.
 */
final readonly class ConfigDenylist implements TargetDenylistInterface
{
    /**
     * @param list<string> $hosts lowercased entries
     */
    public function __construct(private array $hosts = [])
    {
    }

    /**
     * Whether the target host matches an entry.
     */
    public function isDenied(TargetUrl $target, GuardStage $stage): bool
    {
        $host = $target->host();
        foreach ($this->hosts as $entry) {
            if (str_starts_with($entry, '.') ? ($host === substr($entry, 1) || str_ends_with($host, $entry)) : $host === $entry) {
                return true;
            }
        }

        return false;
    }
}

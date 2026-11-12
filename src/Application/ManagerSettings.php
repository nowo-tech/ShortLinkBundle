<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application;

use function in_array;

/**
 * The configuration the manager needs (from `nowo_short_link`), as one immutable object.
 *
 * @internal
 */
final readonly class ManagerSettings
{
    /**
     * @param array<string, array{allow_custom_code: bool, show_in_ui: bool, counted: bool}> $kinds `kinds`
     * @param list<string> $countedKinds `quota.counted_kinds`
     */
    public function __construct(
        public int $randomLength = 7,
        public bool $allowCustom = true,
        public int $maxAttempts = 5,
        public int $targetMaxLength = 2048,
        public bool $enforceAccess = true,
        public array $kinds = [
            'manual' => ['allow_custom_code' => true, 'show_in_ui' => true, 'counted' => true],
            'email'  => ['allow_custom_code' => false, 'show_in_ui' => false, 'counted' => false],
        ],
        public array $countedKinds = ['manual'],
    ) {
    }

    /**
     * Whether the kind is configured.
     */
    public function hasKind(string $kind): bool
    {
        return isset($this->kinds[$kind]);
    }

    /**
     * Whether people may choose the code of this kind.
     */
    public function allowsCustomCode(string $kind): bool
    {
        return $this->allowCustom && ($this->kinds[$kind]['allow_custom_code'] ?? false);
    }

    /**
     * Whether the quota applies to this kind (listed in `quota.counted_kinds` and `counted: true`).
     */
    public function isCounted(string $kind): bool
    {
        return in_array($kind, $this->countedKinds, true) && ($this->kinds[$kind]['counted'] ?? false);
    }

    /**
     * Kinds the UI lists when no kind is asked for.
     *
     * @return list<string>
     */
    public function listedKinds(): array
    {
        $kinds = [];
        foreach ($this->kinds as $name => $flags) {
            if ($flags['show_in_ui']) {
                $kinds[] = $name;
            }
        }

        return $kinds;
    }
}

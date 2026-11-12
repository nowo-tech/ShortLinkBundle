<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;

use function mb_strlen;
use function preg_match;

/**
 * Origin of a generated link: the host reference (e.g. the e-mail send id), the link label and its position in the
 * message. `createMany()` deduplicates on (kind, ref, position) so a retried send never duplicates links.
 */
final readonly class LinkSource
{
    /** Cleaned label, ≤ 128 characters, or null. */
    public ?string $label;

    /**
     * @param string $ref `^[A-Za-z0-9._:-]{1,64}$`, exact
     * @param string|null $label shown in the e-mail log; control characters are stripped
     * @param int|null $position 0–999
     *
     * @throws InvalidShortLinkArgumentException when a part breaks its rule
     */
    public function __construct(public string $ref, ?string $label = null, public ?int $position = null)
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $ref) !== 1) {
            throw new InvalidShortLinkArgumentException('A source ref has 1 to 64 characters [A-Za-z0-9._:-].');
        }
        if ($position !== null && ($position < 0 || $position > 999)) {
            throw new InvalidShortLinkArgumentException('A source position is between 0 and 999.');
        }
        $label = $label === null ? null : Text::clean($label);
        if ($label !== null && mb_strlen($label) > 128) {
            throw new InvalidShortLinkArgumentException('A source label has at most 128 characters.');
        }
        $this->label = $label === '' ? null : $label;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Application;

use Nowo\ShortLinkBundle\Domain\Model\ShortCode;

/**
 * Builds the absolute short URL of a code.
 *
 * @internal
 */
interface ShortUrlBuilderInterface
{
    /**
     * Absolute URL `<base><redirect prefix>/<code>`.
     */
    public function build(ShortCode $code): string;
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use InvalidArgumentException;

/**
 * A value object refused its input. The message names the rule, never the rejected value.
 */
final class InvalidShortLinkArgumentException extends InvalidArgumentException implements ShortLinkExceptionInterface
{
}

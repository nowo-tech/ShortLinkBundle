<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Exception;

use DomainException;
use Nowo\ShortLinkBundle\Domain\Model\TargetRefusal;

/**
 * The target guard refused a target. The message names the reason, never the URL or a DNS answer.
 */
final class TargetRefusedException extends DomainException implements ShortLinkExceptionInterface
{
    /**
     * @param TargetRefusal $refusal why the target was refused
     */
    public function __construct(public readonly TargetRefusal $refusal)
    {
        parent::__construct($refusal->message());
    }
}

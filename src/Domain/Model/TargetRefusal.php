<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain\Model;

/**
 * Why a target was refused (contract §8). The messages never echo the URL or a DNS answer.
 */
enum TargetRefusal: string
{
    case Scheme       = 'scheme';
    case Userinfo     = 'userinfo';
    case Length       = 'length';
    case PrivateHost  = 'private_host';
    case Metadata     = 'metadata';
    case Unresolvable = 'unresolvable';
    case Denylisted   = 'denylisted';
    case SelfLoop     = 'self_loop';

    /**
     * Message for people (form field errors; translated by the UI through the case value).
     */
    public function message(): string
    {
        return match ($this) {
            self::Scheme       => 'The target must be an absolute http or https URL.',
            self::Userinfo     => 'The target must not contain a user name or password.',
            self::Length       => 'The target URL is too long.',
            self::PrivateHost  => 'The target must not point to a private or local address.',
            self::Metadata     => 'The target must not point to a cloud metadata address.',
            self::Unresolvable => 'The target host could not be resolved.',
            self::Denylisted   => 'This target is not allowed.',
            self::SelfLoop     => 'The target must not be another short link of this site.',
        };
    }
}

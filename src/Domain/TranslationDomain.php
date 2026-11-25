<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Domain;

/**
 * Translation domain of the bundle UI copy (REQ-I18N-003). Every layer reads it from here; the bundle class re-exports
 * it as `NowoShortLinkBundle::TRANSLATION_DOMAIN` with the same value (REQ-ARCH-002: no layer depends on the
 * composition root).
 */
final class TranslationDomain
{
    /** The domain name (also the Twig namespace, REQ-TWIG-002). */
    public const NAME = 'NowoShortLinkBundle';
}

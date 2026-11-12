<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Infrastructure\Routing;

use LogicException;
use Nowo\ShortLinkBundle\Application\ShortUrlBuilderInterface;
use Nowo\ShortLinkBundle\DependencyInjection\Configuration;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Symfony\Component\Routing\RequestContext;

use function rtrim;

/**
 * Absolute short URLs: `public_base_url` when set (needed outside a request: Messenger, CLI), else the router request
 * context of the current request. Reads its inputs on every call (worker mode).
 *
 * @internal
 */
final readonly class ShortUrlBuilder implements ShortUrlBuilderInterface
{
    /**
     * @param string|null $publicBaseUrl `public_base_url` (may come from an env variable; re-checked here)
     * @param string $pathPrefix `redirect.path_prefix`
     */
    public function __construct(
        private ?string $publicBaseUrl,
        private string $pathPrefix,
        private ?RequestContext $context = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function build(ShortCode $code): string
    {
        return $this->base() . $this->pathPrefix . '/' . $code->value;
    }

    private function base(): string
    {
        if ($this->publicBaseUrl !== null && $this->publicBaseUrl !== '') {
            if (!Configuration::isBaseUrl($this->publicBaseUrl)) {
                throw new LogicException('nowo_short_link.public_base_url must be an absolute http(s) URL without query or fragment.');
            }

            return rtrim($this->publicBaseUrl, '/');
        }
        if (!$this->context instanceof RequestContext || $this->context->getHost() === '') {
            throw new LogicException('nowo_short_link cannot build an absolute short URL here: set public_base_url (or framework.router.default_uri).');
        }
        $scheme = $this->context->getScheme();
        $port   = $scheme === 'https' ? $this->context->getHttpsPort() : $this->context->getHttpPort();
        $port   = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port;

        return $scheme . '://' . $this->context->getHost() . $port . rtrim($this->context->getBaseUrl(), '/');
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host controller of the worker test: answers only from the current request (tenant header) and a container parameter.
 */
final readonly class EchoController
{
    public function __construct(private string $redirectPrefix)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['tenant' => $request->headers->get('X-Test-Tenant'), 'redirect_prefix' => $this->redirectPrefix]);
    }
}

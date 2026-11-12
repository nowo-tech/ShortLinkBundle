<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration;

use DateTimeImmutable;
use Nowo\ShortLinkBundle\Application\QuotaReader;
use Nowo\ShortLinkBundle\Domain\Exception\AccessDeniedException;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Exception\QuotaExceededException;
use Nowo\ShortLinkBundle\Domain\Exception\ShortLinkNotFoundException;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPage;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UpdateShortLink;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkStatsReaderInterface;
use Nowo\ShortLinkBundle\Presentation\Controller\ShortLinkUiController;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestAccess;
use Nowo\ShortLinkBundle\Tests\Fixtures\Ddd\TestTenantContext;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;

use function assert;

/**
 * Controller answers to manager failures the access check before them does not foresee (an access port that changes
 * its answer between two calls, a quota reached by a concurrent request, a link deleted meanwhile): a denial is a 403,
 * a vanished link a 404, the others form errors. The manager is a stub; the forms, Twig and the router are real.
 */
final class UiControllerFailuresTest extends KernelTestCase
{
    private const ID = '0190a6d0-0000-7000-8000-000000000001';

    private ContainerInterface $services;

    private FailingManager $manager;

    private Session $session;

    protected function setUp(): void
    {
        $host     = Host::ddd();
        $kernel   = $this->bootKernel($host->bundleConfig(['ui' => ['enabled' => true]]), null, $host->configure(), false, true);
        $services = $kernel->getContainer()->get('test.service_container');
        assert($services instanceof ContainerInterface);
        $this->services = $services;
        $context        = $services->get(TestTenantContext::class);
        assert($context instanceof TestTenantContext);
        $context->tenant = TenantKey::default();
        $access          = $services->get(TestAccess::class);
        assert($access instanceof TestAccess);
        $access->granted = ['default' => [ShortLinkPermission::View, ShortLinkPermission::Manage]];
        $this->manager   = new FailingManager();
        $this->session   = new Session(new MockArraySessionStorage());
    }

    public function testDenialsOfTheManagerAre403(): void
    {
        $this->failWith(new AccessDeniedException());
        $controller = $this->controller();
        $calls      = [
            'list'    => fn (): Response => $controller->index($this->request('GET', [])),
            'create'  => fn (): Response => $controller->create($this->request('POST', ['short_link' => ['target' => 'https://example.com', '_token' => $this->token('nowo_short_link_form')]])),
            'stats'   => fn (): Response => $controller->stats($this->request('GET', []), self::ID),
            'disable' => fn (): Response => $controller->disable($this->request('POST', ['confirm' => '1', '_token' => $this->token('nowo_short_link_disable')]), self::ID),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name . ' must be denied.');
            } catch (AccessDeniedHttpException $exception) {
                self::assertInstanceOf(AccessDeniedException::class, $exception->getPrevious()?->getPrevious(), $name);
            }
        }

        $this->manager->findable = true;
        try {
            $controller->edit($this->request('POST', ['short_link' => ['target' => 'https://example.com', '_token' => $this->token('nowo_short_link_form')]]), self::ID);
            self::fail('edit must be denied.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testOtherFailuresBecomeFormErrorsOrNotFound(): void
    {
        $controller              = $this->controller();
        $this->manager->findable = true;
        $create                  = fn (): Response => $controller->create($this->request('POST', ['short_link' => ['target' => 'https://example.com', '_token' => $this->token('nowo_short_link_form')]]));
        $edit                    = fn (): Response => $controller->edit($this->request('POST', ['short_link' => ['target' => 'https://example.com', '_token' => $this->token('nowo_short_link_form')]]), self::ID);

        $this->failWith(new QuotaExceededException(5));
        self::assertStringContainsString('The short-link limit of your plan is reached.', (string) $create()->getContent());
        $this->failWith(new InvalidShortLinkArgumentException('x'));
        $response = $create();
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('The short link could not be saved.', (string) $response->getContent());
        self::assertStringContainsString('The short link could not be saved.', (string) $edit()->getContent());

        $this->failWith(new ShortLinkNotFoundException());
        $this->expectException(NotFoundHttpException::class);
        $edit();
    }

    private function failWith(Throwable $exception): void
    {
        $this->manager->throw = $exception;
    }

    private function controller(): ShortLinkUiController
    {
        $get = function (string $id): object {
            $service = $this->services->get($id);
            assert($service !== null);

            return $service;
        };
        $stats      = $get(ShortLinkStatsReaderInterface::class);
        $quota      = $get(QuotaReader::class);
        $forms      = $get('form.factory');
        $twig       = $get('twig');
        $router     = $get('router');
        $translator = $get('translator');
        $tenants    = $get(TestTenantContext::class);
        $access     = $get(TestAccess::class);
        assert($stats instanceof ShortLinkStatsReaderInterface && $quota instanceof QuotaReader && $forms instanceof FormFactoryInterface && $twig instanceof Environment);
        assert($router instanceof UrlGeneratorInterface && $translator instanceof TranslatorInterface && $tenants instanceof TestTenantContext && $access instanceof TestAccess);

        return new ShortLinkUiController($this->manager, $stats, $tenants, $access, $quota, $forms, $twig, $router, $translator, true, 'workspace');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, array $body): Request
    {
        $request = Request::create('/t/acme/short-links', $method, $body);
        $request->setSession($this->session);
        $request->attributes->set('workspace', 'acme');
        $stack = $this->services->get('request_stack');
        assert($stack instanceof RequestStack);
        while ($stack->pop() instanceof Request) {
        }
        $stack->push($request);

        return $request;
    }

    private function token(string $id): string
    {
        $this->request('GET', []);
        $tokens = $this->services->get('security.csrf.token_manager');
        assert($tokens instanceof CsrfTokenManagerInterface);

        return $tokens->getToken($id)->getValue();
    }
}

/**
 * Manager stub: throws the configured exception from every call; `find()` returns one link when `findable`.
 */
final class FailingManager implements ShortLinkManagerInterface
{
    public ?Throwable $throw = null;

    public bool $findable = false;

    public function create(TenantKey $tenant, CreateShortLink $command): ShortLinkView
    {
        throw $this->throw ?? new AccessDeniedException();
    }

    public function createMany(TenantKey $tenant, array $commands): array
    {
        return [];
    }

    public function update(TenantKey $tenant, ShortLinkId $id, UpdateShortLink $command): ShortLinkView
    {
        throw $this->throw ?? new AccessDeniedException();
    }

    public function disable(TenantKey $tenant, ShortLinkId $id): void
    {
        throw $this->throw ?? new AccessDeniedException();
    }

    public function enable(TenantKey $tenant, ShortLinkId $id): void
    {
    }

    public function delete(TenantKey $tenant, ShortLinkId $id): void
    {
    }

    public function find(TenantKey $tenant, ShortLinkId $id): ShortLinkView
    {
        if (!$this->findable) {
            throw $this->throw ?? new AccessDeniedException();
        }
        $now = new DateTimeImmutable('2026-11-12 10:00:00');

        return new ShortLinkView($id, $tenant, ShortCode::random('aB3xY9k'), TargetUrl::fromString('https://example.com'), null, LinkKind::manual(), null, null, ShortLinkState::Active, null, null, $now, $now, null, null, 'https://links.example/s/aB3xY9k');
    }

    public function list(TenantKey $tenant, ShortLinkListQuery $query): ShortLinkPage
    {
        throw $this->throw ?? new AccessDeniedException();
    }

    public function shortUrl(ShortCode $code): string
    {
        return 'https://links.example/s/' . $code->value;
    }
}

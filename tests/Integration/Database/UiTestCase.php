<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Tests\Integration\Database;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nowo\ShortLinkBundle\Application\Store\ShortLinkStoreInterface;
use Nowo\ShortLinkBundle\Domain\Event\ShortLinkCreatedEvent;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\LinkSource;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TargetUrl;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Infrastructure\Doctrine\ClickTablesSchemaListener;
use Nowo\ShortLinkBundle\Tests\Fixtures\QueryCounter;
use Nowo\ShortLinkBundle\Tests\Fixtures\RecordingListener;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\Host;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\ShortLinkBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;

use function array_replace_recursive;
use function assert;
use function explode;
use function is_numeric;
use function preg_match;
use function str_contains;
use function str_repeat;
use function substr;
use function trim;

/**
 * Management UI end to end (contract §11, §13 S4), once per host kind: profiles (anonymous, viewer, manager, other
 * tenant, no tenant), CSRF on every POST, forms that never map `system` / `kind`, the code-taken limiter, quota,
 * XSS and CSV injection fixtures, keyset pages within the query budget, statistics and CSV.
 */
abstract class UiTestCase extends KernelTestCase
{
    /** Host route parameter of the UI import in the test kernel (`/t/{workspace}/short-links`). */
    protected const BASE = '/t/acme/short-links';

    protected TestKernel $kernel;

    protected KernelBrowser $client;

    protected TenantKey $tenant;

    protected TenantKey $otherTenant;

    abstract protected function host(): Host;

    /**
     * Grants View (and Manage) in the tenant; null tenant = no permission at all.
     */
    abstract protected function grant(?TenantKey $tenant, bool $manage = true): void;

    /**
     * Grants the permissions in the tenant to the manager service outside a request (fixtures).
     */
    abstract protected function grantOutsideRequest(TenantKey $tenant): void;

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        $host = $this->host();
        /** @var array<string, mixed> $override */
        $override     = array_replace_recursive(['ui' => ['enabled' => true, 'tenant_route_parameter' => 'workspace', 'page_size' => 10], 'click_log' => ['write' => 'sync'], 'redirect' => ['rate_limit' => ['cache_pool' => 'cache.short_link_test']]], $config);
        $configure    = $this->hostConfigure();
        $this->kernel = $this->bootKernel(
            $host->bundleConfig($override),
            TestKernel::defaultDatabaseUrl(),
            static function (ContainerConfigurator $container, string $url) use ($configure): void {
                $configure($container, $url);
                $container->services()->set(QueryCounter::class)->public()->tag('doctrine.middleware');
                // A pool that survives the per-request service reset (the array pool of cache.app is cleared).
                $container->extension('framework', ['cache' => ['pools' => ['cache.short_link_test' => ['adapter' => 'cache.adapter.filesystem']]]]);
            },
            $host->kind === Host::STANDARD,
            true,
        );
        $host->resetSchema($this->kernel);
        $client = $this->container()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $client->catchExceptions(true);
        $this->client = $client;
        $this->grantOutsideRequest($this->tenant);
        $this->grant($this->tenant);
    }

    public function testTheListShowsTheTenantsLinksWithClicksAndKeepsTheHostParameter(): void
    {
        $this->boot();
        $link = $this->create('https://example.com/launch', 'Launch');
        $this->create('https://example.org/other', null, $this->otherTenant);
        $this->click($link->code->value, 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
        $this->click($link->code->value, 'Slackbot-LinkExpanding 1.0');

        $crawler = $this->client->request('GET', self::BASE);
        self::assertSame(200, $this->httpStatus());
        $html = $this->html();
        self::assertStringContainsString($link->code->value, $html);
        self::assertStringContainsString('example.com', $html);
        self::assertStringNotContainsString('example.org', $html, 'another tenant\'s links are never listed');
        self::assertStringContainsString('data-nowo-ui-clipboard-text="https://links.example/s/' . $link->code->value . '"', $html);
        self::assertStringContainsString(self::BASE . '/' . $link->id->value . '/stats', $html);
        self::assertStringContainsString(self::BASE . '/new', $html);
        self::assertSame('1', trim($crawler->filter('td[data-label="Clicks (30 d)"]')->text()));
        self::assertStringContainsString('machine traffic: 1', $html);
        self::assertCount(1, $crawler->filter('[data-testid="short-link-state-' . $link->code->value . '"]'));
    }

    public function testTheListPagesByKeysetWithinTheQueryBudgetAndFilters(): void
    {
        $this->boot();
        for ($i = 0; $i < 11; ++$i) {
            $this->create('https://example.com/' . $i, 'Item ' . $i);
            $this->clock()->modify('+1 second');
        }
        $disabled = $this->create('https://disabled.example/x', 'Paused');
        $this->manager()->disable($this->tenant, $disabled->id);

        $counter = $this->container()->get(QueryCounter::class);
        assert($counter instanceof QueryCounter);
        $counter->count = 0;
        $crawler        = $this->client->request('GET', self::BASE);
        self::assertSame(200, $this->httpStatus());
        self::assertLessThanOrEqual(4, $counter->count, 'list page query budget (REQ-PERF-001)');
        self::assertCount(10, $crawler->filter('tbody tr'));
        $next = $crawler->filter('[data-testid="short-link-next"]');
        self::assertCount(1, $next);
        self::assertStringStartsWith(self::BASE . '?after=', (string) $next->attr('href'));

        $crawler = $this->client->request('GET', (string) $next->attr('href'));
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Item 0', $this->html());
        self::assertCount(1, $crawler->filter('[data-testid="short-link-first"]'));
        self::assertCount(0, $crawler->filter('[data-testid="short-link-next"]'));

        $crawler = $this->client->request('GET', self::BASE . '?q=paused&state=disabled');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Paused', $this->html());
        $this->client->request('GET', self::BASE . '?q=nothing-like-this');
        self::assertStringContainsString('No short link matches these filters.', $this->html());
        $this->client->request('GET', self::BASE . '?state=bogus&after=not-a-uuid&q=' . str_repeat('x', 150));
        self::assertSame(200, $this->httpStatus());
    }

    public function testCreatingALinkMakesAManualLinkAndNeverMapsSystemOrKind(): void
    {
        $this->boot();
        $crawler = $this->client->request('GET', self::BASE . '/new');
        self::assertSame(200, $this->httpStatus());
        $form = $crawler->filter('form[name="short_link"]');
        self::assertCount(1, $form);
        self::assertCount(1, $crawler->filter('input[name="short_link[customCode]"]'));
        self::assertCount(0, $crawler->filter('[name*="system"], [name*="kind"]'));
        $token = $this->token($crawler, 'short_link[_token]');

        $this->client->request('POST', self::BASE . '/new', ['short_link' => ['target' => 'https://example.com/new', 'customCode' => '', 'title' => 'Fresh', '_token' => $token, 'system' => '1', 'kind' => 'email']]);
        self::assertSame(422, $this->httpStatus(), 'extra fields are refused');
        self::assertSame(0, $this->linkCount());

        $this->client->request('POST', self::BASE . '/new', ['short_link' => ['target' => 'https://example.com/new', 'customCode' => 'Spring-Sale', 'title' => 'Fresh', 'expiresAt' => '2027-01-01T10:00', '_token' => $token]]);
        self::assertSame(303, $this->httpStatus());
        self::assertSame(self::BASE, $this->client->getResponse()->headers->get('Location'));
        $created = $this->listener()->of(ShortLinkCreatedEvent::class);
        self::assertCount(1, $created);
        self::assertFalse($created[0]->system);
        self::assertSame(LinkKind::MANUAL, $created[0]->kind->value);
        self::assertSame('spring-sale', $created[0]->code->value);
        $this->client->followRedirect();
        self::assertStringContainsString('Short link spring-sale created.', $this->html());
    }

    public function testCreateFormErrorsNameTheField(): void
    {
        $this->boot();
        $this->create('https://example.com', null, null, 'taken-code');
        $token = $this->token($this->client->request('GET', self::BASE . '/new'), 'short_link[_token]');
        $cases = [
            'bad code'      => [['target' => 'https://example.com', 'customCode' => '-bad--'], 'Use 3 to 32 lowercase letters'],
            'taken code'    => [['target' => 'https://example.com', 'customCode' => 'Taken-Code'], 'This code is not available.'],
            'reserved code' => [['target' => 'https://example.com', 'customCode' => 'admin'], 'This code is not available.'],
            'empty target'  => [['target' => '  '], 'Enter the address the short link leads to.'],
            'bad scheme'    => [['target' => 'javascript:alert(1)'], 'The target must be an absolute http or https URL.'],
            'denylisted'    => [['target' => 'https://x.bad.example/'], 'This target is not allowed.'],
            'self chain'    => [['target' => 'https://links.example/s/abc'], 'The target must not be another short link of this site.'],
            'long title'    => [['target' => 'https://example.com', 'title' => str_repeat('t', 256)], 'A title has at most 255 characters.'],
            'past expiry'   => [['target' => 'https://example.com', 'expiresAt' => '2020-01-01T10:00'], 'The expiry must be in the future.'],
        ];
        foreach ($cases as $name => [$fields, $message]) {
            $this->client->request('POST', self::BASE . '/new', ['short_link' => $fields + ['_token' => $token]]);
            self::assertSame(422, $this->httpStatus(), $name);
            self::assertStringContainsString($message, $this->html(), $name);
        }
        self::assertSame(1, $this->linkCount(), 'nothing was created');

        $this->client->request('POST', self::BASE . '/new', ['short_link' => ['target' => 'https://example.com', '_token' => 'forged']]);
        self::assertSame(422, $this->httpStatus(), 'a bad CSRF token is a form error, nothing is created');
        self::assertStringContainsString('data-testid="short-link-form-errors"', $this->html());
        self::assertSame(1, $this->linkCount());
    }

    public function testCustomCodeLookupsAreLimitedPerActorAndCountedBeforeTheLookup(): void
    {
        $this->boot(['code' => ['custom_code_rate_limit' => ['limit' => 3]]]);
        $this->create('https://example.com', null, null, 'taken-code');
        $token = $this->token($this->client->request('GET', self::BASE . '/new'), 'short_link[_token]');
        $this->client->request('POST', self::BASE . '/new', ['short_link' => ['target' => 'https://example.com', 'customCode' => 'free-one', '_token' => $token]]);
        self::assertSame(303, $this->httpStatus(), 'a free code is created, and its lookup is counted too');
        for ($i = 0; $i < 2; ++$i) {
            $this->client->request('POST', self::BASE . '/new', ['short_link' => ['target' => 'https://example.com', 'customCode' => 'taken-code', '_token' => $token]]);
            self::assertStringContainsString('This code is not available.', $this->html());
        }
        $this->client->request('POST', self::BASE . '/new', ['short_link' => ['target' => 'https://example.com', 'customCode' => 'free-code', '_token' => $token]]);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('Too many custom codes were tried.', $this->html(), 'over the limit even a free code is not looked up');
        self::assertSame(2, $this->linkCount());

        $this->client->request('POST', self::BASE . '/new', ['short_link' => ['target' => 'https://example.com', '_token' => $token]]);
        self::assertSame(303, $this->httpStatus(), 'random codes are never limited');
    }

    public function testHiddenKindsAreNotReachableById(): void
    {
        $this->boot();
        $email = $this->createEmail('https://example.com/newsletter');
        $this->create('https://example.com/visible', 'Visible');
        $crawler = $this->client->request('GET', self::BASE);
        $tokens  = [];
        foreach (['disable', 'delete'] as $action) {
            $tokens[$action] = (string) $crawler->filter('form[action$="/' . $action . '"] input[name="_token"]')->first()->attr('value');
        }
        self::assertStringNotContainsString($email->code->value, $this->html());

        foreach (['/edit', '/stats', '/stats.csv'] as $page) {
            $this->client->request('GET', self::BASE . '/' . $email->id->value . $page);
            self::assertSame(404, $this->httpStatus(), $page);
        }
        $this->client->request('POST', self::BASE . '/' . $email->id->value . '/edit', ['short_link' => ['target' => 'https://evil.example/']]);
        self::assertSame(404, $this->httpStatus());
        foreach (['disable', 'delete'] as $action) {
            $this->client->request('POST', self::BASE . '/' . $email->id->value . '/' . $action, ['confirm' => '1', '_token' => $tokens[$action]]);
            self::assertSame(404, $this->httpStatus(), $action);
        }
        $row = $this->store()->find($this->tenant, $email->id);
        self::assertNotNull($row, 'the e-mail link is untouched');
        self::assertTrue($row->active);
        self::assertSame('https://example.com/newsletter', $row->target->toString());
    }

    public function testAFullQuotaReplacesTheForm(): void
    {
        $this->boot();
        $this->setLimit(2);
        $this->create('https://example.com/1');
        $crawler = $this->client->request('GET', self::BASE . '/new');
        self::assertStringContainsString('1 of 2 links of your plan', $this->html());
        self::assertCount(1, $crawler->filter('form[name="short_link"]'));
        $this->create('https://example.com/2');

        $crawler = $this->client->request('GET', self::BASE . '/new');
        self::assertSame(200, $this->httpStatus());
        self::assertCount(0, $crawler->filter('form[name="short_link"]'));
        self::assertCount(1, $crawler->filter('[data-testid="short-link-quota-full"]'));
        self::assertCount(1, $crawler->filter('button[disabled]'));
        $this->client->request('POST', self::BASE . '/new', ['short_link' => ['target' => 'https://example.com/3']]);
        self::assertSame(2, $this->linkCount());
    }

    public function testEditingChangesTargetTitleAndExpiryOnly(): void
    {
        $this->boot();
        $link    = $this->create('https://example.com/old', 'Old');
        $crawler = $this->client->request('GET', self::BASE . '/' . $link->id->value . '/edit');
        self::assertSame(200, $this->httpStatus());
        self::assertCount(0, $crawler->filter('input[name="short_link[customCode]"]'), 'the code is immutable');
        self::assertSame('https://example.com/old', $crawler->filter('input[name="short_link[target]"]')->attr('value'));
        $token = $this->token($crawler, 'short_link[_token]');

        $this->client->request('POST', self::BASE . '/' . $link->id->value . '/edit', ['short_link' => ['target' => 'ftp://example.com', 'title' => 'New', '_token' => $token]]);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('The target must be an absolute http or https URL.', $this->html());
        $this->client->request('POST', self::BASE . '/' . $link->id->value . '/edit', ['short_link' => ['target' => 'https://example.com/new', 'title' => 'New', 'expiresAt' => '', '_token' => $token]]);
        self::assertSame(303, $this->httpStatus());
        $view = $this->manager()->find($this->tenant, $link->id);
        self::assertInstanceOf(ShortLinkView::class, $view);
        self::assertSame('https://example.com/new', $view->target->toString());
        self::assertSame('New', $view->title?->value);
        self::assertSame($link->code->value, $view->code->value);

        $other = $this->create('https://example.org', null, $this->otherTenant);
        $this->client->request('GET', self::BASE . '/' . $other->id->value . '/edit');
        self::assertSame(404, $this->httpStatus(), 'another tenant\'s id is not found');
        $this->client->request('GET', self::BASE . '/0190a6d0-0000-7000-8000-00000000dead/edit');
        self::assertSame(404, $this->httpStatus());
        $this->client->request('GET', self::BASE . '/NOT-A-UUID/edit');
        self::assertSame(404, $this->httpStatus());
    }

    public function testStateChangesNeedPostAndTheirCsrfToken(): void
    {
        $this->boot();
        $link    = $this->create('https://example.com', 'Toggle');
        $crawler = $this->client->request('GET', self::BASE);
        $tokens  = [];
        foreach (['disable', 'delete'] as $action) {
            $tokens[$action] = (string) $crawler->filter('form[action="' . self::BASE . '/' . $link->id->value . '/' . $action . '"] input[name="_token"]')->first()->attr('value');
            self::assertNotSame('', $tokens[$action], $action);
        }

        foreach ([['confirm' => '1'], ['confirm' => '1', '_token' => 'forged'], ['confirm' => '1', '_token' => $tokens['delete']], ['_token' => $tokens['disable']]] as $body) {
            $this->client->request('POST', self::BASE . '/' . $link->id->value . '/disable', $body);
            self::assertSame(403, $this->httpStatus(), 'a missing, forged or other-action token (or no form at all) is refused');
        }
        self::assertSame(ShortLinkState::Active, $this->manager()->find($this->tenant, $link->id)?->state, 'nothing changed');
        $this->client->request('GET', self::BASE . '/' . $link->id->value . '/disable');
        self::assertSame(405, $this->httpStatus());

        $this->client->request('POST', self::BASE . '/' . $link->id->value . '/disable', ['confirm' => '1', '_token' => $tokens['disable']]);
        self::assertSame(303, $this->httpStatus());
        self::assertSame(ShortLinkState::Disabled, $this->manager()->find($this->tenant, $link->id)?->state);
        $this->client->request('GET', self::BASE . '/' . $link->id->value . '/stats');
        self::assertStringContainsString('>Disabled<', $this->html());

        $crawler = $this->client->request('GET', self::BASE);
        $enable  = (string) $crawler->filter('form[action="' . self::BASE . '/' . $link->id->value . '/enable"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', self::BASE . '/' . $link->id->value . '/enable', ['confirm' => '1', '_token' => $enable]);
        self::assertSame(303, $this->httpStatus());
        self::assertSame(ShortLinkState::Active, $this->manager()->find($this->tenant, $link->id)?->state);

        $other = $this->create('https://example.org', null, $this->otherTenant);
        $this->client->request('POST', self::BASE . '/' . $other->id->value . '/delete', ['confirm' => '1', '_token' => $tokens['delete']]);
        self::assertSame(404, $this->httpStatus(), 'another tenant\'s id is not found, not forbidden');
        self::assertNotNull($this->store()->find($this->otherTenant, $other->id), 'the other tenant\'s link is untouched');

        $this->client->request('POST', self::BASE . '/' . $link->id->value . '/delete', ['confirm' => '1', '_token' => $tokens['delete']]);
        self::assertSame(303, $this->httpStatus());
        self::assertNull($this->manager()->find($this->tenant, $link->id));
        $this->client->followRedirect();
        self::assertStringContainsString('Short link deleted.', $this->html());
    }

    public function testEnablingARefusedTargetKeepsTheLinkDisabled(): void
    {
        $this->boot();
        $link  = $this->create('https://example.com/x');
        $store = $this->store();
        $row   = $store->find($this->tenant, $link->id);
        self::assertNotNull($row);
        $store->update($row->withEdit(TargetUrl::fromString('https://later.bad.example/x'), null, null, new DateTimeImmutable(Host::NOW), null)->withActive(false, 'manual', new DateTimeImmutable(Host::NOW), null));

        $crawler = $this->client->request('GET', self::BASE);
        $token   = (string) $crawler->filter('form[action="' . self::BASE . '/' . $link->id->value . '/enable"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', self::BASE . '/' . $link->id->value . '/enable', ['confirm' => '1', '_token' => $token]);
        self::assertSame(303, $this->httpStatus());
        $this->client->followRedirect();
        self::assertStringContainsString('The short link stays disabled: This target is not allowed.', $this->html());
        self::assertSame(ShortLinkState::Disabled, $this->manager()->find($this->tenant, $link->id)?->state);
    }

    public function testStatisticsShowTheChartTableAndEscapedBreakdowns(): void
    {
        $this->boot();
        $link = $this->create('https://example.com/stats', '<script>alert("t")</script>');
        $this->click($link->code->value, 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'https://news.example/item?id=1');
        $this->click($link->code->value, 'Slackbot-LinkExpanding 1.0');
        $this->insertClick($link->id, '"><img src=x onerror=alert(1)>', 'FR');

        $crawler = $this->client->request('GET', self::BASE . '/' . $link->id->value . '/stats');
        self::assertSame(200, $this->httpStatus());
        $html = $this->html();
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('news.example', $html);
        self::assertStringContainsString('Firefox', $html);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        self::assertCount(1, $crawler->filter('svg.nowo-short-link-chart__svg'));
        self::assertCount(1, $crawler->filter('[data-testid="short-link-daily"]'));
        self::assertSame(1, preg_match('/(\d+)/', $crawler->filter('[data-testid="short-link-clicks"]')->text(), $clicks));
        self::assertSame('2', $clicks[1], 'people: the Firefox click and the inserted row; the Slackbot preview is machine traffic');
        self::assertStringContainsString(self::BASE . '/' . $link->id->value . '/stats.csv?from=2026-10-14&amp;to=2026-11-12', $html);
        self::assertStringContainsString('aria-current="page"', $html, 'the default 30-day preset is active');
        self::assertStringContainsString(self::BASE . '/' . $link->id->value . '/edit', $html);

        $this->client->request('GET', self::BASE . '/' . $link->id->value . '/stats?from=2026-11-01&to=2026-11-12');
        self::assertStringContainsString('From 2026-11-01 to 2026-11-12', $this->html());
        $this->client->request('GET', self::BASE . '/' . $link->id->value . '/stats?from=2024-01-01&to=2026-11-12');
        self::assertStringContainsString('From 2025-11-12 to 2026-11-12', $this->html(), 'a longer range keeps its last 366 days');
        $this->client->request('GET', self::BASE . '/' . $link->id->value . '/stats?from=2026-11-12&to=2026-11-01');
        self::assertStringContainsString('From 2026-10-14 to 2026-11-12', $this->html(), 'a reversed range falls back to the default');

        $other = $this->create('https://example.org', null, $this->otherTenant);
        $this->client->request('GET', self::BASE . '/' . $other->id->value . '/stats');
        self::assertSame(404, $this->httpStatus());
        $this->client->request('GET', self::BASE . '/' . $other->id->value . '/stats.csv');
        self::assertSame(404, $this->httpStatus());
    }

    public function testTheCsvExportIsBoundedAndInjectionSafe(): void
    {
        $this->boot(['ui' => ['csv_max_days' => 7]]);
        $link = $this->create('https://example.com/csv');
        $this->click($link->code->value, 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'https://news.example/');
        $this->insertClick($link->id, '=HYPERLINK("https://evil.example","x")', '+1');

        $csv = $this->csv(self::BASE . '/' . $link->id->value . '/stats.csv?from=2026-10-01&to=2026-11-12');
        self::assertSame('text/csv; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('attachment; filename="short-link-' . $link->code->value . '-20261106-20261112.csv"', $this->client->getResponse()->headers->get('Content-Disposition'));
        $lines = explode("\n", trim($csv));
        self::assertSame('day,clicks,machine_clicks', $lines[0]);
        self::assertCount(7, $this->section($lines, 'day'), 'bounded by ui.csv_max_days');
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertStringNotContainsString("\n=HYPERLINK", $csv);
        self::assertStringContainsString("'+1", $csv);
        self::assertStringContainsString('referrer,clicks,machine_clicks', $csv);
        self::assertStringContainsString('browser,clicks,machine_clicks', $csv);

        $one = $this->csv(self::BASE . '/' . $link->id->value . '/stats.csv?dimension=country&excel=1');
        self::assertStringStartsWith("\u{FEFF}country,clicks,machine_clicks", $one);
        self::assertStringNotContainsString('day,clicks', $one);
        self::assertStringContainsString('day,clicks', $this->csv(self::BASE . '/' . $link->id->value . '/stats.csv?dimension=daily'));
        $this->client->request('GET', self::BASE . '/' . $link->id->value . '/stats.csv?dimension=nope');
        self::assertSame(404, $this->httpStatus());
    }

    public function testAViewerSeesButCannotChange(): void
    {
        $this->boot();
        $link = $this->create('https://example.com', 'Seen');
        $this->grant($this->tenant, false);

        $crawler = $this->client->request('GET', self::BASE);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Seen', $this->html());
        self::assertCount(0, $crawler->filter('form[action$="/delete"]'));
        self::assertStringNotContainsString(self::BASE . '/new', $this->html());
        $this->client->request('GET', self::BASE . '/' . $link->id->value . '/stats');
        self::assertSame(200, $this->httpStatus());
        self::assertStringNotContainsString(self::BASE . '/' . $link->id->value . '/edit', $this->html());
        foreach (['GET /new', 'GET /' . $link->id->value . '/edit', 'POST /' . $link->id->value . '/disable', 'POST /' . $link->id->value . '/delete'] as $call) {
            [$method, $path] = explode(' ', $call);
            $this->client->request($method, self::BASE . $path);
            self::assertSame(403, $this->httpStatus(), $call);
        }
    }

    public function testWithoutPermissionEveryPageIsDenied(): void
    {
        $this->boot();
        $link = $this->create('https://example.com');
        $this->grant(null);
        foreach (['', '/new', '/' . $link->id->value . '/stats', '/' . $link->id->value . '/stats.csv'] as $path) {
            $this->client->request('GET', self::BASE . $path);
            self::assertContains($this->httpStatus(), [401, 403], $path);
        }
    }

    public function testTheUiIsOffByDefault(): void
    {
        $this->boot(['ui' => ['enabled' => false]]);
        $this->client->request('GET', self::BASE);
        self::assertSame(404, $this->httpStatus());
        self::assertFalse($this->container()->has('Nowo\ShortLinkBundle\Presentation\Controller\ShortLinkUiController'));
    }

    protected function create(string $target, ?string $title = null, ?TenantKey $tenant = null, ?string $code = null): ShortLinkView
    {
        return $this->manager()->create($tenant ?? $this->tenant, new CreateShortLink($target, $code, $title, system: true));
    }

    protected function createEmail(string $target): ShortLinkView
    {
        return $this->manager()->createMany($this->tenant, [new CreateShortLink($target, kind: LinkKind::email(), source: new LinkSource('send-1', 'Button', 1), system: true)])[0];
    }

    protected function manager(): ShortLinkManagerInterface
    {
        $this->grantOutsideRequest($this->tenant);
        $manager = $this->container()->get(ShortLinkManagerInterface::class);
        assert($manager instanceof ShortLinkManagerInterface);

        return $manager;
    }

    protected function store(): ShortLinkStoreInterface
    {
        $services = $this->container()->get('test.service_container');
        assert($services instanceof ContainerInterface);
        $store = $services->get(ShortLinkStoreInterface::class);
        assert($store instanceof ShortLinkStoreInterface);

        return $store;
    }

    protected function container(): ContainerInterface
    {
        return $this->kernel->getContainer();
    }

    protected function clock(): MockClock
    {
        $clock = $this->container()->get('clock');
        assert($clock instanceof MockClock);

        return $clock;
    }

    protected function listener(): RecordingListener
    {
        $listener = $this->container()->get(RecordingListener::class);
        assert($listener instanceof RecordingListener);

        return $listener;
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    protected function linkCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->host()->tablePrefix() . 'nowo_short_link WHERE tenant_key = ?', [$this->tenant->value]);

        return is_numeric($count) ? (int) $count : -1;
    }

    protected function connection(): Connection
    {
        return $this->host()->connection($this->kernel);
    }

    /**
     * A redirect through `/s/{code}` (click written synchronously).
     */
    protected function click(string $code, string $userAgent, ?string $referer = null): void
    {
        $this->client->request('GET', '/s/' . $code, [], [], ['HTTP_USER_AGENT' => $userAgent] + ($referer === null ? [] : ['HTTP_REFERER' => $referer]));
        self::assertSame(302, $this->httpStatus());
    }

    /**
     * A raw click row with attacker-shaped values (the redirect itself would never store them).
     */
    protected function insertClick(ShortLinkId $id, string $referrer, string $country): void
    {
        $this->connection()->insert($this->host()->tablePrefix() . ClickTablesSchemaListener::CLICK_TABLE, [
            'link_id'       => $id->value,
            'tenant_key'    => $this->tenant->value,
            'clicked_at'    => '2026-11-12 09:00:00',
            'referrer_host' => $referrer,
            'country'       => substr($country, 0, 2),
            'ua_family'     => 'chrome',
            'machine'       => 0,
        ]);
    }

    protected function token(Crawler $crawler, string $name): string
    {
        $token = $crawler->filter('input[name="' . $name . '"]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    protected function csv(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertSame(200, $this->httpStatus());

        return $this->client->getInternalResponse()->getContent();
    }

    /**
     * Data rows of one CSV section (the lines after its header up to the next empty line).
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    protected function section(array $lines, string $header): array
    {
        $rows = [];
        $in   = false;
        foreach ($lines as $line) {
            if ($in && trim($line) === '') {
                break;
            }
            if ($in) {
                $rows[] = $line;
            }
            $in = $in || str_contains($line, $header . ',clicks');
        }

        return $rows;
    }

    abstract protected function setLimit(int $limit): void;

    /**
     * Host configuration of the kernel (Doctrine, security, services).
     *
     * @return Closure(ContainerConfigurator, string): void
     */
    protected function hostConfigure(): Closure
    {
        return $this->host()->configure();
    }
}

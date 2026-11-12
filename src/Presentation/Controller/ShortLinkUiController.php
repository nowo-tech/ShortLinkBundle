<?php

declare(strict_types=1);

namespace Nowo\ShortLinkBundle\Presentation\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nowo\ShortLinkBundle\Application\QuotaReader;
use Nowo\ShortLinkBundle\Application\QuotaUsage;
use Nowo\ShortLinkBundle\Domain\Exception\AccessDeniedException as DomainAccessDenied;
use Nowo\ShortLinkBundle\Domain\Exception\CodeAttemptsExceededException;
use Nowo\ShortLinkBundle\Domain\Exception\CodeTakenException;
use Nowo\ShortLinkBundle\Domain\Exception\InvalidShortLinkArgumentException;
use Nowo\ShortLinkBundle\Domain\Exception\QuotaExceededException;
use Nowo\ShortLinkBundle\Domain\Exception\ShortLinkNotFoundException;
use Nowo\ShortLinkBundle\Domain\Exception\TargetRefusedException;
use Nowo\ShortLinkBundle\Domain\Model\ClickDimension;
use Nowo\ShortLinkBundle\Domain\Model\CreateShortLink;
use Nowo\ShortLinkBundle\Domain\Model\DateRange;
use Nowo\ShortLinkBundle\Domain\Model\LinkKind;
use Nowo\ShortLinkBundle\Domain\Model\ShortCode;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkId;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkListQuery;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkPermission;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkState;
use Nowo\ShortLinkBundle\Domain\Model\ShortLinkView;
use Nowo\ShortLinkBundle\Domain\Model\TenantKey;
use Nowo\ShortLinkBundle\Domain\Model\UpdateShortLink;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkAccessInterface;
use Nowo\ShortLinkBundle\Domain\Port\ShortLinkTenantContextInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkStatsReaderInterface;
use Nowo\ShortLinkBundle\NowoShortLinkBundle;
use Nowo\ShortLinkBundle\Presentation\Csv\StatsCsv;
use Nowo\ShortLinkBundle\Presentation\Form\ShortLinkActionType;
use Nowo\ShortLinkBundle\Presentation\Form\ShortLinkFilterType;
use Nowo\ShortLinkBundle\Presentation\Form\ShortLinkType;
use Nowo\ShortLinkBundle\Presentation\Form\StatsRangeType;
use Nowo\ShortLinkBundle\Presentation\View\DailyChart;
use Nowo\ShortLinkBundle\Presentation\View\ShortLinkPages;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException as SecurityAccessDenied;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_map;
use function in_array;
use function is_scalar;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Management pages (contract §11): list, create, edit, disable / enable / delete, statistics and CSV. Every page
 * resolves the tenant through the tenant-context port (none → 404) and checks the access port: `View` for the pages,
 * `Manage` for every change. Lookups by id are scoped by the tenant, so another tenant's id answers 404, never 403.
 * State changes are POST with a CSRF token per action; the forms never map `system` or `kind` (the UI creates `manual`
 * links). Stateless (worker mode): every value comes from the request and the ports; nothing is kept between requests.
 */
final readonly class ShortLinkUiController
{
    /** Days of the click column of the list. */
    public const LIST_CLICK_DAYS = 30;

    /** Rows of each breakdown on the statistics page. */
    public const BREAKDOWN_LIMIT = 20;

    /** Range presets of the statistics page (days). */
    public const PRESETS = [7, 30, 90];

    private const DOMAIN = NowoShortLinkBundle::TRANSLATION_DOMAIN;

    /**
     * @param string|null $tenantParameter `ui.tenant_route_parameter`: host route parameter kept in every UI link
     * @param int $pageSize `ui.page_size`
     * @param int $statsDefaultDays `ui.stats_default_days`
     * @param int $csvMaxDays `ui.csv_max_days`
     * @param string|null $layout `ui.layout_template`
     * @param list<string> $listedKinds kinds with `show_in_ui: true`: a link of another kind answers 404 (audit NY-S4 L1)
     */
    public function __construct(
        private ShortLinkManagerInterface $manager,
        private ShortLinkStatsReaderInterface $stats,
        private ShortLinkTenantContextInterface $tenants,
        private ShortLinkAccessInterface $access,
        private QuotaReader $quota,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
        private bool $customCodes = true,
        private ?string $tenantParameter = null,
        private int $pageSize = 25,
        private int $statsDefaultDays = 30,
        private int $csvMaxDays = 366,
        private ?string $layout = null,
        private ?ClockInterface $clock = null,
        private array $listedKinds = [LinkKind::MANUAL],
    ) {
    }

    /**
     * List of the tenant's links: filters (search, state), keyset pages of `ui.page_size`, clicks of the last 30 days.
     */
    public function index(Request $request): Response
    {
        $tenant  = $this->tenant(ShortLinkPermission::View);
        $filters = $this->forms->createNamed('', ShortLinkFilterType::class);
        $filters->handleRequest($request);
        $search = null;
        $state  = null;
        if ($filters->isSubmitted() && $filters->isValid()) {
            $search = $this->text($filters->get('q')->getData());
            $state  = ShortLinkState::tryFrom($this->text($filters->get('state')->getData()) ?? '');
        }
        $after = $this->id($this->text($request->query->get('after')) ?? '', false);
        try {
            $query = new ShortLinkListQuery(state: $state, search: $search === null ? null : mb_substr($search, 0, 100), limit: $this->pageSize, after: $after, system: false);
            $page  = $this->manager->list($tenant, $query);
        } catch (DomainAccessDenied $exception) {
            throw $this->denied($exception);
        }
        $ids    = array_map(static fn (ShortLinkView $link): ShortLinkId => $link->id, $page->items);
        $totals = $this->stats->totals($tenant, $ids, DateRange::lastDays(self::LIST_CLICK_DAYS, $this->now()));

        $keep = [];
        foreach (['q' => $search, 'state' => $state?->value] as $key => $value) {
            if ($value !== null && $value !== '') {
                $keep[$key] = $value;
            }
        }
        $pages = $this->pages($request);

        return $this->render('@NowoShortLink/ui/list.html.twig', [
            'page'       => $pages->list($page->items, $totals, $filters->createView(), $keep !== [], $this->canManage($tenant) ? $this->actionForms() : null),
            'next_href'  => $page->next instanceof ShortLinkId ? $this->url($request, 'nowo_short_link_index', $keep + ['after' => $page->next->value]) : null,
            'first_href' => $after instanceof ShortLinkId ? $this->url($request, 'nowo_short_link_index', $keep) : null,
        ]);
    }

    /**
     * Create form (target, optional custom code, title, expiry). Over the plan limit the form is replaced by the
     * quota message.
     */
    public function create(Request $request): Response
    {
        $tenant = $this->tenant(ShortLinkPermission::Manage);
        $kind   = LinkKind::manual();
        $quota  = $this->quota->usage($tenant, $kind);
        $pages  = $this->pages($request);
        if ($quota instanceof QuotaUsage && $quota->isFull()) {
            return $this->render('@NowoShortLink/ui/form.html.twig', ['page' => $pages->form(null, null, $quota), 'quota' => $quota]);
        }
        $form = $this->forms->create(ShortLinkType::class, null, ['custom_code' => $this->customCodes]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{target: string|null, customCode?: string|null, title: string|null, expiresAt: DateTimeImmutable|null} $data */
            $data = $form->getData();
            $code = $this->text($data['customCode'] ?? null);
            if ($this->checkFields($form, $data, $code)) {
                try {
                    // `system` and `kind` are never taken from the request: people create manual links.
                    $link = $this->manager->create($tenant, new CreateShortLink((string) $data['target'], $code, $data['title'], $data['expiresAt'], $kind, system: false));
                    $this->flash($request, 'success', 'ui.flash.created', ['%code%' => $link->code->value]);

                    return $this->redirect($request, 'nowo_short_link_index');
                } catch (DomainAccessDenied $exception) {
                    throw $this->denied($exception);
                } catch (CodeAttemptsExceededException) {
                    $this->fieldError($form, 'customCode', 'ui.error.code_attempts');
                } catch (CodeTakenException) {
                    $this->fieldError($form, 'customCode', 'ui.error.code_unavailable');
                } catch (QuotaExceededException) {
                    $this->formError($form, 'ui.error.quota');
                } catch (TargetRefusedException $exception) {
                    $this->fieldError($form, 'target', 'ui.error.target.' . $exception->refusal->value);
                } catch (InvalidShortLinkArgumentException) {
                    $this->formError($form, 'ui.error.invalid');
                }
            }
        }

        return $this->render('@NowoShortLink/ui/form.html.twig', ['page' => $pages->form($form->createView(), null, $quota), 'quota' => $quota], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * Edit form (target, title, expiry; the code is immutable).
     */
    public function edit(Request $request, string $id): Response
    {
        $tenant = $this->tenant(ShortLinkPermission::Manage);
        $link   = $this->find($tenant, $id);
        $form   = $this->forms->create(ShortLinkType::class, [
            'target'    => $link->target->toString(),
            'title'     => $link->title?->value,
            'expiresAt' => $link->expiry?->at,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{target: string|null, title: string|null, expiresAt: DateTimeImmutable|null} $data */
            $data = $form->getData();
            if ($this->checkFields($form, $data, null)) {
                try {
                    $this->manager->update($tenant, $link->id, new UpdateShortLink((string) $data['target'], $data['title'], $data['expiresAt'], system: false));
                    $this->flash($request, 'success', 'ui.flash.updated', ['%code%' => $link->code->value]);

                    return $this->redirect($request, 'nowo_short_link_index');
                } catch (DomainAccessDenied $exception) {
                    throw $this->denied($exception);
                } catch (ShortLinkNotFoundException $exception) {
                    throw new NotFoundHttpException('Short link not found.', $exception);
                } catch (TargetRefusedException $exception) {
                    $this->fieldError($form, 'target', 'ui.error.target.' . $exception->refusal->value);
                } catch (InvalidShortLinkArgumentException) {
                    $this->formError($form, 'ui.error.invalid');
                }
            }
        }

        return $this->render('@NowoShortLink/ui/form.html.twig', ['page' => $this->pages($request)->form($form->createView(), $link, null), 'quota' => null], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * Disables a link (POST + CSRF).
     */
    public function disable(Request $request, string $id): RedirectResponse
    {
        return $this->change($request, $id, 'disable');
    }

    /**
     * Enables a link again (POST + CSRF); the save guard runs again on its target.
     */
    public function enable(Request $request, string $id): RedirectResponse
    {
        return $this->change($request, $id, 'enable');
    }

    /**
     * Deletes a link (POST + CSRF): a tombstone, its code stays taken for `code.quarantine`.
     */
    public function delete(Request $request, string $id): RedirectResponse
    {
        return $this->change($request, $id, 'delete');
    }

    /**
     * Statistics of one link: totals, daily chart + table, top referrer hosts, countries and browser families.
     */
    public function stats(Request $request, string $id): Response
    {
        $tenant = $this->tenant(ShortLinkPermission::View);
        $link   = $this->find($tenant, $id);
        $form   = $this->forms->createNamed('', StatsRangeType::class);
        $range  = $this->range($request, $form, DateRange::MAX_DAYS);
        $daily  = $this->stats->daily($tenant, $link->id, $range);
        $pages  = $this->pages($request);
        $query  = ['id' => $link->id->value, 'from' => $range->from->format('Y-m-d'), 'to' => $range->to->format('Y-m-d')];

        $breakdowns = [];
        foreach (ShortLinkPages::BREAKDOWNS as $dimension => $title) {
            $breakdowns[$dimension] = $pages->breakdownTable($dimension, $this->stats->breakdown($tenant, $link->id, ClickDimension::from($dimension), $range, self::BREAKDOWN_LIMIT));
        }
        $presets = [];
        foreach (self::PRESETS as $days) {
            $preset    = DateRange::lastDays($days, $this->now());
            $presets[] = [
                'days'   => $days,
                'href'   => $this->url($request, 'nowo_short_link_stats', ['id' => $link->id->value, 'from' => $preset->from->format('Y-m-d'), 'to' => $preset->to->format('Y-m-d')]),
                'active' => $preset->from == $range->from && $preset->to == $range->to,
            ];
        }

        return $this->render('@NowoShortLink/ui/stats.html.twig', [
            'page'        => $pages->stats($link, $range, $daily, $this->canManage($tenant), $this->url($request, 'nowo_short_link_stats_csv', $query)),
            'link'        => $link,
            'range_form'  => $form->createView(),
            'presets'     => $presets,
            'chart'       => new DailyChart($daily),
            'daily_table' => $pages->dailyTable($daily),
            'breakdowns'  => $breakdowns,
        ]);
    }

    /**
     * CSV export of the statistics (`?from=&to=`, at most `ui.csv_max_days` days: a longer range keeps its last days;
     * `?dimension=daily|referrer|country|ua` for one section; `?excel=1` for a UTF-8 BOM). Formula-injection safe.
     */
    public function statsCsv(Request $request, string $id): StreamedResponse
    {
        $tenant    = $this->tenant(ShortLinkPermission::View);
        $link      = $this->find($tenant, $id);
        $range     = $this->range($request, $this->forms->createNamed('', StatsRangeType::class), $this->csvMaxDays);
        $dimension = $this->text($request->query->get('dimension'));
        $daily     = $dimension === null || $dimension === 'daily' ? $this->stats->daily($tenant, $link->id, $range) : null;

        $breakdowns = [];
        foreach (['referrer' => 'referrer', 'country' => 'country', 'ua' => 'browser'] as $name => $section) {
            if ($dimension === null || $dimension === $name) {
                $breakdowns[$section] = $this->stats->breakdown($tenant, $link->id, ClickDimension::from($name), $range, 1000);
            }
        }
        if ($daily === null && $breakdowns === []) {
            throw new NotFoundHttpException('Unknown statistics dimension.');
        }
        $rows = StatsCsv::rows($daily, $breakdowns);
        $bom  = $request->query->getBoolean('excel');
        $file = sprintf('short-link-%s-%s-%s.csv', $link->code->value, $range->from->format('Ymd'), $range->to->format('Ymd'));

        return new StreamedResponse(static function () use ($rows, $bom): void {
            StatsCsv::write($rows, $bom);
        }, Response::HTTP_OK, [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'Content-Disposition'    => sprintf('attachment; filename="%s"', $file),
            'Cache-Control'          => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Disable, enable or delete after the CSRF check.
     */
    private function change(Request $request, string $id, string $action): RedirectResponse
    {
        $tenant = $this->tenant(ShortLinkPermission::Manage);
        $form   = $this->forms->createNamed('', ShortLinkActionType::class, null, ['csrf_token_id' => 'nowo_short_link_' . $action]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $linkId = $this->find($tenant, $id)->id;
        try {
            match ($action) {
                // @igor-ignore - Calls the stateless manager service (a domain verb); nothing is mutated on it.
                'disable' => $this->manager->disable($tenant, $linkId),
                // @igor-ignore - Calls the stateless manager service (a domain verb); nothing is mutated on it.
                'enable' => $this->manager->enable($tenant, $linkId),
                default  => $this->manager->delete($tenant, $linkId),
            };
            $this->flash($request, 'success', 'ui.flash.' . $action . 'd', []);
        } catch (DomainAccessDenied $exception) {
            throw $this->denied($exception);
        } catch (ShortLinkNotFoundException $exception) {
            throw new NotFoundHttpException('Short link not found.', $exception);
        } catch (TargetRefusedException $exception) {
            $this->flash($request, 'error', 'ui.flash.enable_refused', ['%reason%' => $this->translator->trans('ui.error.target.' . $exception->refusal->value, [], self::DOMAIN)]);
        }

        return $this->redirect($request, 'nowo_short_link_index');
    }

    /**
     * The current tenant after the access check: no tenant → 404, not granted → 403 (the host firewall turns it into
     * a login for anonymous visitors).
     */
    private function tenant(ShortLinkPermission $permission): TenantKey
    {
        $tenant = $this->tenants->current() ?? throw new NotFoundHttpException('No short-link tenant in this context.');
        if (!$this->access->isGranted($permission, $tenant)) {
            throw $this->denied(null);
        }

        return $tenant;
    }

    private function canManage(TenantKey $tenant): bool
    {
        return $this->access->isGranted(ShortLinkPermission::Manage, $tenant);
    }

    private function denied(?DomainAccessDenied $previous): AccessDeniedHttpException
    {
        return new AccessDeniedHttpException('Access denied.', new SecurityAccessDenied('Access denied.', $previous));
    }

    /**
     * A live link of the tenant shown in the UI; a malformed id, another tenant's id or a hidden kind → 404.
     */
    private function find(TenantKey $tenant, string $id): ShortLinkView
    {
        $linkId = $this->id($id, true);
        try {
            $link = $linkId instanceof ShortLinkId ? $this->manager->find($tenant, $linkId) : null;
        } catch (DomainAccessDenied $exception) {
            throw $this->denied($exception);
        }

        // Kinds hidden from people (e-mail links) are not reachable by id either.
        if (!$link instanceof ShortLinkView || !in_array($link->kind->value, $this->listedKinds, true)) {
            throw new NotFoundHttpException('Short link not found.');
        }

        return $link;
    }

    private function id(string $value, bool $notFound): ?ShortLinkId
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) !== 1) {
            return $notFound ? throw new NotFoundHttpException('Short link not found.') : null;
        }

        return ShortLinkId::fromString($value);
    }

    /**
     * The range of the statistics: `from` / `to` from the range form (a range longer than `$maxDays` keeps its last
     * days), else the last `ui.stats_default_days` days.
     *
     * @param FormInterface<mixed> $form
     */
    private function range(Request $request, FormInterface $form, int $maxDays): DateRange
    {
        $form->handleRequest($request);
        $from = $form->isSubmitted() && $form->isValid() ? $form->get('from')->getData() : null;
        $to   = $form->isSubmitted() && $form->isValid() ? $form->get('to')->getData() : null;
        if (!$from instanceof DateTimeInterface || !$to instanceof DateTimeInterface || $to < $from) {
            return DateRange::lastDays($this->statsDefaultDays, $this->now());
        }
        $days = (int) $from->diff($to)->days + 1;

        return $days > $maxDays ? DateRange::lastDays($maxDays, $to) : DateRange::of($from, $to);
    }

    /**
     * Checks the fields the value objects would refuse without naming the field (custom code pattern, title length,
     * expiry in the future) and adds field errors. True when nothing was added.
     *
     * @param FormInterface<mixed> $form
     * @param array{target: string|null, title: string|null, expiresAt: DateTimeImmutable|null} $data
     */
    private function checkFields(FormInterface $form, array $data, ?string $code): bool
    {
        if (trim((string) $data['target']) === '') {
            return $this->fieldError($form, 'target', 'ui.error.target_required');
        }
        if ($code !== null && preg_match(ShortCode::CUSTOM_PATTERN, strtolower($code)) !== 1) {
            return $this->fieldError($form, 'customCode', 'ui.error.code_format');
        }
        if ($data['title'] !== null && mb_strlen(trim($data['title'])) > 255) {
            return $this->fieldError($form, 'title', 'ui.error.title_length');
        }
        if ($data['expiresAt'] instanceof DateTimeImmutable && $data['expiresAt'] <= $this->now()) {
            return $this->fieldError($form, 'expiresAt', 'ui.error.expiry_past');
        }

        return true;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function fieldError(FormInterface $form, string $field, string $key): bool
    {
        $form->get($field)->addError(new FormError($this->translator->trans($key, [], self::DOMAIN)));

        return false;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function formError(FormInterface $form, string $key): void
    {
        $form->addError(new FormError($this->translator->trans($key, [], self::DOMAIN)));
    }

    /**
     * One CSRF-only form per row action, shared by every row.
     *
     * @return array{disable: FormView, enable: FormView, delete: FormView}
     */
    private function actionForms(): array
    {
        $forms = [];
        foreach (['disable', 'enable', 'delete'] as $action) {
            $forms[$action] = $this->forms->createNamed('', ShortLinkActionType::class, null, ['csrf_token_id' => 'nowo_short_link_' . $action])->createView();
        }

        return $forms;
    }

    private function pages(Request $request): ShortLinkPages
    {
        return new ShortLinkPages(fn (string $route, array $params = []): string => $this->url($request, $route, $params), $this->layout);
    }

    /**
     * A UI URL that keeps the host's tenant route parameter (`ui.tenant_route_parameter`, Beacon: `uuid`).
     *
     * @param array<string, string> $params
     */
    private function url(Request $request, string $route, array $params = []): string
    {
        if ($this->tenantParameter !== null) {
            $value = $request->attributes->get($this->tenantParameter);
            if (is_scalar($value)) {
                $params += [$this->tenantParameter => (string) $value];
            }
        }

        return $this->router->generate($route, $params);
    }

    private function redirect(Request $request, string $route): RedirectResponse
    {
        return new RedirectResponse($this->url($request, $route), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $params
     */
    private function flash(Request $request, string $type, string $key, array $params): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->translator->trans($key, $params, self::DOMAIN));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render($template, $context), $status);
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}

# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Validation](#validation)
- [FormKit profile](#formkit-profile)
- [Container parameters](#container-parameters)
- [Symfony Beacon example](#symfony-beacon-example)

The tree is strict (REQ-SF-006): unknown keys and invalid values are refused at container build, and the message names
the path and the allowed values. Every key of the contract exists from S0; S1 wires the manager, the ports and the store (no route before S2).

## Full reference

```yaml
nowo_short_link:
    public_base_url: null            # absolute http(s) URL, e.g. '%env(DEFAULT_URI)%'; null or '' = the router request context
    redirect:
        path_prefix: /s              # absolute, no trailing slash, never "/"
        status: 302                  # 302 | 307 (301 / 308 refused: browsers cache them forever)
        not_found_template: '@NowoShortLink/redirect/not_found.html.twig'
        rate_limit:
            enabled: true
            cache_pool: cache.app    # give a fast shared pool (Redis / APCu); Beacon: cache.rate_limiter
            ipv6_prefix: 64          # 56..64: IPv6 clients are limited per network (IPv4 per address)
            misses: { limit: 20, interval: '1 minute' }   # unknown / disabled / expired / deleted codes, per IP
            hits:   { limit: 120, interval: '1 minute' }  # active links, per IP
            over_limit_hits: redirect_unlogged            # redirect_unlogged | block
    code:
        random_length: 7             # 6..16
        allow_custom: true
        reserved: []                 # added to the built-in list, case-insensitive, [a-z0-9-]{1,32}
        blocklist: true              # generated codes skip offensive substrings
        max_attempts: 5              # 1..20 collisions, then length + 1
        quarantine: P365D            # ISO 8601 duration; null = deleted codes stay taken forever
        custom_code_rate_limit:      # per-person limit on custom-code lookups (audits L7 / NY-S4), in redirect.rate_limit.cache_pool
            enabled: true
            limit: 10                # 1..100000 lookups per tenant + actor (free codes count too: the attempt is taken first)
            interval: '1 minute'     # then no custom code is looked up until the window ends
            limiter: null            # CustomCodeLimiterInterface service id; null = symfony/rate-limiter (atomic with symfony/lock)
    target:
        max_length: 2048             # 64..2048
        guard: auto                  # auto (basic + OutboundUrlGuard) | basic | <service id>: a service REPLACES the whole chain (self chain, OutboundUrlGuard, denylist)
        recheck_max_unresolvable_percent: 20  # 0..100 of all checked targets unresolvable → the recheck aborts, checked after every link (0 = on any, once recheck_min_sample links were checked)
        recheck_min_sample: 20       # the threshold applies once this many links were checked
        save_resolve_dns: true       # DNS check on create / update / enable / recheck, never on the redirect
        self_paths: ['/s/']          # targets on own_hosts under these paths are refused (no chains)
        own_hosts: []                # host names, lowercased
        denylist:
            hosts: []                # exact host or ".suffix"
            provider: null           # TargetDenylistInterface service id; null = the hosts list
    click_log:
        enabled: true
        write: terminate             # terminate | messenger | sync
        store: { ip_prefix: true, user_agent_family: true, referrer_host: true, country: true }
        ipv4_prefix: 24              # 16..32
        ipv6_prefix: 48              # 32..64
        country_resolver: null       # ClickCountryResolverInterface service (local lookup only); refused with write: messenger
        honour_gpc: true             # Sec-GPC: 1 → count only
        retention: P90D              # raw rows; null = keep
        rollup: { lag_days: 2, referrer_top_n: 50, retention: null }
    tenancy:
        context: null                # ShortLinkTenantContextInterface service id; null = single tenant "default"
    access:
        provider: null               # ShortLinkAccessInterface service id; null = role-based
        view_role: ROLE_ADMIN
        manage_role: ROLE_ADMIN
        enforce_in_manager: true
    quota:
        provider: null               # ShortLinkQuotaInterface service id; null = default_limit
        default_limit: null          # integer >= 0; null (or ~) = unlimited
        counted_kinds: [manual]      # only configured kinds
    kinds:                           # manual and email are built in; add yours (^[a-z][a-z0-9_]{0,31}$)
        email: { allow_custom_code: false, show_in_ui: false, counted: false }
    ui:
        enabled: false
        path_prefix: /short-links
        tenant_route_parameter: null # route parameter of the host prefix kept in UI links (Beacon: uuid)
        page_size: 25                # 10..100
        stats_default_days: 30       # 1..366
        csv_max_days: 366            # 1..366: a longer CSV range keeps its last days
        layout_template: null        # layout of the UI pages; null = the UiKit admin layout (nowo_ui_kit.layouts.admin)
    doctrine:
        entity_manager: null         # null = default EntityManager; dedicated one recommended for DDD hosts
        connection: null             # DBAL connection of the click tables; null = the EntityManager's
        table_prefix: ''             # '' or [a-z][a-z0-9_]{0,29}
        mapping: xml                 # xml | attribute
```

A host entry for a built-in kind keeps the built-in values of the flags it leaves out (`kinds: {email: {show_in_ui:
true}}` keeps `allow_custom_code: false` and `counted: false`). A kind is counted by the quota only when it is listed in
`quota.counted_kinds` **and** its `counted` flag is true.

## Validation

- `public_base_url`: absolute `http` / `https` URL with a host, no userinfo, query or fragment; an env placeholder is
  accepted and re-checked at runtime.
- `redirect.path_prefix` and `ui.path_prefix`: absolute, no trailing slash, not `/`, and different from each other.
- `code.reserved`: lowercased, `[a-z0-9-]{1,32}`. The bundle also refuses custom codes starting with `_` or `.` (they
  never match the custom-code pattern).
- Durations (`code.quarantine`, `click_log.retention`, `click_log.rollup.retention`): ISO 8601 (`P90D`, `P1Y`, `PT12H`)
  or null.
- `click_log.retention` must be at least `click_log.rollup.lag_days` + 1 days (the rollup recomputes those days from
  raw rows), and `click_log.rollup.retention`, when set, at least `click_log.retention`.
- `tenancy.context` needs `access.provider`: the default role-based access grants the same role in every tenant, so a
  multi-tenant host must give an access port that checks the tenant (the container refuses to build otherwise).
- `REQ-CFG-001` (profiles) does not apply: one instance-wide configuration; per-tenant variation goes through the ports.

## FormKit profile

The UI forms use the FormKit profile `short_link`, prepended by the bundle (`translation_domain: NowoShortLinkBundle`,
`attr.class: nowo-ui-input`). Define `nowo_form_kit.profiles.short_link` yourself to replace it (yours wins).

## Container parameters

| Parameter | Value |
| --- | --- |
| `nowo_short_link.redirect_path_prefix` | `redirect.path_prefix` (e.g. for a stateless firewall pattern) |
| `nowo_short_link.ui_enabled` | `ui.enabled` |
| `nowo_short_link.ui_path_prefix` | `ui.path_prefix` |
| `nowo_short_link.table_prefix` | `doctrine.table_prefix` |
| `nowo_short_link.config` | The processed tree |

## Symfony Beacon example

From the Beacon answers (contract §17):

```yaml
nowo_short_link:
    public_base_url: '%env(DEFAULT_URI)%'
    redirect:
        rate_limit:
            cache_pool: cache.rate_limiter
    code:
        reserved: [account, accounts, admin, api, autocomplete, billing, build, chat, dashboard, developer, files, health,
                   hooks, invitation, legal, locale, login, logout, manual, mcp, metrics, offline, organization,
                   organizations, projects, register, reset-password, search, secret, settings, setup, share, status,
                   tasks, time, time-track, tools, uptime, uptime-push, wiki, working-time, beacon, nowo, nowo-tech, s, e]
    target:
        own_hosts: ['beacon.example']
        denylist:
            provider: App\ShortLink\InstanceSettingsDenylist
    tenancy:
        context: App\ShortLink\OrganizationTenantContext
    access:
        provider: App\ShortLink\OrganizationShortLinkAccess
    quota:
        provider: App\ShortLink\PlanShortLinkQuota
    ui:
        enabled: true
        tenant_route_parameter: uuid
```

# Short-link bundle (ShortLinkBundle). Contract v1.0 for symfony-beacon spec 230 Block A

Status 2026-11-12: **v1.0.** The owner answered Q-O1 to Q-O15 (§15) and Symfony Beacon answered Q-B1 to Q-B13 (§16);
the answers are recorded in §17. The slices of §13 build the bundle; `docs/ROADMAP.md` tracks them.

- Source: Beacon spec `230-short-links-and-email-tracking` (Symfony Beacon, private)
  Block A (FR-001 to FR-003, SC-002, SC-003 redirect half, SC-004), task T002, owner decision **D-20261112-16**
  (`docs/DECISIONS.md`: public short links per organization, targets through OutboundUrlGuard, plan module with a
  limit, truncated IP, UA family, 90-day retention, "bundles first").
- Beacon request (relayed by the coordinator 2026-11-12): multitenant by tenant key; random base62 code of 7 or custom
  `[a-z0-9-]{3,32}` unique per instance; reserved words; kinds (`manual`, `email` with send id and link label /
  position); public `GET /s/{code}`; click log + daily rollup + P90D purge; stats API; host access port (view / manage
  per tenant); embeddable UI on UiKit + FormKit (list, create / edit, stats with chart, CSV); plan-limit hook; p95 < 20 ms
  on the redirect; GDPR.
- Block B (pixel, link rewriting) is **not** in this bundle: it lives in MailKitBundle's tracking ports (T001). This
  bundle only gives MailKit / Beacon a PHP API to create `email`-kind links in bulk and to read their clicks per send.
- House bars from the first PR: REQ-ARCH-001 (standard Symfony default, DDD opt-in, rule 3 strict: own VO per port, no
  Symfony / Doctrine / HTTP types in public ports, events or VOs), REQ-RUNTIME-002 (FrankenPHP worker, kernel reset
  false), 100 % of the applicable REQs of `BUNDLES_FULL_SPECS_DETAILS.md` before 1.0.0 (`BUNDLES_FIX_RULES.md`, "New
  bundles"), the identifier-column rule (binary collation + PHP re-check, audit LQ), the demo bar (UiKit base style +
  FormKit forms, REQ-DEMO-014/015), and the 221 conventions accepted 2026-11-12 (UUIDv7 string(36) binary ids, XML
  mapping default + attribute twin, public HTTP APIs versioned and disabled by default).

## 0. Findings that shaped the contract

| # | Finding | Effect |
|---|---|---|
| F1 | OutboundUrlGuardBundle 1.5.0 offers `inspect(?string $url, ?bool $allowPrivate, ?bool $resolveDns)`, `assertSafe()`, `httpClientOptions()`. It has **no denylist** and its DNS lookup has a 2 s timeout | DNS can never run on the redirect path (p95 < 20 ms). Full guard **with DNS at save time**; on the redirect only scheme / literal-IP / metadata checks (`resolveDns: false`) + the bundle's denylist port. A periodic `recheck` command covers DNS drift (Q-O12). The denylist is this bundle's own port (`TargetDenylistInterface`) |
| F2 | The threat is not SSRF (the server never fetches the target; the *browser* follows the 302) but open redirect / phishing under a trusted domain, intranet pivoting (a public link to `http://192.168.1.1/...` on the visitor's LAN) and enumeration | Guard keeps "no private / link-local" for the browser-pivot case; abuse is bounded by permissions, plan limits, the denylist, audit events and instant disable |
| F3 | Random base62 codes are **case-sensitive**. On MySQL with the default `utf8mb4_*_ci` collation `aB3xY9k` and `AB3XY9K` are the same key: collisions on insert and a lookup that answers another link | `code` column `utf8mb4_bin` (as rule LQ for ids), exact PHP re-check `===` on the row returned, test on MySQL 8 + MariaDB |
| F4 | UiKitBundle 2.49 has no chart component (ROADMAP: "charts: product"); FormKit 2.22 has the form types | Chart: a generic server-side SVG bar chart in UiKit (Q-O5), with a table fallback until it is released |
| F5 | HttpLog's 1.16 rollup contract already solved rollup + purge + per-tenant erasure with DBAL tables and UTC days | Same shape here (DBAL click tables, UTC days, recompute-a-day rollup, keyset purge); simpler, because raw rows exist for 90 days so a day can be recomputed from them instead of an exactly-once ledger |
| F6 | TaskBoard's tenancy keeps the tenant per request on `Request` attributes and stores `tenant_key VARCHAR(191)` | Same idea, but the public port is framework-free (rule 3): `ShortLinkTenantContextInterface::current(): ?TenantKey`; the host's implementation reads its own `RequestStack` per call. Key capped at 64 like HttpLog |
| F7 | Email security scanners (Microsoft Safe Links, Proofpoint, Mimecast) and chat previews (Slackbot, Twitterbot, WhatsApp) fetch links before people do | `machine` flag on clicks, shown apart (same idea as spec 230's *machine opens*); HEAD is never logged |
| F8 | A deleted code reused by another tenant inherits every printed flyer, QR code and old e-mail that carries it | Tombstones: deleted codes stay reserved for a quarantine period (Q-O7) |
| F9 | `Referrer-Policy: no-referrer` on the 302 stops the target from seeing the short URL; the *incoming* `Referer` (the page where the short link was clicked) is what we log, host only | Inbound referrer host stored (≤ 253 chars, IDN → punycode, lowercased), never path or query |

## 1. Package

| Item | Proposal |
|---|---|
| Name | **`ShortLinkBundle`** (recommended, Q-O1). Alternatives: `ShortLinkKitBundle` (the "Kit" suffix is used for toolboxes spanning several features: UiKit, FormKit, AuthKit, NotifyKit, ReportKit; this is one feature, like Wiki, Vault, HttpLog, UptimeMonitor), `LinkShortenerBundle` (verb-ish, longer), `UrlShortenerBundle` (generic, collides in search with many third-party packages). Avoid plain `LinkBundle` (confusable with `UxLinkBundle`) |
| Composer | `nowo-tech/short-link-bundle` |
| GitHub | `nowo-tech/ShortLinkBundle` (REQ-DOCS-014), public, MIT; owner registers Packagist once S0 is on `main` |
| Namespace / class | `Nowo\ShortLinkBundle` / `NowoShortLinkBundle`; config root `nowo_short_link`; Twig namespace `@NowoShortLink` (REQ-TWIG-002); translation domain `NowoShortLinkBundle` (REQ-I18N-003) |
| First tag | 0.x per slice for Beacon integration, **1.0.0** after audit (Q-O2) |
| PHP / Symfony | `>=8.2 <8.6`; `^7.4 \|\| ^8.0` (REQ-SF-001); demos Symfony 7.4 + 8.1 on FrankenPHP worker (REQ-DEMO-002/010/012) |
| Runtime deps | `doctrine/dbal ^3.8.2 \|\| ^4.0`, `doctrine/orm ^3.7`, `doctrine/doctrine-bundle ^2.8 \|\| ^3.0`, `symfony/{framework-bundle,http-kernel,routing,config,dependency-injection,event-dispatcher,clock,console,form,validator,translation,twig-bundle,rate-limiter,uid}`, `symfony/security-bundle` (default role-based access), `twig/extra-bundle ^3.12`, `nowo-tech/ui-kit-bundle ^2.49` and `nowo-tech/form-kit-bundle ^2.22` (Q-O4), `nowo-tech/outbound-url-guard-bundle ^1.5` (Q-O3), `psr/log`, `psr/clock` |
| Suggest | `symfony/messenger` (click write mode `messenger`), `symfony/lock` (exact plan limit under concurrency) |
| Not used | No HTTP client (the bundle never fetches a target), no GeoIP library (country via port), no UA-parser library (own small classifier, §7.3), no JS build (REQ-ASSETS-* N/A unless UiKit lacks a copy button, §11) |

## 2. Shape

```
Visitor ──GET /s/{code}──▶ RedirectController (stateless, no session, no security user needed)
                               │ 1 rate limiter (misses / hits)        ─▶ symfony/rate-limiter on a host cache pool
                               │ 2 lookup by code (1 indexed DBAL row) ─▶ ResolvedLink VO
                               │ 3 state: active, not expired, not deleted
                               │ 4 redirect guard: scheme + literal IP + denylist (no DNS)
                               ▼
                         302 Location: target  |  neutral 404 / 429
                               │ after the response (kernel.terminate) or via Messenger
                               ▼
                         ClickRecorder ─▶ nowo_short_link_click (truncated IP, UA family, referrer host, country?, machine)
                                          └─▶ ShortLinkClickedEvent (ids only)

Host UI (opt-in routes, UiKit scaffolds + FormKit) ─▶ ShortLinkManager (create / update / disable / enable / delete)
                                                      ├─ TenantContext, Access, Quota ports (host)
                                                      ├─ save guard: basic + OutboundUrlGuard with DNS + denylist
                                                      └─ events after commit (ids)
Console: rollup (daily) · purge (raw P90D, tombstones) · recheck (targets)  ─▶ nowo_short_link_click_daily
Stats: ShortLinkStatsReaderInterface (PHP) ─▶ UI page with chart, CSV export; Beacon E-mail log per send
```

The bundle owns codes, targets, the redirect, the click log, rollups, purge and the UI. The host owns tenants, who may
view / manage, plan limits, the denylist source, the country lookup, the cache pool and where the UI is mounted.

## 3. Public API (framework-free domain; layered namespaces)

```
src/Domain/        VOs, enums, ports (interfaces), events, exceptions — no Symfony / Doctrine / HTTP types
src/Application/   ShortLinkManager, ShortLinkStatsReader, CodeGenerator, ClickRecorder (use the ports)
src/Infrastructure Doctrine (entity, XML + attribute mapping, DBAL tables, repositories), Symfony (controller,
                   forms, commands, rate limiter, guard bridge, default port implementations)
src/Presentation/  UI controllers, view models, Twig
```

### 3.1 Value objects (`Nowo\ShortLinkBundle\Domain\…`, all `final readonly`, validated in the constructor)

| VO | Rule |
|---|---|
| `ShortLinkId` | UUIDv7 as a lowercase 36-char string (221 convention); `fromString()`, `generate()` via a `ShortLinkIdGeneratorInterface` (default `symfony/uid`, behind the port) |
| `TenantKey` | `^[A-Za-z0-9._:-]{1,64}$`, exact (no trim, no case folding). `TenantKey::default()` = `default` for single-tenant hosts |
| `ShortCode` | `random(string)`: `^[A-Za-z0-9]{6,16}$`; `custom(string)`: `^[a-z0-9](?:[a-z0-9]\|-(?!-)){1,30}[a-z0-9]$` (3–32, no leading / trailing / double hyphen, Q-O8). `isCustom()`. Compared with `===` |
| `TargetUrl` | Absolute `http`/`https`, ≤ 2 048 bytes after normalisation; host lowercased, IDN → punycode (`idn_to_ascii` UTS46), default port dropped, no userinfo (`user:pass@` refused), no control / whitespace characters, fragment allowed. `host()`, `toString()` |
| `LinkKind` | `^[a-z][a-z0-9_]{0,31}$`; constants `MANUAL = 'manual'`, `EMAIL = 'email'`; hosts may add kinds via config `kinds` |
| `LinkSource` | Optional, for generated links: `ref` (send id etc., `^[A-Za-z0-9._:-]{1,64}$`), `label` (≤ 128 chars, control chars stripped), `position` (0–999) |
| `LinkMetadata` | ≤ 16 keys `^[a-z][a-z0-9_]{0,31}$`, scalar or null values, ≤ 2 KiB JSON; never shown unescaped |
| `ActorRef` | Opaque creator / editor id from the host, ≤ 128 chars, exact (rule LQ); nullable everywhere |
| `LinkTitle` | ≤ 255 chars, trimmed, control chars stripped |
| `Expiry` | `?DateTimeImmutable` in UTC, must be in the future at create / update |
| `ClientIpPrefix` | Built from a raw IP: IPv4 `/24`, IPv6 `/48` (config), stored as `203.0.113.0/24`, `2001:db8:1234::/48`; the raw IP never leaves the request |
| `UserAgentFamily` | Enum-like: `chrome`, `firefox`, `safari`, `edge`, `opera`, `samsung`, `other_browser`, `bot`, `preview`, `scanner`, `unknown`; `isMachine()` for the last four except `unknown` |
| `ReferrerHost` | Lowercased punycode host ≤ 253, or null; never path / query |
| `CountryCode` | ISO 3166-1 alpha-2 uppercase, or null |
| `ResolvedLink` | Read model of the hot path: id, tenant, code, target, kind, state (`Active`, `Disabled`, `Expired`, `Deleted`) |
| `ShortLinkView` | Read model for lists and the host: every column except metadata internals, plus `shortUrl` |
| `DailyClicks`, `DimensionClicks`, `LinkClickTotals`, `DateRange` | Stats read models (days in UTC, `DateRange` ≤ 366 days) |

Enums: `ShortLinkPermission { View; Manage }`, `ShortLinkState`, `ClickDimension { Referrer; Country; UaFamily }`,
`GuardStage { Save; Redirect; Recheck }`, `TargetRefusal { Scheme; Userinfo; Length; PrivateHost; Metadata;
Unresolvable; Denylisted; SelfLoop }`, `ClickWriteMode { Terminate; Messenger; Sync }`.

### 3.2 Ports the host implements (all optional; default in brackets)

```php
namespace Nowo\ShortLinkBundle\Domain\Port;

interface ShortLinkTenantContextInterface   // [SingleTenantContext: TenantKey::default()]
{
    /** Tenant of the current UI / API call; null = none (UI answers 404). Never cached in the service. */
    public function current(): ?TenantKey;
}

interface ShortLinkAccessInterface          // [RoleBasedAccess: config access.view_role / manage_role via AuthorizationChecker]
{
    public function isGranted(ShortLinkPermission $permission, TenantKey $tenant): bool;
    public function currentActor(): ?ActorRef;  // recorded as created_by / updated_by
}

interface ShortLinkQuotaInterface           // [ConfigQuota: quota.default_limit, null = unlimited]
{
    /** Max non-deleted links of this kind for the tenant; null = unlimited. Only kinds in quota.counted_kinds are asked. */
    public function limitFor(TenantKey $tenant, LinkKind $kind): ?int;
}

interface TargetGuardInterface              // [ChainTargetGuard: BasicTargetGuard + OutboundUrlGuardBridge]
{
    public function check(TargetUrl $target, GuardStage $stage): TargetVerdict;  // TargetVerdict{allowed, ?TargetRefusal}
}

interface TargetDenylistInterface           // [ConfigDenylist: denylist.hosts, exact or ".suffix" match]
{
    /** Must be cheap on GuardStage::Redirect (in-memory or a host cache); may be slower on Save / Recheck. */
    public function isDenied(TargetUrl $target, GuardStage $stage): bool;
}

interface ClickCountryResolverInterface     // [NullCountryResolver]
{
    /** Receives the raw IP after the response is sent; must not store it. Timeout ≤ 50 ms (REQ-RUNTIME-001). */
    public function countryFor(string $clientIp): ?CountryCode;
}

interface UserAgentClassifierInterface      // [BuiltInUserAgentClassifier, §7.3]
{
    public function classify(?string $userAgent): UserAgentFamily;
}

interface ReservedCodeProviderInterface     // [ConfigReservedCodes: built-in list + code.reserved]
{
    public function isReserved(string $lowercasedCode): bool;
}

interface CodeBlocklistInterface            // [BuiltInCodeBlocklist: small en + es offensive substrings, Q-O9]
{
    public function isBlocked(string $lowercasedCode): bool;
}
```

`ClientIp` stays a plain `string` only inside `ClickCountryResolverInterface` (it is the port's whole purpose); it is
never stored. Every port is a public alias so hosts can decorate it.

### 3.3 Services the host calls

```php
interface ShortLinkManagerInterface
{
    /** @throws QuotaExceeded|CodeTaken|CodeReserved|TargetRefused|AccessDenied */
    public function create(TenantKey $tenant, CreateShortLink $command): ShortLinkView;
    /** Bulk for generated links (email kind): one transaction, random codes only, quota checked once for the batch.
     *  @param list<CreateShortLink> $commands ≤ 200 */
    public function createMany(TenantKey $tenant, array $commands): array;    // list<ShortLinkView>, same order
    public function update(TenantKey $tenant, ShortLinkId $id, UpdateShortLink $command): ShortLinkView;  // target, title, expiry; code is immutable
    public function disable(TenantKey $tenant, ShortLinkId $id): void;
    public function enable(TenantKey $tenant, ShortLinkId $id): void;          // re-runs the save guard
    public function delete(TenantKey $tenant, ShortLinkId $id): void;          // tombstone (Q-O7)
    public function find(TenantKey $tenant, ShortLinkId $id): ?ShortLinkView;  // another tenant's id → null
    /** @return ShortLinkPage keyset-paginated, filters: kind, state, search (code / title / target host) */
    public function list(TenantKey $tenant, ShortLinkListQuery $query): ShortLinkPage;
    public function shortUrl(ShortCode $code): string;                         // absolute, from public_base_url or the router context
}

interface ShortLinkStatsReaderInterface
{
    public function daily(TenantKey $tenant, ShortLinkId $id, DateRange $range): array;           // list<DailyClicks> (human + machine)
    public function breakdown(TenantKey $tenant, ShortLinkId $id, ClickDimension $dim, DateRange $range, int $limit = 20): array;
    public function totals(TenantKey $tenant, array $ids, ?DateRange $range = null): array;       // map id => LinkClickTotals (list column, one grouped query)
    /** Email log: clicks per link of one source (send). */
    public function bySource(TenantKey $tenant, LinkKind $kind, string $sourceRef): array;        // list<LinkClickTotals + label + position + first/last click>
}

interface ShortLinkEraserInterface   // GDPR and tenant lifecycle
{
    public function eraseTenant(TenantKey $tenant): int;                        // clicks + rollups deleted, links blanked tombstones (§10)
    public function eraseSource(TenantKey $tenant, LinkKind $kind, string $sourceRef): int;   // a send's links' clicks (rows and rollups); links tombstoned
    public function anonymiseActor(ActorRef $actor): int;                       // created_by / updated_by → null
    public function exportSource(TenantKey $tenant, LinkKind $kind, string $sourceRef): array; // personal-data export rows
}
```

Every method takes the tenant explicitly and every query filters on it (another tenant's id answers as missing). The UI
controllers pass `ShortLinkTenantContextInterface::current()` and check `ShortLinkAccessInterface` before calling;
the manager checks access again when `access.enforce_in_manager: true` (default true for UI calls; programmatic callers
such as MailKit pass a system flag via `CreateShortLink::$system`, documented).

### 3.4 Events (`Nowo\ShortLinkBundle\Domain\Event`, dispatched after commit, ids and VOs only)

| Event | Fields |
|---|---|
| `ShortLinkCreatedEvent` | id, tenant, code, kind, target host, actor, system |
| `ShortLinkUpdatedEvent` | id, tenant, changed field names (`target`, `title`, `expires_at`), old / new target host, actor |
| `ShortLinkStateChangedEvent` | id, tenant, from, to (`Active` / `Disabled`), reason (`manual`, `recheck_refused`, `expired` is not an event), actor |
| `ShortLinkDeletedEvent` | id, tenant, code, actor |
| `ShortLinkClickedEvent` | id, tenant, kind, source ref, occurredAt, machine, ua family, country (no IP, no referrer). Dispatched by the recorder after the click row is written (after the response) |
| `ShortLinkTargetRefusedEvent` | tenant, stage, refusal, target host (no full URL), id when known. For abuse monitoring |

Beacon's audit log subscribes to them (Q-B9). Events are plain classes (no Symfony `Event` base in the domain; the
dispatcher bridge wraps them).

### 3.5 Routes

| Route | Method | Path (default) | Notes |
|---|---|---|---|
| `nowo_short_link_redirect` | GET, HEAD | `{redirect.path_prefix}/{code}` = `/s/{code}` | `code` requirement `[A-Za-z0-9-]{3,32}`; `stateless: true`; outside any locale prefix; other methods 405 `Allow: GET, HEAD` |
| `nowo_short_link_index` | GET | `{ui.path_prefix}` = `/short-links` | Opt-in (`ui.enabled`); host imports `@NowoShortLinkBundle/config/routes/ui.php` under its own prefix, e.g. `/organizations/{organization}/short-links` |
| `…_new`, `…_edit` | GET, POST | `/new`, `/{id}/edit` | FormKit forms, CSRF |
| `…_disable`, `…_enable`, `…_delete` | POST | `/{id}/disable` … | CSRF token per action |
| `…_stats` | GET | `/{id}/stats?from=&to=` | Chart + breakdowns |
| `…_stats_csv` | GET | `/{id}/stats.csv?from=&to=` | Streamed |

The redirect path is a public, unversioned path by nature (a link printed on paper): under the owner's 2026-11-10 API
rule it is a **protocol path, not an API**, the same exemption class as `/mcp`; documented. No public HTTP JSON API in
1.0 (Q-O13).

## 4. Configuration (strict tree, REQ-SF-006)

```yaml
nowo_short_link:
    public_base_url: null            # e.g. https://beacon.example; required to build short URLs outside a request (Messenger, CLI)
    redirect:
        path_prefix: /s
        status: 302                  # 302 | 307; 301/308 refused (browsers cache them forever)
        not_found_template: '@NowoShortLink/redirect/not_found.html.twig'
        rate_limit:
            enabled: true
            cache_pool: cache.app    # host should give a fast shared pool (Redis / APCu), see Q-B5
            misses: { limit: 20, interval: '1 minute' }     # unknown / disabled / expired / deleted codes, per IP
            hits:   { limit: 120, interval: '1 minute' }    # existing active links, per IP
            over_limit_hits: redirect_unlogged              # redirect_unlogged | block (Q-O6)
    code:
        random_length: 7             # 6..16
        allow_custom: true
        reserved: []                 # added to the built-in list (§6.3)
        blocklist: true              # generated codes skip offensive substrings (Q-O9)
        max_attempts: 5              # then length + 1, logged
        quarantine: P365D            # deleted codes stay taken (Q-O7); null = forever
    target:
        max_length: 2048
        guard: auto                  # auto (basic + OutboundUrlGuard) | basic | <service id>
        save_resolve_dns: true       # DNS check on create / update / enable / recheck
        self_paths: ['/s/']          # targets on own hosts under these paths are refused (no chains)
        own_hosts: []                # hosts treated as "self" for self_paths
        denylist: { hosts: [] }      # default denylist implementation
    click_log:
        enabled: true
        write: terminate             # terminate | messenger | sync (Q-O11)
        store: { ip_prefix: true, user_agent_family: true, referrer_host: true, country: true }
        ipv4_prefix: 24              # 16..32
        ipv6_prefix: 48              # 32..64
        honour_gpc: true             # Sec-GPC: 1 → count only (Q-O10)
        retention: P90D              # raw rows; null = keep (not recommended)
        rollup: { lag_days: 2, referrer_top_n: 50, retention: null }
    tenancy:
        context: null                # service id of ShortLinkTenantContextInterface; null = single tenant "default"
    access:
        provider: null               # service id; null = role-based
        view_role: ROLE_ADMIN
        manage_role: ROLE_ADMIN
        enforce_in_manager: true
    quota:
        provider: null
        default_limit: null
        counted_kinds: [manual]
    kinds:                           # extra kinds; manual and email are built in
        email: { allow_custom_code: false, show_in_ui: false, counted: false }
    ui:
        enabled: false
        path_prefix: /short-links
        page_size: 25                # 10..100 (REQ-PERF-001)
        stats_default_days: 30
        csv_max_days: 366
    doctrine:
        entity_manager: null         # null = default EM; a dedicated EM recommended for DDD hosts
        connection: null             # DBAL connection of the click tables; null = the EM's
        table_prefix: ''             # REQ-ORM-002
        mapping: xml                 # xml | attribute (221 convention: XML default, attribute twin)
```

`REQ-CFG-001` (profiles) is **N/A**: one instance-wide configuration, per-tenant variation goes through ports (recorded
in the audit report). Container validation: `public_base_url` must be an absolute http(s) URL; `redirect.path_prefix`
must start with `/` and must not be `/`; `ui.path_prefix` ≠ `redirect.path_prefix`.

## 5. Data model (bundle-owned; the host references links by id)

### 5.1 `nowo_short_link` (ORM entity `ShortLink`, XML mapping default + attribute twin)

```sql
CREATE TABLE nowo_short_link (
  id               VARCHAR(36)   NOT NULL COLLATE utf8mb4_bin,   -- UUIDv7 string
  tenant_key       VARCHAR(64)   NOT NULL COLLATE utf8mb4_bin,
  code             VARCHAR(32)   NOT NULL COLLATE utf8mb4_bin,   -- F3: case-sensitive, exact
  code_custom      BOOLEAN       NOT NULL DEFAULT FALSE,
  target_url       VARCHAR(2048) NOT NULL,
  target_host      VARCHAR(253)  NOT NULL,                       -- for search, denylist sweeps, events
  title            VARCHAR(255)  DEFAULT NULL,
  kind             VARCHAR(32)   NOT NULL DEFAULT 'manual',
  source_ref       VARCHAR(64)   DEFAULT NULL COLLATE utf8mb4_bin,
  source_label     VARCHAR(128)  DEFAULT NULL,
  source_position  SMALLINT      DEFAULT NULL,
  metadata         JSON          DEFAULT NULL,
  active           BOOLEAN       NOT NULL DEFAULT TRUE,
  expires_at       DATETIME      DEFAULT NULL,                   -- UTC (datetime_immutable)
  disabled_reason  VARCHAR(32)   DEFAULT NULL,                   -- manual | recheck_refused
  created_at       DATETIME      NOT NULL,
  updated_at       DATETIME      NOT NULL,
  deleted_at       DATETIME      DEFAULT NULL,                   -- tombstone (Q-O7)
  created_by       VARCHAR(128)  DEFAULT NULL COLLATE utf8mb4_bin,
  updated_by       VARCHAR(128)  DEFAULT NULL COLLATE utf8mb4_bin,
  PRIMARY KEY (id),
  UNIQUE INDEX uniq_short_link_code (code),
  INDEX idx_short_link_tenant_list (tenant_key, kind, deleted_at, created_at),
  INDEX idx_short_link_tenant_source (tenant_key, kind, source_ref),
  INDEX idx_short_link_deleted (deleted_at),
  INDEX idx_short_link_target_host (target_host)
);
```

- PostgreSQL: `VARCHAR` compares exactly; `JSONB` for metadata; `TIMESTAMP(0) WITHOUT TIME ZONE` (UTC by convention).
  SQLite: `TEXT`, exact by default (verified in tests). Collation declared through mapping `options: {collation:
  utf8mb4_bin}` on MySQL / MariaDB only (platform-aware listener, as HttpLog's `TablePrefixListener`).
- The hot-path lookup is a DBAL `SELECT id, tenant_key, code, target_url, kind, active, expires_at, deleted_at FROM
  nowo_short_link WHERE code = ?` on `uniq_short_link_code`, then `$row['code'] === $code` in PHP. No ORM hydration.
- `metadata` is never queried; only `source_ref` is.

### 5.2 Click tables (DBAL, created by a `postGenerateSchema` listener; no ORM entity)

```sql
CREATE TABLE nowo_short_link_click (
  id            BIGINT       AUTO_INCREMENT NOT NULL,   -- PostgreSQL: GENERATED BY DEFAULT AS IDENTITY
  link_id       VARCHAR(36)  NOT NULL COLLATE utf8mb4_bin,
  tenant_key    VARCHAR(64)  NOT NULL COLLATE utf8mb4_bin,
  clicked_at    DATETIME     NOT NULL,                  -- UTC, second precision
  ip_prefix     VARCHAR(43)  DEFAULT NULL,
  ua_family     VARCHAR(16)  DEFAULT NULL,
  referrer_host VARCHAR(253) DEFAULT NULL,
  country       CHAR(2)      DEFAULT NULL,
  machine       BOOLEAN      NOT NULL DEFAULT FALSE,
  PRIMARY KEY (id),
  INDEX idx_short_link_click_link (link_id, clicked_at),
  INDEX idx_short_link_click_tenant (tenant_key, clicked_at),
  INDEX idx_short_link_click_time (clicked_at)
);

CREATE TABLE nowo_short_link_click_daily (
  link_id        VARCHAR(36)  NOT NULL COLLATE utf8mb4_bin,
  tenant_key     VARCHAR(64)  NOT NULL COLLATE utf8mb4_bin,
  day            DATE         NOT NULL,                 -- UTC
  dimension      VARCHAR(16)  NOT NULL,                 -- total | referrer | country | ua
  value          VARCHAR(253) NOT NULL DEFAULT '',      -- '' for total / unknown, '~other' beyond referrer_top_n
  clicks         INT          NOT NULL DEFAULT 0,       -- human
  machine_clicks INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (link_id, day, dimension, value),
  INDEX idx_short_link_daily_tenant (tenant_key, day)
);

CREATE TABLE nowo_short_link_rollup_day (
  day       DATE     NOT NULL,
  rolled_at DATETIME NOT NULL,
  PRIMARY KEY (day)
);
```

Key width (MySQL utf8mb4): (36 + 3 + 16 + 253) × 4 ≈ 1 232 bytes < 3 072. Tests: empty schema diff after the
documented DDL on MySQL 8, MariaDB 10.11, PostgreSQL 16 and SQLite, with and without `table_prefix`; the prefix
listener also renames indexes (longest fixed name `idx_short_link_daily_tenant` = 27 chars, ≤ 64 with a 30-char
prefix, checked in the container).

### 5.3 Rollup algorithm (`nowo:short-link:rollup`, Beacon runs it daily)

1. Days to roll: every day `D` from the oldest raw day not in `rollup_day` (bounded by the retention cutoff) up to
   yesterday (UTC), plus the last `lag_days` days again (late Messenger writes).
2. Per day, one transaction: `DELETE FROM click_daily WHERE day = D`; `INSERT … SELECT … GROUP BY link_id,
   tenant_key, dimension, value` for `total`, `country`, `ua`, and `referrer` limited to the top `referrer_top_n` per
   link (the rest summed into `~other`); upsert `rollup_day`. Idempotent: running it twice gives the same rows.
3. Purge (`nowo:short-link:purge`) deletes raw rows older than `retention` **only for rolled days**, in keyset batches
   of 1 000, and never rolls a day older than the cutoff (nothing left to recompute from).
4. Stats read the rollup for rolled days and the raw rows for the rest (today and un-rolled days), so the UI is
   exact without waiting for the command.
5. Rollup retention `null` = forever (aggregates hold no personal data).

## 6. Codes

### 6.1 Random codes

- Alphabet base62 `[0-9A-Za-z]`, length `random_length` (7), from `Random\Randomizer(new Random\Engine\Secure())`
  per call (no seeded engine kept in the service; worker-safe). 62^7 ≈ 3.5 × 10^12.
- Skip a candidate when it is reserved, blocklisted (Q-O9, case-insensitive substring) or matches the custom-code
  pattern and is reserved; insert relies on `uniq_short_link_code`: a unique violation retries with a new code, up to
  `max_attempts`, then length + 1 (warning logged with the tenant, never the code list).
- Codes never encode ids, time or tenant (no sequential ids, spec 230).

### 6.2 Custom codes

- Only when `allow_custom` and the kind allows it (`email`: never). Lowercase `[a-z0-9-]`, 3–32, no leading /
  trailing / double hyphen (Q-O8). Input is lowercased before validation; the stored code is lowercase.
- Unique per instance (one `/s/` namespace for every tenant). Taken → `CodeTaken` with a neutral message ("This code is
  not available"), the same for taken, reserved and quarantined codes, so the form does not reveal which other tenant
  owns it. The form is rate-limited by the host's normal UI limits; the manager adds a per-actor limiter on
  custom-code lookups (10 / minute per tenant + actor, consumed before the lookup; audit NY-S4) against enumeration
  through the form.
- Codes are immutable after creation (a new code = a new link; old printed links keep working).

### 6.3 Reserved words (built in, case-insensitive, plus `code.reserved` and the host provider)

`admin`, `api`, `app`, `assets`, `auth`, `build`, `bundles`, `css`, `dashboard`, `docs`, `e`, `favicon`, `health`,
`help`, `img`, `js`, `legal`, `login`, `logout`, `mcp`, `media`, `metrics`, `new`, `oauth`, `privacy`, `register`,
`reset`, `robots`, `s`, `security`, `settings`, `signin`, `signup`, `sitemap`, `static`, `status`, `support`, `terms`,
`unsubscribe`, `well-known`, `www`, plus brand-like words the host adds (Q-B6). Two-character codes are already below
the minimum.

### 6.4 Lookup semantics

Exact match only: `/s/Launch` does not open `launch` (Q-O8). Random codes are case-sensitive by design.

## 7. Redirect (`GET|HEAD /s/{code}`)

### 7.1 Order (first failure answers)

| # | Step | Answer |
|---|---|---|
| 1 | Route requirement fails (bad characters / length) | Neutral 404 (no DB hit), counts as a miss |
| 2 | Miss limiter already exhausted for the IP (peek) | 429 neutral page, `Retry-After` |
| 3 | Lookup by code; PHP `===` re-check | Not found → neutral 404, consume a miss token |
| 4 | State: deleted, disabled, expired (`expires_at <= now`, clock port) | Neutral 404, consume a miss token |
| 5 | Redirect guard (`GuardStage::Redirect`: scheme, userinfo, literal private / link-local / metadata IP, self path, denylist; **no DNS**) | Neutral 404, `ShortLinkTargetRefusedEvent`, warning log (link id, refusal) |
| 6 | Hit limiter for the IP | Over limit: `redirect_unlogged` → 302 without a click row; `block` → 429 |
| 7 | 302 `Location: <target>` | Click recorded after the response (§7.3) unless HEAD |

### 7.2 Response headers

- 302 / 404 / 429 alike: `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex,
  nofollow`, `X-Content-Type-Options: nosniff`, no `Set-Cookie`, no session started (route `stateless: true`; test
  asserts `$request->hasSession()` is never started and no cookie is set even when the host firewall matches `/`).
- 302 body empty. `Location` is the stored normalised target, emitted with `Response::headers->set()` (no CRLF possible:
  `TargetUrl` refuses control characters).
- Neutral 404: one Twig template, same bytes for every reason (unknown, disabled, expired, deleted, refused); no code,
  no tenant name, no target, `<meta name="robots" content="noindex">`, no inline script (CSP-safe), themable
  (REQ-TWIG-001), translated (en, es). Timing: steps 3–5 run the same single query for every reason; no extra query on
  a hit vs a miss before the answer.
- 429: same neutral template variant, `Retry-After` seconds.

### 7.3 Click recording

- Data gathered in the request (cheap, no I/O): link id, tenant, `clicked_at` (clock), `ClientIpPrefix` from
  `Request::getClientIp()` (host `trusted_proxies`, Q-B5), UA family via the classifier (a bounded regex list over the
  first 512 bytes of `User-Agent`), referrer host from `Referer` (parsed, host only), machine flag, GPC flag.
- `Sec-GPC: 1` with `honour_gpc` → row with time, link and machine flag only (no IP prefix, UA, referrer, country).
- Write modes (Q-O11): `terminate` (default) keeps a request-attribute payload and writes on `kernel.terminate`
  after the response is flushed (verify `frankenphp_finish_request()` / `fastcgi_finish_request()` is called by the
  runtime; S2 measures it; fallback documented); `messenger` dispatches `RecordShortLinkClick` (readonly message, IP
  already truncated; the country is resolved in the request only when the resolver is local, otherwise it needs the raw
  IP → only allowed with `sync`/`terminate`, container check); `sync` writes before answering (tests, tiny hosts).
- Country: `ClickCountryResolverInterface` gets the raw IP in `terminate` / `sync` modes only, after the response.
- The recorder uses its own DBAL insert (one statement), never flushes the host's EM, catches and logs DB errors
  (class only): a broken click table must never break the redirect.
- `ShortLinkClickedEvent` after the insert.
- HEAD: answered like GET (302 / 404) but never logged and never consumes a hit token.

### 7.4 Performance budget (SC-003: p95 < 20 ms without session)

| Item | Budget |
|---|---|
| Routing + controller | < 2 ms (compiled routes, controller has 4 dependencies, no Twig on 302) |
| Rate limiter peek + consume | < 3 ms on Redis / APCu (Q-B5); a DB or filesystem cache pool is documented as unsuitable |
| Lookup | 1 indexed row, < 2 ms |
| Guard (no DNS), denylist (in memory) | < 1 ms |
| Click write | after the response (not in the budget) |

Measured in S2 with a demo benchmark (FrankenPHP worker, MySQL 8, Redis pool, 5 000 requests, 50 links): p95
recorded in `docs/PERFORMANCE.md`; CI asserts the in-kernel handle time p95 < 20 ms over 1 000 requests on SQLite +
array pool (a regression guard, not the production number).

### 7.5 Worker mode (REQ-RUNTIME-002)

- Every service `readonly`; no memo of codes, links, tenants, IPs or limiter state in properties or statics; per-request
  data on `Request` attributes only (click payload), cleared on `kernel.finish_request`.
- The UA classifier and the reserved / blocklist sets are immutable compiled arrays (container parameters).
- Own DBAL usage: no transaction left open; reconnect-safe (`ping`-free retry once on a lost connection).
- When the bundle's EM is the default EM, the manager `clear()`s only its own entities after each write unit
  (`detach`), never the host's (rule 6 + worker rule); with a dedicated EM, `clear()` after each unit and recreate a
  closed EM.
- Test: one kernel, `disableReboot()`, 5 000 alternating requests over two tenants and 3 states; memory flat (± 1 MiB),
  no cross-tenant answer; Igor and `phpstan-frankenphp` clean.

## 8. Target guard

| Stage | Checks |
|---|---|
| Save (create, update, enable) | `TargetUrl` VO rules; scheme http/https; no userinfo; length; self path (`own_hosts` + `self_paths`, no chains through `/s/`); `OutboundUrlGuard::inspect($url, allowPrivate: false, resolveDns: save_resolve_dns)` (private, link-local, loopback, CGNAT, metadata names and literals, unresolvable); denylist |
| Redirect | Same minus DNS (`resolveDns: false`) and with the denylist's cheap path |
| Recheck (`nowo:short-link:recheck --batch=500`) | Save checks with DNS over active links (keyset); a refused link is disabled with `disabled_reason = recheck_refused` + `ShortLinkStateChangedEvent`; Beacon schedules it daily (Q-O12) |

- `guard: basic` (no OutboundUrlGuard) is allowed only when the host says so; then hostnames resolving to private
  addresses are not caught (documented, warning at container build).
- `allowPrivate` is never true for this bundle (a container check refuses an `allow_private_resolver` bridge here).
- Javascript / data / file / ftp / mailto / relative / protocol-relative (`//evil`) targets are refused by the VO.
- Refusal messages in the form are per reason but never echo DNS results.

## 9. Security model

| Threat | Control | Test |
|---|---|---|
| Open redirect via parameters | The endpoint takes only `{code}`; no `?url=`, no `next`; target comes from the DB | Functional: extra query params ignored, `Location` unchanged |
| Phishing / malware under the trusted domain | Manage permission per tenant, plan limit, denylist port (Safe-Browsing-like), refused-target event, instant disable, recheck command, audit events with target host | Denylisted host at save and at redirect (added after save) → 404 |
| Intranet pivot from the visitor's browser | Private, loopback, link-local, CGNAT, metadata refused at save (DNS) and redirect (literals) | Matrix of literals (IPv4, IPv6, mapped, decimal / octal / hex IPv4 forms, `localhost`, `*.localhost`, `[::1]`, `169.254.169.254`, `metadata.google.internal`) |
| Scheme abuse | VO: http/https only, absolute, no control chars, no userinfo | `javascript:`, `JaVaScRiPt:`, `data:`, `//host`, `http:\\host`, tab / newline inside, `https://user:pass@x` |
| Header injection | VO refuses control chars; Symfony header bag | CRLF fixtures |
| Redirect chains / loops | `self_paths` on `own_hosts`; other shorteners not blocked by default (host can denylist `bit.ly` etc.) | Target `https://own/s/abc` refused |
| Enumeration | 62^7 space, miss limiter (20/min/IP), identical 404 for every non-redirect reason, no timing branch, codes never sequential, `CodeTaken` neutral + per-actor limiter on the form; custom codes are guessable by nature (docs: never put secrets behind a short link) | 404 body + headers byte-identical across reasons; limiter 429 after N misses |
| Log flooding | Hit limiter; over limit redirect unlogged; HEAD unlogged; raw retention P90D; referrer top-N in rollups | 1 000 hits from one IP → ≤ 120 rows/min |
| Code collision / takeover | Unique index, binary collation, PHP `===`, tombstone quarantine, immutable codes | MySQL case test (`aBcdEf1` vs `ABCDEF1` both insertable and resolve separately); deleted code not reusable until quarantine ends |
| Cross-tenant access | Every manager / reader / eraser query filters on tenant; UI ids of another tenant → 404; access port checked per action | Matrix: other tenant view / edit / disable / delete / stats / CSV → 404 |
| CSRF | FormKit forms with tokens; POST-only state changes (REQ-SEC-005) | Missing / bad token → 403, nothing changed |
| XSS in UI | Title, target, referrer host, labels, metadata escaped by Twig; target shown as text + `rel="noopener noreferrer nofollow"` link | Fixtures with `<script>`, `"><img onerror>`, RTL override in title |
| CSV injection | Cells starting with `=`, `+`, `-`, `@`, tab, CR prefixed with `'`; referrer hosts are attacker-controlled | Fixture referrer `=HYPERLINK(...)` |
| Session / cookie on public path | `stateless: true`, no `Set-Cookie`, no security token needed | Assert no session, no cookie |
| DoS by slow dependencies | No DNS on redirect, country resolver after the response with ≤ 50 ms timeout, limiter on a fast pool | Resolver that sleeps does not delay the 302 (terminate mode) |
| Bots / scanners inflating stats | `machine` flag, shown apart, HEAD unlogged | UA fixtures (Slackbot, facebookexternalhit, Twitterbot, WhatsApp, Microsoft Office / Safe Links, Proofpoint, curl, python-requests) |
| Plan-limit race | Count + insert in one transaction under a per-tenant `symfony/lock` key when available; without Lock, at most +N concurrent overshoot (documented) | Parallel create test with Lock → exactly the limit |

Out of scope v1 (spec 230): custom domains per tenant, QR codes, UTM builder, A/B links, link preview page
(`/s/{code}+`), abuse report form, password-protected links, click caps.

## 10. GDPR

- Personal data in raw clicks: truncated IP, UA family, referrer host, country, time. Pseudonymous; legal basis is the
  host's (Beacon: legitimate interest + opt-out for e-mail kind). Raw retention P90D (purge command); rollups hold no
  personal data and are kept while the link exists, then deleted with the link when its tombstone is purged after
  `code.quarantine`.
- No raw IP, full UA, cookie, fingerprint or user id is ever stored. GPC honoured (Q-O10).
- `created_by` / `updated_by` are personal data of the host's users: `anonymiseActor()`.
- E-mail kind: the recipient is identified only through the host's `source_ref` (send); `exportSource()` /
  `eraseSource()` let Beacon include or delete them in its personal-data export / erasure (spec 230 FR-008).
- Tenant deletion: `eraseTenant()` deletes every click and rollup row of the tenant and turns its links into
  tombstones (target, title, source and metadata blanked, tenant `~erased`, actors null) until the quarantine ends, so
  the codes cannot be taken over (Q-O7); the purge command then hard-deletes them.
- `docs/PRIVACY.md`: data inventory, retention, RAT text template for hosts (en), how to wire purge and erasure.

## 11. UI (opt-in, REQ-UI-001/002/003, UiKit scaffolds + FormKit)

| Page | Content |
|---|---|
| List | UiKit list scaffold: code (copy button), title, target host, kind badge, state badge (active / disabled / expired), expiry, clicks (30 d, human; machine in tooltip), created; filters (state, search); keyset pagination `page_size`; row actions edit / stats / disable-enable / delete (confirm). `email` kind hidden unless `show_in_ui` |
| Create / edit | FormKit form: target URL, custom code (create only, when allowed), title, expiry (FormKit datetime), active. Quota shown ("12 of 50 links"); over quota → disabled button + message. Guard refusals as field errors |
| Stats | Range (7 / 30 / 90 / custom ≤ 366 d), totals (human, machine), daily bar chart (UiKit chart, Q-O5; table fallback always present for a11y), top referrer hosts, countries, browser families; CSV button |
| CSV | Streamed (`StreamedResponse`, REQ-PERF-001 rule 3): `day,clicks,machine_clicks` then per-dimension sections, or `?dimension=` for one; UTF-8 BOM optional (`?excel=1`); injection-safe |
| Neutral 404 | §7.2 |

- Every page checks `View`; mutations check `Manage`; tenant from the context port; another tenant's id → 404.
- Copy button: UiKit's copy-to-clipboard component if it exists in 2.49, else a 2.x UiKit request (no bundle JS →
  REQ-ASSETS-* stay N/A). Checked in S0.
- Translations en + es (`NowoShortLinkBundle` domain), `validate-translations` target (REQ-MAKE-004).
- Twig blocks for host overrides (REQ-TWIG-001), `form_start`/`form_row`/`form_end` only (REQ-TWIG-003/005).

## 12. Standard vs DDD host (REQ-ARCH-001)

| | Standard host (default) | DDD host (opt-in) |
|---|---|---|
| Mapping | `doctrine.mapping: attribute` or xml, default EM, `doctrine:migrations:diff` | `mapping: xml`, dedicated EM / connection; no host entity extends or uses anything from the bundle |
| References | Host entity may store `short_link_id` string | Same, by `ShortLinkId` VO |
| Tenancy | Single tenant `default` | `ShortLinkTenantContextInterface` |
| Access | Roles from config | `ShortLinkAccessInterface` |
| Ports in signatures | Only bundle VOs / enums / scalars (rule 3 strict: no `Request`, `UserInterface`, `EntityManager`, `DateTime` mutable) | Same |
| Flush | Bundle never flushes the host EM (rule 6): own EM or explicit DBAL transaction, `detach` its entities | Same |
| Events | After commit (rule 7), ids + VOs (rule 4) | Same |
| Tests | Functional kernel A (attributes, roles, single tenant) | Functional kernel B (XML, dedicated EM, ports implemented by test doubles, two tenants) — both in `make test` |

There is no host entity to extend, no trait and no mapped superclass: the only entity is bundle-owned. Entities +
superclasses + traits = 1 = alternatives (XML + attribute twin).

## 13. Slices and acceptance tests

Every slice is one PR on the new repo (after Q-O1), green `make release-check` and CI on the exact SHA, CHANGELOG
`[Unreleased]`, docs updated. 0.x tags per slice only if Q-O2 says so.

| Slice | Content | Acceptance tests (done when) |
|---|---|---|
| **S0** scaffold | Copied from a 100 % compliant bundle (UptimeMonitor / HttpLog layout): composer (Packagist metadata, archive.exclude), Docker + Makefile + `release-check`, CI matrix (PHP 8.2–8.5 × SF 7.4 / 8.0 / 8.1 × SQLite / MySQL / MariaDB / PostgreSQL), PHPStan max + phpstan-frankenphp, Igor, Rector, CS, coverage gate 100 %, Spec Kit baseline (`docs/SPEC-KIT.md`, `SPEC-DRIVEN-DEVELOPMENT.md`, specs/001), docs set, `.github` (templates, CODEOWNERS, SECURITY, dependabot, pr-lint on `pull_request`, stale, release, sync-releases), `.cursor` pack, Flex recipe, demos symfony7 + symfony8 (FrankenPHP worker, MySQL, UiKit base style, FormKit), README banner + sections | `make release-check` green on an empty kernel test; demo smoke 200; `bundle-req-audit` dry run shows only "not yet built" gaps |
| **S1** domain + persistence | VOs, enums, ports + defaults, `CodeGenerator`, reserved / blocklist, `TargetUrl` normalisation, guard chain + OutboundUrlGuard bridge, denylist, entity + XML / attribute mapping, DBAL tables listener, table prefix listener, `ShortLinkManager` (create, createMany, update, disable, enable, delete tombstone, list, find, shortUrl), quota (+ Lock), access, events after commit | VO fixture matrices (§9 rows: scheme, userinfo, IDN, length, private literals); code generator: alphabet, length, retry on collision (forced duplicate), length + 1 after `max_attempts`, blocklist; custom code rules + reserved + quarantine; quota exact with Lock under 10 parallel creates; cross-tenant find / update / delete → null / not found; events only after commit (rollback → none); schema diff empty on 4 platforms × prefix; MySQL case-sensitivity test; standard and DDD kernels |
| **S2** redirect | Route, controller, steps §7.1, headers §7.2, neutral 404 / 429 templates, limiters, click recorder (3 modes), UA classifier, referrer parsing, GPC, country port, `ShortLinkClickedEvent`, worker test, benchmark | Step-order table test (each failure beats the later ones); 404 byte-identical for unknown / disabled / expired / deleted / refused; HEAD unlogged; no session / cookie; `Referrer-Policy`, `Cache-Control` present on every answer; 302 never to a refused target (denylist added after save); miss limiter 429; hit limiter redirect-unlogged vs block; click row contents per store flag and GPC; DB failure in recorder → still 302; terminate mode: slow country resolver does not delay the response; worker test 5 000 req flat memory, no cross-tenant; CI p95 guard; demo benchmark recorded |
| **S3** stats + retention | Rollup, purge, recheck commands; stats reader (rollup + raw merge); eraser (tenant, source, actor, export); Messenger message + handler (REQ-MSG-001) | Rollup idempotent (run twice, same rows), late clicks within `lag_days` counted, referrer top-N + `~other`; purge deletes only rolled days older than retention, keyset batches; quarantine purge; stats equal across the rollup / raw boundary (today + yesterday + 100 days ago); `bySource` per position / label; erasure counts; recheck disables a link whose host now resolves private (DNS stub) with an event |
| **S4** UI | List, create / edit, disable / enable / delete, stats with chart + breakdowns, CSV; translations en + es; Twig blocks; access + tenant context; quota display | Functional per profile: anonymous, viewer, manager, other tenant (404 everywhere), quota full; CSRF missing / wrong on every POST; XSS and CSV-injection fixtures; pagination budget (≤ 4 queries per list page, REQ-PERF-001); a11y table fallback of the chart; Playwright e2e (create → open `/s/` → stats shows 1 click → CSV) with screenshots (REQ-DEMO-013) |
| **S5** docs + hardening | USAGE, CONFIGURATION, ARCHITECTURE (ports, both host kinds), SECURITY, PRIVACY, PERFORMANCE, UPGRADING, FRANKENPHP-WORKER-AUDIT, DEMO-FRANKENPHP, README screenshots, Spec Kit deep baseline (REQ-SPECKIT-003) | `bundle-req-audit`: 100 % of applicable REQs ✅, every N/A justified (§14.2) |
| **Audit** | REQ-SEC-004 audit (immediate: public unauthenticated endpoint + permission port), fixes, re-audit only if behaviour changes; `bundle-req-audit` | Pass + owner OK → 1.0.0 (release standing OK applies once audited) |
| Beacon | T003–T005 adopt (Q-B answers); MailKit T001 uses `createMany()` / `bySource()` | Beacon's plan; the bundle waits for nothing in Beacon |

## 14. Audit points

### 14.1 For the REQ-SEC-004 audit (checklist handed to the auditor)

1. Redirect never reaches a non-http(s), private, link-local, metadata, self-chain or denylisted target, at save and at
   redirect time (SC-002); guard bypass fixtures (IPv4 alternative notations, IPv6 mapped / compat, IDN homographs
   normalised, trailing dot, uppercase scheme, whitespace, backslashes).
2. No parameter influences `Location`; no CRLF.
3. Neutral 404 identical for every reason (body, headers, status, no timing branch before the answer).
4. Code uniqueness and lookup are exact (binary collation on MySQL / MariaDB; PHP `===`); tombstones block reuse.
5. Tenant isolation on every manager, reader, eraser and UI route (ids of another tenant → 404, no existence leak).
6. Access port checked per action; default denies anonymous; `system` flag not reachable from HTTP.
7. CSRF on every state change; no GET mutation.
8. No session, cookie or security token on `/s/`; works with the host firewall covering `/`.
9. Rate limits: miss / hit limiters, pool choice, `trusted_proxies` dependence documented (spoofed `X-Forwarded-For`
   when the host trusts all proxies).
10. Click privacy: no raw IP stored anywhere (DB, logs, events, Messenger messages, exceptions); GPC honoured;
    retention and purge correct; erasure complete.
11. XSS / CSV injection on every rendered or exported user-controlled value.
12. Worker mode: no state across requests; memory flat; recorder errors contained.
13. Plan limit not bypassable through `createMany()` or concurrent requests (with Lock).
14. Logs: link id, refusal kind, exception class; never full target URLs with query strings (they may carry tokens),
    never IPs, never codes in bulk.

### 14.2 REQ applicability (100 % before 1.0.0; N/A recorded with a reason)

| REQ | Status |
|---|---|
| ARCH-001, RUNTIME-001, RUNTIME-002, PHP-001, PKG-001..004, SF-001..006, DI-001, ORM-001, ORM-002, OBS-001, PERF-001, BC-001, SEC-001..005, CS-001..008, MAKE-001..004, 006..010, TEST-001..003, 005..008, 010, 011, CI-001..003, REL-001..003, GIT-001, GIT-002, DOC-001, DOC-002, DEMO-001..015, ENV-001, GITIGNORE-001..003, DOCS-001..005, 008..019, TWIG-001..005, UI-001..003, I18N-001..003, SPECKIT-001..003, RECIPE-001, IDE-001..006, GH-001..008 | Applies |
| MSG-001 | Applies (`messenger` write mode, async purge message) |
| CFG-001, CFG-002 | N/A: single configuration, tenant variation via ports |
| ASSETS-001..004, UX-001, TEST-009 | N/A unless the copy button needs bundle JS (§11; decided in S0) |
| TEST-004, MAKE-005, GH-009 | N/A: no Python, no PoC, docs are Markdown |
| GH-010, GH-011 | Optional maintainer tooling; follow the template bundle |

## 15. Questions for the owner (Q-O)

| # | Question | Recommendation | If not |
|---|---|---|---|
| Q-O1 | Name and repo: `ShortLinkBundle`, package `nowo-tech/short-link-bundle`, public MIT `nowo-tech/ShortLinkBundle`? May the repo be created when S0 is ready? | **Yes.** One feature, so no "Kit" suffix (Kit = toolbox: UiKit, FormKit, AuthKit, NotifyKit); clear in Packagist search; distinct from `UxLinkBundle` | `ShortLinkKitBundle` / `short-link-kit-bundle` if you prefer the Kit family for every new bundle with UI |
| Q-O2 | Tags: 0.x per slice (Beacon integrates early, as ApiToken) and 1.0.0 after the audit, or no tag until 1.0.0? | **0.x per slice, 1.0.0 after SEC-004 + REQ audit.** Beacon 1.153 can start T003 on 0.2 | Beacon waits for 1.0.0 (or uses a VCS dev branch) |
| Q-O3 | `nowo-tech/outbound-url-guard-bundle` as a hard requirement? | **Yes.** Safe by default (DNS check at save) for every host; it is ours and small | Suggest only; built-in basic guard catches literals but not hostnames resolving to private addresses (warning at build) |
| Q-O4 | UiKit + FormKit as hard requirements (UI is opt-in at runtime)? | **Yes**, like UptimeMonitor / HttpLog / Wiki; standardise goal | Suggest; the UI routes refuse to load without them |
| Q-O5 | Stats chart: add a generic server-side SVG bar chart component to **UiKit** (reusable by HttpLog, ReportKit, Uptime), or keep it inside this bundle? | **UiKit component** (no JS, CSP-safe, table fallback), a UiKit minor before S4; the bundle shows the table until it ships | Bundle-local SVG partial (an override the inventory will count) |
| Q-O6 | A visitor over the per-IP hit limit on a **valid** link: redirect without logging, or 429? | **Redirect unlogged.** People behind a shared NAT (offices, mobile carriers) are never blocked; log flooding is still bounded. Misses (unknown codes) always get 429 | `block`: 429 for everyone over the limit |
| Q-O7 | Deleted codes: keep them reserved (tombstone) so another tenant cannot take over printed / mailed links? For how long? | **Yes, 365 days** (`code.quarantine: P365D`), then the code is free; tenant erasure also tombstones | Forever (never reuse) or immediate reuse |
| Q-O8 | Custom codes: lowercase `[a-z0-9-]{3,32}` with no leading / trailing / double hyphen; lookup exact only (`/s/Launch` → 404, not `launch`)? | **Yes to both.** Fewer look-alike codes; one simple rule; random codes are case-sensitive anyway | Allow any hyphen placement (spec text literally) and / or case-insensitive fallback for custom codes |
| Q-O9 | Random codes: base62 as the spec says, and skip codes containing a small offensive-word list (en + es)? | **Base62 + blocklist on.** Base62 keeps the spec; the blocklist avoids embarrassing generated codes at no cost | Base58 (no `0 O I l`) for printed links; or no blocklist |
| Q-O10 | `Sec-GPC: 1` (Global Privacy Control) on a click: count it but store nothing else (no IP prefix, UA, referrer, country)? | **Yes.** Aggregate counts stay right, the visitor's signal is respected (good for the RAT text) | Ignore GPC (same row for everyone) |
| Q-O11 | Click write default: after the response (`kernel.terminate`), with `messenger` and `sync` as options? | **Terminate.** Keeps the 20 ms budget without needing a Messenger transport; Beacon may switch to Messenger | Messenger by default (needs a transport on every host) or sync |
| Q-O12 | DNS: full check at save; on redirect only literal checks + denylist (no DNS, for p95 < 20 ms); plus a daily `recheck` command that disables links whose target now fails? | **Yes.** DNS on the hot path would cost up to 2 s and adds nothing for a browser redirect | DNS on every redirect (breaks SC-003) or no recheck |
| Q-O13 | Public HTTP JSON API (links CRUD + stats, `/short-links/v1`, OpenAPI) in 1.0? | **No.** 1.0 ships the PHP API (`ShortLinkManagerInterface`, `ShortLinkStatsReaderInterface`), the UI and CSV; a versioned API, disabled by default, in a later minor when a client needs it (smaller audited surface) | Include it in 1.0, disabled by default, auth via the host (ApiToken) |
| Q-O14 | Mapping and ids: XML default + attribute twin, UUIDv7 string(36) binary ids, DBAL click tables (as the 221 decisions and HttpLog)? | **Yes**, consistency with ApiToken / NotifyKit / ReportKit | Attribute only, or binary UUID columns |
| Q-O15 | Machine clicks (bots, link previews, e-mail scanners): stored with a flag and shown apart, excluded from the main count? | **Yes**, same as spec 230's machine opens | Count everything together, or drop machine clicks entirely |

## 16. Questions for Beacon (Q-B)

| # | Question | Recommendation |
|---|---|---|
| Q-B1 | Tenant key | Organization UUID (36 chars) as `TenantKey`; `ShortLinkTenantContextInterface` reads the organization of the current organization page |
| Q-B2 | Permissions mapping | `View` = `organization.short_links.view`, `Manage` = `organization.short_links.manage` (spec 163/165 permission sets); `currentActor()` = user UUID |
| Q-B3 | Plan limit | `ShortLinkQuotaInterface` from plan module *Short links* (spec 180): limit on `manual` links only; `email` links are not counted (`counted: false`). Install `symfony/lock` with the existing lock store so the limit is exact. Confirm |
| Q-B4 | UI mount | Import UI routes under `/organizations/{organization}/short-links` in the organization layout (UiKit), menu + breadcrumbs in Beacon; `ui.page_size` 25 |
| Q-B5 | Rate-limit pool and proxies | A Redis (or APCu) cache pool for `redirect.rate_limit.cache_pool`; `trusted_proxies` limited to Beacon's real proxy range (otherwise `X-Forwarded-For` spoofing defeats the per-IP limits). Default limits 20 misses / 120 hits per minute per IP; confirm or give numbers |
| Q-B6 | Reserved words | Built-in list (§6.3) plus Beacon's top-level route segments and brand words (`beacon`, `nowo`, …); give the list or let the bundle derive nothing (recommended: Beacon passes a static list) |
| Q-B7 | Denylist source | Beacon implements `TargetDenylistInterface` with its existing denylist (instance settings) cached in memory per worker **with an explicit version check per request** (worker-safe) or reads a host cache; Safe Browsing API later. Which source exists today? |
| Q-B8 | `public_base_url` and route | `/s/{code}` on the main Beacon host, outside the locale prefix and outside any stateful firewall rule that would start a session; `public_base_url` = Beacon's canonical URL so Messenger-sent e-mails get absolute short URLs. `own_hosts` = Beacon hosts |
| Q-B9 | Audit trail | Subscribe to `ShortLinkCreated/Updated/StateChanged/Deleted/TargetRefused` for the organization audit log (target host only, no full URL) |
| Q-B10 | E-mail kind | MailKit's link rewriter (T001) calls `createMany()` with `kind: email`, `LinkSource{ref: send id, label, position}`, `system: true`; credential-bearing links are never passed (Beacon's allowlist). E-mail links never expire by default (old e-mails keep working); is a lifetime wanted (e.g. 2 years, then 404)? Recommended: no expiry |
| Q-B11 | E-mail log and GDPR | Development → E-mail log reads `bySource()`; personal-data export calls `exportSource()` per send of the user, erasure calls `eraseSource()`; opt-out users get no email links at all (MailKit side) |
| Q-B12 | Schedules | Daily `nowo:short-link:rollup`, `nowo:short-link:purge`, `nowo:short-link:recheck` in Beacon's maintenance schedule (the bundle ships no scheduler); retention P90D as decided |
| Q-B13 | Country | Is GeoIP configured in Beacon? If yes, implement `ClickCountryResolverInterface` with a local database (no HTTP lookups); otherwise leave the null resolver and the country column empty |

## 17. Answers (2026-11-12) — contract v1.0

**Owner (Q-O1..Q-O15): all as recommended.** Repo `nowo-tech/ShortLinkBundle` created public by the coordinator; owner registers `nowo-tech/short-link-bundle` on Packagist once S0 is on `main`.

**Beacon (Q-B1..Q-B13):**
- Q-B1 tenant key = organization UUID. Beacon's route parameter is `uuid` (e.g. `/organizations/{uuid}/...`), not `organization`.
- Q-B2 as recommended (`organization.short_links.view` / `.manage`; actor = user UUID); Beacon creates the permissions.
- Q-B3 quota only on manual links; `symfony/lock` 8.1 installed with Redis store (framework.lock default) → use the default lock factory.
- Q-B4 UI at `/organizations/{uuid}/short-links` (param `uuid`, `Requirement::UUID`), organization layout; Beacon adds menu/breadcrumbs/page-edge; page_size 25. → the UI route prefix and the tenant route parameter name must be configurable.
- Q-B5 rate limiter pool configurable, Beacon uses `cache.rate_limiter`; do not touch trusted_proxies; 20 misses / 120 hits per minute per IP OK.
- Q-B6 config parameter `reserved_codes` (list) supplied by the host; the bundle also refuses codes starting with `_` or `.` on its own. Beacon list: account accounts admin api autocomplete billing build chat dashboard developer files health hooks invitation legal locale login logout manual mcp metrics offline organization organizations projects register reset-password search secret settings setup share status tasks time time-track tools uptime uptime-push wiki working-time beacon nowo nowo-tech s e.
- Q-B7 no target denylist exists today; Beacon implements `TargetDenylistInterface` over a new Instance Settings host list (cached per worker with version) + wires OutboundUrlGuard. An optional external-feed adapter may ship but is not used in v1.
- Q-B8 `/s/{code}` on the main host, no locale prefix, stateless PUBLIC_ACCESS firewall (Beacon adds it); `public_base_url` = `%env(DEFAULT_URI)%`; own_hosts = DEFAULT_URI host (+ localhost in dev).
- Q-B9 Beacon subscribes to the five events (target host only).
- Q-B10 `createMany()` kind email, `LinkSource{send id, label, position}`, system true, no credentials, no expiry by default. **Additional: dedupe by (send ref, position)** so a retried send does not duplicate links; email links never count in any quota and never appear in the manual UI.
- Q-B11 as recommended. Q-B12 as recommended (MaintenanceSchedule daily; P90D). Q-B13 no GeoIP → null resolver.

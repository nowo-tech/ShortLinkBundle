# Usage

## Table of contents

- [Status](#status)
- [Creating and managing links](#creating-and-managing-links)
- [Generated links (e-mail sends)](#generated-links-e-mail-sends)
- [Errors](#errors)
- [Events](#events)
- [Ports for multi-tenant and DDD hosts](#ports-for-multi-tenant-and-ddd-hosts)
- [Redirect path and firewalls](#redirect-path-and-firewalls)

## Status

S1: the PHP API (manager, ports, persistence). The redirect (S2), statistics (S3) and UI (S4) follow
([ROADMAP.md](ROADMAP.md)); the full design is [CONTRACT.md](CONTRACT.md) §3.

## Creating and managing links

```php
use Nowo\ShortLinkBundle\Domain\Model\{CreateShortLink, ShortLinkListQuery, TenantKey, UpdateShortLink};
use Nowo\ShortLinkBundle\Domain\Service\ShortLinkManagerInterface;

public function __construct(private ShortLinkManagerInterface $links) {}

$tenant = TenantKey::default();                       // multi-tenant hosts: TenantKey::fromString($organizationUuid)
$link   = $this->links->create($tenant, new CreateShortLink(
    'https://example.com/landing?utm_source=flyer',
    customCode: 'launch-2026',                         // null = random base62 code of 7 characters
    title: 'Launch flyer',
    expiresAt: new \DateTimeImmutable('+90 days'),
));
$link->shortUrl;                                       // https://your.host/s/launch-2026

$this->links->update($tenant, $link->id, new UpdateShortLink('https://example.com/new', 'Launch flyer'));
$this->links->disable($tenant, $link->id);             // the redirect answers 404
$this->links->enable($tenant, $link->id);              // the target is guarded again
$this->links->delete($tenant, $link->id);              // tombstone: the code stays taken for code.quarantine

$page = $this->links->list($tenant, new ShortLinkListQuery(search: 'flyer', limit: 25));
$next = $this->links->list($tenant, new ShortLinkListQuery(limit: 25, after: $page->next));
```

Every method takes the tenant explicitly; another tenant's id answers as missing. Codes are immutable (a new code is a
new link). Custom codes are lowercased; random codes are case-sensitive (`aB3xY9k` and `AB3XY9K` are two links).

## Generated links (e-mail sends)

```php
use Nowo\ShortLinkBundle\Domain\Model\{LinkKind, LinkSource};

$views = $this->links->createMany($tenant, [
    new CreateShortLink('https://example.com/a', kind: LinkKind::email(), source: new LinkSource($sendId, 'Header logo', 0), system: true),
    new CreateShortLink('https://example.com/b', kind: LinkKind::email(), source: new LinkSource($sendId, 'Button', 1), system: true),
]);
```

At most 200 commands, one transaction, random codes only. A command whose (kind, send ref, position) already has a live
link returns that link: a retried send never duplicates links. `email` links never count against the plan limit and
are hidden from the default list. `system: true` skips the access check: set it only in programmatic callers, never from
request input.

## Errors

All implement `Nowo\ShortLinkBundle\Domain\Exception\ShortLinkExceptionInterface`:

| Exception | When |
| --- | --- |
| `TargetRefusedException` (`->refusal`: `Scheme`, `Userinfo`, `Length`, `PrivateHost`, `Metadata`, `Unresolvable`, `Denylisted`, `SelfLoop`) | The target cannot be used |
| `CodeTakenException` / `CodeReservedException` | The custom code is not available (same neutral message for taken, reserved and quarantined codes) |
| `QuotaExceededException` (`->limit`) | The tenant's plan limit for a counted kind is reached |
| `AccessDeniedException` | The access port refused the action |
| `ShortLinkNotFoundException` | No live link with this id in this tenant |
| `InvalidShortLinkArgumentException` | A value broke its rule (expiry in the past, unknown kind, custom code where not allowed, batch over 200, …) |

## Events

Dispatched after the outermost commit (never when it rolls back), with ids and value objects only:
`ShortLinkCreatedEvent`, `ShortLinkUpdatedEvent`, `ShortLinkStateChangedEvent`, `ShortLinkDeletedEvent` and
`ShortLinkTargetRefusedEvent` (target host only, never the full URL), in `Nowo\ShortLinkBundle\Domain\Event`. They are
plain classes: listen to them by class name.

## Ports for multi-tenant and DDD hosts

| Port (`Nowo\ShortLinkBundle\Domain\Port`) | Default | Configure |
| --- | --- | --- |
| `ShortLinkTenantContextInterface` | single tenant `default` | `tenancy.context` |
| `ShortLinkAccessInterface` | `access.view_role` / `access.manage_role` (anonymous denied) | `access.provider` |
| `ShortLinkQuotaInterface` | `quota.default_limit` | `quota.provider` |
| `TargetDenylistInterface` | `target.denylist.hosts` | `target.denylist.provider` |
| `TargetGuardInterface` | self chain + OutboundUrlGuard + denylist | `target.guard: <service id>` |
| `ReservedCodeProviderInterface`, `CodeBlocklistInterface`, `ShortLinkIdGeneratorInterface`, `TenantLockInterface` | built in | decorate the alias |

Ports take and return the bundle's value objects only (no Symfony, Doctrine or HTTP types). Implementations must read
their request state on every call (FrankenPHP worker mode).

## Redirect path and firewalls

The redirect (S2) answers `GET|HEAD {redirect.path_prefix}/{code}` (default `/s/{code}`). It is a public, unversioned
protocol path (a link printed on paper), stateless: put it outside any locale prefix and under a stateless firewall so
no session starts, e.g.

```yaml
security:
    firewalls:
        short_link:
            pattern: ^/s/
            stateless: true
            security: false
```

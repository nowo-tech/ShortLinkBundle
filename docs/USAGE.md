# Usage

## Table of contents

- [Status](#status)
- [What you will call](#what-you-will-call)
- [Redirect path and firewalls](#redirect-path-and-firewalls)

## Status

S0 scaffold: the bundle registers no service, route or table yet. This page grows with each slice
([ROADMAP.md](ROADMAP.md)); the full API is designed in [CONTRACT.md](CONTRACT.md) §3.

## What you will call

| Service (S1+) | Use |
| --- | --- |
| `ShortLinkManagerInterface` | `create()`, `createMany()` (generated `email` links, deduplicated by send ref + position), `update()`, `disable()`, `enable()`, `delete()` (tombstone), `find()`, `list()`, `shortUrl()`; every method takes the tenant explicitly |
| `ShortLinkStatsReaderInterface` (S3) | `daily()`, `breakdown()`, `totals()`, `bySource()` (clicks per link of one e-mail send) |
| `ShortLinkEraserInterface` (S3) | `eraseTenant()`, `eraseSource()`, `anonymiseActor()`, `exportSource()` |

## Redirect path and firewalls

The redirect (S2) answers `GET|HEAD {redirect.path_prefix}/{code}` (default `/s/{code}`). It is a public, unversioned
protocol path (a link printed on paper), stateless: put it outside any locale prefix and under a stateless
`PUBLIC_ACCESS` firewall so no session starts, e.g.

```yaml
security:
    firewalls:
        short_link:
            pattern: ^/s/
            stateless: true
            security: false
```

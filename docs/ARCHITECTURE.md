# Architecture

## Table of contents

- [Layers](#layers)
- [Standard and DDD hosts](#standard-and-ddd-hosts)
- [What the bundle owns and what the host owns](#what-the-bundle-owns-and-what-the-host-owns)
- [S0 state](#s0-state)
- [Architecture checks](#architecture-checks)

## Layers

Contract §3 ([CONTRACT.md](CONTRACT.md)):

| Namespace | Content | Rule |
| --- | --- | --- |
| `Nowo\ShortLinkBundle\Domain` | Value objects, enums, ports (interfaces), events, exceptions | No Symfony, Doctrine or HTTP type (REQ-ARCH-001 rule 3, strict: own VO per port) |
| `Nowo\ShortLinkBundle\Application` | `ShortLinkManager`, `ShortLinkStatsReader`, `CodeGenerator`, `ClickRecorder` | Use the ports only |
| `Nowo\ShortLinkBundle\Infrastructure` | Doctrine (entity, XML + attribute mapping, DBAL tables, repositories), Symfony (controller, forms, commands, rate limiter, guard bridge, default port implementations) | Never flushes the host EntityManager |
| `Nowo\ShortLinkBundle\Presentation` | UI controllers, view models, Twig | UiKit scaffolds + FormKit forms |

## Standard and DDD hosts

| | Standard host (default) | DDD host (opt-in) |
| --- | --- | --- |
| Mapping | `doctrine.mapping: attribute` or `xml`, default EntityManager | `mapping: xml`, dedicated EntityManager / connection |
| References | A host entity stores the `short_link_id` string | Same, through the `ShortLinkId` value object |
| Tenancy | Single tenant `default` | `ShortLinkTenantContextInterface` |
| Access | Roles from configuration | `ShortLinkAccessInterface` |
| Flush | The bundle uses its own EntityManager or an explicit DBAL transaction and detaches its entities | Same |
| Events | After commit, ids and value objects | Same |

There is no host entity to extend, no trait and no mapped superclass: the only entity is bundle-owned.

## What the bundle owns and what the host owns

The bundle owns codes, targets, the redirect, the click log, rollups, purge and the UI. The host owns tenants, who may
view / manage, plan limits, the denylist source, the country lookup, the rate-limiter cache pool and where the UI is
mounted (for Beacon: `/organizations/{uuid}/short-links`, `ui.tenant_route_parameter: uuid`).

## Persistence and events (S1)

- The `nowo_short_link` table is mapped by `Infrastructure\Doctrine\Entity\ShortLinkRecord` (XML by default, the same
  class carries the attribute twin) only so that `doctrine:migrations:diff` sees it; the bundle never hydrates it. Reads
  and writes go through `DbalShortLinkStore` on the configured entity manager's connection: the host's unit of work is
  never flushed (rule 6).
- Writes join an open host transaction; their after-commit work (the events) is held by `AfterCommitQueue` until the
  outermost commit (DBAL middleware), dropped on rollback and at every request / message boundary (worker mode).
- The click tables (S2 / S3) are plain DBAL tables added to the same schema by `ClickTablesSchemaListener`.

## Where the code is

[specs/001-baseline/code-inventory.md](../specs/001-baseline/code-inventory.md) lists every file of `src/` with its
requirement ids.

## Architecture checks

[Deptrac](https://github.com/deptrac/deptrac) checks the dependencies between the layers on every CI run (REQ-ARCH-002; `deptrac.yaml`, `composer deptrac` / `make deptrac`, part of `make release-check` and of the PHPStan CI job). A violation, a dependency on an unlisted vendor namespace or a class under `src/` that belongs to no layer fails the build.

| Deptrac layer | Namespaces | May depend on |
| --- | --- | --- |
| `Domain` | `Domain` | itself, `psr/clock`, `psr/log`, `psr/event-dispatcher`, `nowo-tech/transaction-contract` |
| `Application` | `Application`, plus the Messenger message `Infrastructure\Messenger\RecordShortLinkClick` (a framework-free DTO; hosts route it by class name, so it is not moved) | `Domain` and the same contract packages |
| `Infrastructure` | `Infrastructure` except the HTTP edge below | `Domain`, `Application`, Symfony, Doctrine, Twig, transaction-doctrine, UiKit / FormKit, OutboundUrlGuard, MigrationsKit contracts |
| `UI` | `Presentation`, `Infrastructure\{Http, Security}`, the route loaders in `Infrastructure\Routing` | `Domain`, `Application`, Symfony, Twig, transaction-doctrine, UiKit / FormKit |
| `DependencyInjection` | `DependencyInjection`, the bundle class | every layer (composition root) |

The click recorder asks `Domain\Port\TransientFailureDetectorInterface` whether a raw storage failure is a lock
conflict (the container wires `Infrastructure\Persistence\DbalTransientFailureDetector`, transaction-doctrine's
classifier); the `public_base_url` rule is `Domain\Model\PublicBaseUrl` (`Configuration::isBaseUrl()` delegates to it) and
the translation domain is `Domain\TranslationDomain::NAME` (the bundle constant keeps the same value). No violation is
skipped.

# Release process

## Table of contents

- [Version plan](#version-plan)
- [Before each 0.x tag and 1.0.0](#before-each-0x-tag-and-100)
- [Standard workflow](#standard-workflow)

## Version plan

One 0.x tag per slice (owner decision Q-O2): **0.1.0** (S1 domain + persistence) … **0.5.0** (S5 docs + hardening),
each after its REQ-SEC-004 audit (immediate for the redirect and guard slices), then **1.0.0** after the full audit.
The first tag is **0.1.0**. See [ROADMAP.md](ROADMAP.md).

## Before each 0.x tag and 1.0.0

1. Independent AI security audit of the slice recorded with a passing grade (REQ-SEC-004) — see
   [SECURITY.md](SECURITY.md#release-security-checklist-1241); every blocking finding fixed and re-checked.
2. `bundle-req-audit`: every applicable REQ of `BUNDLES_FULL_SPECS_DETAILS.md` ✅ or justified N/A (new-bundle rule:
   100 % before the first tag; items deferred to a later slice are listed in the audit and in [ROADMAP.md](ROADMAP.md)).
3. [CHANGELOG.md](CHANGELOG.md) — move `[Unreleased]` to `## [0.x.0] - YYYY-MM-DD`.
4. [UPGRADING.md](UPGRADING.md) — the section for that version current (schema changes with their DDL).
5. [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) — audited revision set to the tag.
6. `make release-check` green (check-no-cursor-coauthor, check-open-prs, check-no-raw-html-form, check-twig-extra,
   composer-sync, cs-fix, cs-check, rector-dry, phpstan, validate-phpdoc, igor, validate-translations, test-coverage,
   coverage-check, demo release-check incl. `demo-smoke`). Playwright e2e and demo screenshots join with the S4 pages
   (REQ-DEMO-013).
7. CI green on the exact release commit.
8. Annotated tag `git tag -a v0.x.0 -m "Release v0.x.0"`; run `make check-no-cursor-coauthor` again before pushing
   (REQ-GIT-001).
9. Push branch and tag; `release.yml` creates the GitHub Release from the tag message and the changelog entry.
10. Confirm Packagist picked up the tag; submit the recipe to `symfony/recipes-contrib` if wanted (1.0.0).

## Standard workflow

1. Move `[Unreleased]` entries in [CHANGELOG.md](CHANGELOG.md) to a new `## [x.y.z] - YYYY-MM-DD` section.
2. Update [UPGRADING.md](UPGRADING.md) when hosts must act.
3. Update integrator docs ([USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [SECURITY.md](SECURITY.md)) when behaviour changes.
4. Run `make release-check`.
5. Commit, tag (`git tag -a vx.y.z -m "Release vx.y.z"`), push branch and tag.
6. Verify the GitHub Release and Packagist.

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001).

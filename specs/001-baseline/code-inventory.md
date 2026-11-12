# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/short-link-bundle`  
**Last audited**: 2026-11-12 (S0)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **12**.

## Bundle and dependency injection

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoShortLinkBundle.php` | Bundle | FR-BUNDLE-001 |
| `src/DependencyInjection/NowoShortLinkExtension.php` | Configuration | FR-CONFIG-001, FR-CONFIG-002 |
| `src/DependencyInjection/Configuration.php` | Configuration | FR-CONFIG-001 |
| `src/DependencyInjection/ConfigNodes.php` | Configuration | FR-CONFIG-001 |

## Resources

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/config/doctrine-mapping/README.md` | Persistence (mapping folder, empty in S0) | FR-DB-001 |
| `src/Resources/translations/NowoShortLinkBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.pt.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoShortLinkBundle.nl.yaml` | Translations | FR-I18N-001 |

| Total production sources | 12 |
| --- | --- |

# Short Link Bundle demos

| Demo | Symfony | PHP | URL |
|---|---|---|---|
| `symfony7` | 7.4 | 8.3 | http://localhost:8230 |
| `symfony8` | 8.1 | 8.5 | http://localhost:8231 |

```bash
make up-symfony8      # prints "Demo started at: http://localhost:8231"
make setup-symfony8   # composer install + database
make down-symfony8
```

- FrankenPHP **worker mode** by default (`FRANKENPHP_MODE=worker`: kernel kept alive, not reset between requests);
  `classic` in `.env` + recreate.
- Pages use **UiKit**'s admin layout and default theme (REQ-DEMO-014: no CSS framework, no CDN); forms are
  **FormKit** forms with the `demo` profile (REQ-DEMO-015).
- The port is bound to 127.0.0.1. The database is a passwordless MySQL on the private Compose network (demo only).
- `make demo-smoke` from the repository root boots each demo in turn and expects HTTP 200 (REQ-TEST-011).

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).

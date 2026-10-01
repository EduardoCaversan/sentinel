# V1 verification

Verified locally on 2026-10-01 using Docker Desktop Linux containers on Windows.

## Results

| Check | Result |
| --- | --- |
| PHP / Laravel | PHP 8.4.26 / Laravel 12.69.3 |
| SQLite suite | 60 tests, 187 assertions, passed |
| MariaDB suite | 60 tests, 187 assertions, passed against `sentinel_testing` |
| Pint | 64 PHP files, passed |
| Composer | Locked installation and strict manifest validation passed |
| Migrations | Fresh MariaDB initialization succeeded; subsequent migration run had nothing pending |
| OpenAPI | Validated during Docker build with Swagger Parser |
| HTTP discovery | `/`, `/health`, `/health/ready`, `/docs`, `/openapi.json`, and both Swagger assets returned 200 |
| Horizon | Running; processed real HTTPS checks |
| Scheduler | Running; minute dispatch and daily token pruning listed by `schedule:list` |
| Redis locks | Separate PHP process was excluded while a lock was held and acquired it after release |
| Live smoke | Registration, login, token revocation, monitor creation, queued HTTPS checks, persisted results, incident opening and recovery passed |
| Images | Development and production targets built successfully |

The final smoke run used `https://example.com`, expected 503 to produce two failures, then expected 200 to produce two recovery successes. Exactly one incident opened and resolved. The disposable monitor was deleted and its token revoked. Generated smoke accounts remain without active tokens.

## Commands executed

The principal commands were:

```bash
docker compose build app
docker compose up -d --wait
docker compose exec app composer install --no-interaction --prefer-dist
docker compose exec app composer validate --strict
docker compose exec app php artisan migrate --force
docker compose exec app php artisan test
docker compose exec app vendor/bin/phpunit --configuration=phpunit.mariadb.xml
docker compose exec app vendor/bin/pint --test
docker compose exec app php artisan horizon:status
docker compose exec app php artisan schedule:list
docker compose exec app php scripts/verify-locks.php
docker compose exec app php scripts/smoke.php
docker build --target production -t sentinel:production .
```

Final test, lint and lock checks also ran in a disposable container using the same final development image and the stack's Redis/MariaDB network. The separate test database was created with `scripts/create-test-database.sh`.

## Environment-specific details and verification limits

- Windows reserved port 8080, so this workspace's ignored `.env` maps the API to **http://localhost:18080**. Repository defaults remain 8080.
- Horizon and the scheduler explicitly use SIGTERM; Apache's inherited stop signal is unsuitable for PHP worker shutdown.
- Swagger HTML, specification and bundled assets were verified over HTTP. Browser interaction was not automated.
- GitHub Actions is configured but has not run remotely. No public deployment, TLS termination, production egress firewall or load test was performed.
- The production image was built; the live integration run used the development image with the same application code.
- Retention, resource quotas, notification integrations, organizations and other roadmap features remain deferred. See the README for deployment and SSRF limits.

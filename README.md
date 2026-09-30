# Polaris

Polaris tracks your Tesla's odometer against your lease allowance, projects the mileage at the end of the lease and warns you before you go over.

> **Status:** early design. Nothing is usable yet.

## What it will do

- Read the odometer from the official Tesla Fleet API, once or twice a day, without waking the car, plus a manual refresh at most once per hour. Waking the car on a confirmed manual refresh comes after the first release.
- Compare the kilometres driven with the lease allowance and project the total at lease end.
- Give a remaining budget per day, week and month, and alert when the projection crosses the limit.

## How the projection works

Every figure is taken at the latest odometer reading after the lease start (even past the lease end, if the car keeps driving), because nothing is known about the distance driven since:

- **Allowed to date:** the allowance earned so far, linear over the whole term: allowance x time elapsed / lease length, in exact seconds.
- **Margin:** allowed to date minus the distance driven; negative when over.
- **Pace:** the overall pace since the lease start, and the pace over the last 30 days (the odometer is interpolated between readings). The blended pace is 0.6 x the last 30 days + 0.4 x overall, or the overall pace alone during the first 30 days.
- **Projection:** the distance driven plus the blended pace until the lease end.

### Budgets, risk level and excess cost

With A the total allowance and P the projected distance at the lease end:

- **Risk level:** `Over` when P > A. Otherwise `Watch` when P > A x (1 - tolerance), else `OK`. The tolerance is a safety margin below the allowance and is 0 by default (Swiss leases have none), so `Watch` only appears when you set one.
- **Budget:** what is left of the allowance (zero once it is used up) divided by the time left from the latest reading. A day is 86,400 s, a week 7 days, and a month one lease month: the term divided by its number of months (a 60-month lease has 60 equal months). Rounded half up to the metre.
- **Weekly cut:** (P - A) per week left. Positive is the distance to cut per week to land on the allowance, negative the room left.
- **Excess cost:** (P - A) x the contract's excess cost per km, in integer arithmetic from the metres, rounded half up once to the minor unit. It is 0 while P stays within A.
- **No data:** no reading after the lease start yet, or the lease has not started.
- **Lease ended:** once the clock reaches the end date, no time is left: budgets are zero and P is the distance driven. The excess cost keeps growing with every new reading.

The weight of the last 30 days is the `polaris.lease.recent_pace_weight` parameter in `config/services.yaml`, in basis points (`6000` = 0.6, from `0` to `10000`).

## This repository

The open-source, self-hostable edition of Polaris: a Symfony application. It is published as a Docker image on GitHub Container Registry.

Each user connects their own Tesla developer application, so Tesla API usage is billed to the user's own Tesla developer account.

## Repository layout

A standard Symfony application at the repository root (namespace `Polaris\`):

```
polaris/
  bin/console
  config/
  docs/adr/     # architecture decision records
  migrations/   # Doctrine migrations
  public/index.php
  src/          # application code, Polaris\, one namespace per feature module
  tests/        # PHPUnit tests: Unit/ and Functional/
  translations/
```

`src/` is organized by feature module (`Shared`, `Lease`, `Vehicle`, and more as they arrive), see [ADR 0001](docs/adr/0001-feature-modules.md). Decisions are recorded in [`docs/adr/`](docs/adr/).

The private hosted platform is built on top of this application's Docker image and adds its own bundle; nothing hosted-only lives here.

## Local development

Requirements: Docker with Compose v2.24 or later. The setup is based on [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker) and runs on [FrankenPHP](https://frankenphp.dev) with PHP 8.5.

| Service | Role | Exposed on the host (dev) |
| --- | --- | --- |
| `php` | FrankenPHP web server | https://localhost (HTTP redirects to HTTPS) |
| `worker` | Same image, runs `messenger:consume` (idle until Messenger is installed) | none |
| `database` | PostgreSQL 18, time zone UTC | `127.0.0.1:5432` |
| `redis` | Redis 8 | `127.0.0.1:6379` |
| `mailer` | Mailpit, catches every email | UI http://localhost:8025, SMTP `127.0.0.1:1025` |

Secrets are never committed. Copy `.env.example` to `.env.local` (git-ignored), which Symfony and every container read, then fill each empty value with a generated secret (`openssl rand -hex 16`):

```
cp .env.example .env.local
```

Build and start everything (`compose.override.yaml` adds the development settings automatically):

```
make start
```

`make start` runs `docker compose build --pull --no-cache` then `docker compose up --detach --wait`. The `Makefile` wraps the Docker workflow and runs every PHP command inside the `php` container, never with the host PHP; `make` alone lists all targets.

Open https://localhost and accept the self-signed certificate: the Symfony welcome page answers. The first start runs `composer install` if `vendor/` is empty.

Everyday commands:

```
make sh                         # shell in the php container
make test                       # PHPUnit, options via ARGS="--filter=..." (prepares the test database first)
make ci                         # every CI check: PHP-CS-Fixer, PHPStan, Deptrac, PHPUnit
make migrate                    # apply the pending Doctrine migrations
make diff                       # generate a migration from the entity mapping
make test-db                    # create and migrate the polaris_test database
make cs-fix                     # apply the coding standard
make sf ARGS="about"            # any bin/console command
make composer ARGS="outdated"   # any Composer command
make logs                       # follow the container logs
make down                       # stop everything
docker compose restart worker   # after changing message handlers
docker compose down -v          # also delete the database and Redis data
```

Host ports can be changed with environment variables: `HTTP_PORT`, `HTTPS_PORT`, `HTTP3_PORT`, `DATABASE_PORT`, `REDIS_PORT`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`. Xdebug is installed in the dev image; enable step debugging with `XDEBUG_MODE=debug docker compose up -d`.

To try the production image locally:

```
docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait
```

Everything runs in UTC: the containers set `TZ=UTC`, PHP sets `date.timezone = UTC`, PostgreSQL uses `timezone = UTC`, and the kernel forces UTC again when it boots. Doctrine hydrates every date as a UTC `CarbonImmutable`: calendar dates use the `carbon_date_immutable` type (SQL `DATE`), and `datetime_immutable` is replaced by a type that converts to UTC before writing.

The database schema changes only through Doctrine migrations in `migrations/`. The `php` container applies pending migrations when it starts; `make migrate` does it on demand. `DATABASE_URL` in `.env` holds no password: it reads `${POSTGRES_PASSWORD}` from `.env.local`.

Without Docker, PHP 8.5 and Composer are enough to run the console and the unit tests: `composer install`, `bin/console about`, `vendor/bin/phpunit tests/Unit`. The functional tests need PostgreSQL: point `DATABASE_URL` at a reachable server (for example `127.0.0.1:5432` with the Docker database) in `.env.local`.

### Quality checks

CI (`.github/workflows/ci.yml`) runs on every pull request and on `main`, one job per tool, each calling a Composer script that you can run the same way locally (`composer <script>` on the host, `make <target>` in Docker). The PHPUnit job starts PostgreSQL 18, applies the migrations and runs `doctrine:schema:validate` before the tests, so a migration that does not match the mapping fails the build:

| Check | Composer / Make | Configuration |
| --- | --- | --- |
| PHPUnit | `composer test` / `make test` | `phpunit.dist.xml` |
| PHPStan, level max, with the Symfony, Doctrine and PHPUnit extensions | `composer phpstan` / `make phpstan` | `phpstan.dist.neon` |
| PHP-CS-Fixer (`@Symfony`, `@Symfony:risky`, PHP 8.5 migration), dry run | `composer cs` / `make cs` | `.php-cs-fixer.dist.php` |
| Deptrac, the module boundaries of [ADR 0001](docs/adr/0001-feature-modules.md) | `composer deptrac` / `make deptrac` | `deptrac.yaml` |

`composer ci` (or `make ci`) runs them all. Fix findings rather than adding PHPStan baselines or ignores.

## Stack

In place: Symfony 8.1, PHP 8.5, PostgreSQL 18 with Doctrine ORM and Migrations, Carbon, ULID identifiers ([ADR 0002](docs/adr/0002-ulid-identifiers.md)). All dates are handled in UTC ([ADR 0003](docs/adr/0003-utc-dates.md)).

Planned: Redis for Messenger queues, Lock and Cache; Tailwind CSS with the Symfony UX Toolkit Shadcn kit; Flowbite charts.

## Related repositories

- [polaris-ios-kit](https://github.com/RSickenberg/polaris-ios-kit): iOS translations and push notification handling (Apache-2.0).

## License

[AGPL-3.0-or-later](LICENSE). A commercial license is available for uses that cannot comply with the AGPL, see [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). External contributions require the [Contributor License Agreement](CLA.md).

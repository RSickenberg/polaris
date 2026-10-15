# Polaris

Polaris tracks your Tesla's odometer against your lease allowance, projects the mileage at the end of the lease and warns you before you go over.

> **Status:** early design. Nothing is usable yet.

## What it will do

- Read the odometer from the official Tesla Fleet API, once or twice a day, without waking the car, plus a manual refresh at most once per hour. Waking the car on a confirmed manual refresh comes after the first release.
- Compare the kilometres driven with the lease allowance and project the total at lease end.
- Give a remaining budget per day, week and month, and alert when the projection crosses the limit.

## Signing in

Every page sits behind a login; the only public ones are `/login` and the language switcher. A self-hosted Polaris has a single user, created on the command line (there is no sign-up page):

```bash
make sf ARGS="polaris:user:create you@example.com"                                   # asks for the password twice, hidden
make sf ARGS="polaris:user:create you@example.com --locale=fr --time-zone=Europe/Zurich"
printf '%s\n' "$PASSWORD" | docker compose exec -T php bin/console polaris:user:create you@example.com   # not interactive: reads the password from the standard input
```

The password is never an argument or an option, so it stays out of the shell history and the process list. It needs at least 12 characters and is stored only as a hash. The command refuses to create a second user. Then open `/login`.

After 5 failed attempts for the same email and IP address within 15 minutes, further attempts from them are refused, even with the right password, until the window passes; an address that tries many emails is also limited. The counters live in the cache (`cache.rate_limiter`), so they are shared by every worker of the same container. A forgotten password is reset from the login page ("Forgot your password?"): `/forgot-password` emails a link that works once and expires after an hour, and the page behind it asks for the new password twice. The answer is the same whether or not the email has an account, at most 5 links are asked for per client address and hour, and a new link replaces the previous one. Resetting or changing the password cancels every pending link and signs every device out. It needs a working mailer: set `MAILER_DSN` and `MAILER_FROM`, and `DEFAULT_URI` to the public address of the app (the link is built from it, never from the request's Host header), in `.env.local`. In development the containers send to Mailpit (http://localhost:8025).

The sidebar shows the user as an avatar with their initials and a menu with the settings and sign out. `/settings` changes the email, the language, the time zone (`User.timeZone`, UTC by default, the one dates are shown and entered in) and the password (the current one is asked, the new one is typed twice and needs 12 characters); the user stays signed in after a change. The language switcher is kept in `User.locale` too.

`make seed` loads the sample vehicles for `dev@example.test`, with a random password printed once at the end of the seed output.

## Tesla key pair

Tesla verifies your app by fetching a public key at `https://<your domain>/.well-known/appspecific/com.tesla.3p.public-key.pem`. Set `POLARIS_ENCRYPTION_KEY` in `.env.local` (`openssl rand -base64 32`, and back it up), then generate the key pair (prime256v1) after creating the user:

```
make sf ARGS="polaris:tesla:keygen"
```

The private key is stored encrypted ([ADR 0007](docs/adr/0007-secrets-encrypted-at-rest.md)) and never shown; the command prints the public key. It refuses to replace an existing key unless you pass `--force`, which most likely means registering the app with Tesla again (not verified).

`POLARIS_KEY_HOSTING` chooses who serves the key. `polaris` (default): this app answers the URL above, without a login. `external`: set `POLARIS_KEY_HOSTING_DIR` to a local clone of a static site (for example on GitHub Pages) and `keygen` writes the public key to `.well-known/appspecific/com.tesla.3p.public-key.pem` inside it. Commit and push it yourself. Only the public key is ever written there.

## Tesla app credentials and registration

Create a Fleet API app on developer.tesla.com, with the domain that hosts your public key as an allowed origin. Then store its credentials (the key pair must exist first):

```
make sf ARGS="polaris:tesla:configure --client-id=<client ID> --domain=polaris.example.com --region=eu"
```

The client secret is asked with a hidden prompt (or read from the standard input when the command is not interactive), never taken from an argument, and stored encrypted. `--region` can be repeated: `na` is North America and Asia-Pacific, `eu` is Europe, the Middle East and Africa. China is not supported: it needs a separate Tesla account and developer app. Options you leave out keep their stored value.

```
make sf ARGS="polaris:tesla:key:check"
make sf ARGS="polaris:tesla:register"
```

`key:check` fetches the public key from your domain without following redirects and compares it with the stored one. `register` runs the same check first and sends nothing to Tesla if it fails. It then registers the domain once per enabled region (a partner token, then `POST /api/1/partner_accounts`) and reads the key back to confirm it. Tesla's error messages are shown with a hint. The partner token is requested without a scope, and the register and read-back response shapes are not documented by Tesla, so the first real run is the check (not verified).

## Odometer readings

Readings are stored in integer metres and UTC, whatever unit they were typed in. Add one by hand, in km or miles:

```bash
make sf ARGS="polaris:odometer:add 12345"                          # km, now, the only vehicle
make sf ARGS="polaris:odometer:add 7600 --unit=mi --at=2026-10-01T08:00 --vehicle=<VIN or id>"
```

or on `/vehicles/{id}/readings`, which also lists the history, newest first. A value lower than the previous reading is rejected; a reading dated in the past (a backfill) must fit between the readings around it, and an equal value is accepted. Dates are entered and shown in the time zone of the signed-in user (UTC by default), and stored in UTC.

## Lease terms

Enter or edit the lease on `/vehicles/{id}/lease`: start and end dates (the end date is either the return day or the last day of the lease, and the lease must last a whole number of months), the allowance (per year or for the whole term, in km or miles), the odometer at the start, the excess cost per km with its currency (CHF, EUR, GBP or USD) and the tolerance (0 to 50 %). A vehicle has one contract, and an edit overwrites it. Distances are stored in metres and the cost in minor units. Saving recomputes the projection with the recorded readings, and terms that contradict a reading (a start odometer above it, for example) are refused whole.

## Dashboard

`/vehicles/{id}` shows where a vehicle stands in one page: the risk level with a message (the projected excess, its cost and the weekly cut when it is `Watch` or `Over`), the share of the allowance driven (the tick marks the allowance earned to date), the distance driven, the allowance to date, the projection at the lease end, the date of the last reading after the lease start, and the budget per day, week and month. Without a contract, without a reading after the lease start, or once the lease has ended it says so and links to the page that fixes it; an ended lease shows the figures so far and no budget. Distances are in km and dates are shown in the time zone of the signed-in user.

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

`src/` is organized by feature module (`Shared`, `Account`, `Lease`, `Vehicle`, and more as they arrive), see [ADR 0001](docs/adr/0001-feature-modules.md). Decisions are recorded in [`docs/adr/`](docs/adr/).

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

Open https://localhost and accept the self-signed certificate. The first start runs `composer install` if `vendor/` is empty.

The `tailwind` service rebuilds the stylesheet whenever a template or `assets/styles/app.css` changes, so there is no front-end build step to run by hand. The Tailwind binary and the built CSS live in the `tailwind` volume (`var/tailwind`); the first start downloads the binary. The pages show the stylesheet only once it is built, so run `make tailwind` before `make test` on a fresh checkout (the `test` and `ci` targets do it for you).

The interface follows the browser language (English or French) until you pick one with the language switcher, which keeps the choice in a cookie and, once you are signed in, in your account. The light, dark or system theme is kept in the browser only.

Everyday commands:

```
make sh                         # shell in the php container
make tailwind                   # build the CSS once (the tailwind service watches it in dev)
make test                       # PHPUnit, options via ARGS="--filter=..." (prepares the test database first)
make ci                         # every CI check: PHP-CS-Fixer, PHPStan, Deptrac, PHPUnit
make migrate                    # apply the pending Doctrine migrations
make diff                       # generate a migration from the entity mapping
make seed                       # reset the dev database, load sample vehicles, leases and readings and create the dev user
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

In place: Symfony 8.1, PHP 8.5, PostgreSQL 18 with Doctrine ORM and Migrations, Twig and Form, Carbon, Tailwind CSS 4 ([TailwindBundle](https://symfony.com/bundles/TailwindBundle/current/index.html)) on AssetMapper with the Symfony UX Toolkit Shadcn kit (Twig components in `templates/components/`, Stimulus, Turbo, UX Icons with Lucide icons committed in `assets/icons/`), a responsive app shell with a dark mode, a single-user login (Symfony Security, login throttling), English and French, ULID identifiers ([ADR 0002](docs/adr/0002-ulid-identifiers.md)). All dates are handled in UTC ([ADR 0003](docs/adr/0003-utc-dates.md)).

Planned: Redis for Messenger queues, Lock and Cache; Flowbite charts.

## Related repositories

- [polaris-ios-kit](https://github.com/RSickenberg/polaris-ios-kit): iOS translations and push notification handling (Apache-2.0).

## License

[PolyForm Noncommercial 1.0.0](LICENSE). You may run Polaris for yourself, for example on a NAS, in Home Assistant or in your own Docker, but not for commercial purposes and not as a service for others. Versions published before this change remain available under the AGPL-3.0-or-later. A commercial license is available, see [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). External contributions require the [Contributor License Agreement](CLA.md).

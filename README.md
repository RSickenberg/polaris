# Polaris

Polaris tracks your Tesla's odometer against your lease allowance, projects the mileage at the end of the lease and warns you before you go over.

> **Status:** pre-1.0 (0.x). Expect changes between releases; read the release notes before you upgrade.

- Reads the odometer from the official Tesla Fleet API, once or twice a day, without waking the car, plus a manual refresh at most once per hour.
- Compares the kilometres driven with the lease allowance and projects the total at lease end.
- Gives a remaining budget per day, week and month, and alerts when the projection crosses the limit.

Each user connects their own Tesla developer app, so Tesla API usage is billed to their own Tesla developer account. This repository is the source-available, self-hostable edition (personal, noncommercial use, see [ADR 0008](docs/adr/0008-polyform-noncommercial-license.md)), published as a Docker image on GitHub Container Registry.

## Quick start

Requirements: Docker with Compose v2.24 or later.

1. Create your settings file and fill every empty value with a generated secret:

   ```bash
   cp .env.example .env.local
   openssl rand -hex 16        # for each password or secret
   openssl rand -base64 32     # for POLARIS_ENCRYPTION_KEY (back it up: without it, stored Tesla secrets are unreadable)
   ```

   **The encryption key.** `POLARIS_ENCRYPTION_KEY` encrypts everything secret that Polaris stores (Tesla tokens, client secret, app private key; libsodium, [ADR 0007](docs/adr/0007-secrets-encrypted-at-rest.md)). Treat it like a password:
   - Keep it in `.env.local` (never committed) or in the Symfony vault (`make sf ARGS="secrets:set POLARIS_ENCRYPTION_KEY"`), never in the database or the logs.
   - Back it up, but apart from your database dumps.
   - Do not change it once Tesla is connected: the stored values become unreadable, and you must enter your Tesla credentials, generate a new key pair and register again.

2. Build and start everything, then create your user (the password is asked twice, hidden, and needs 12 characters):

   ```bash
   make start
   make sf ARGS="polaris:user:create you@example.com"
   ```

3. Open https://localhost, accept the self-signed certificate and sign in.

A self-hosted Polaris has a single user and no sign-up page. Options: `--locale=fr`, `--time-zone=Europe/Zurich`. Without a terminal, pipe the password on the standard input instead.

`make seed` loads sample vehicles for `dev@example.test` (random password printed once) if you only want to look around.

Forgotten password: use "Forgot your password?" on the login page. It needs a mailer: set `MAILER_DSN`, `MAILER_FROM` and `DEFAULT_URI` (the public address of the app) in `.env.local`. In development, mails go to Mailpit at http://localhost:8025.

## Run the published image

Instead of building it, run the image published on GitHub Container Registry for each release (`linux/amd64` and `linux/arm64`). The tag is the version without the `v` (`0.1.0`); `latest` is the newest stable release. Pin a version rather than following `latest`, and read the release notes before you change it.

1. Get the Compose files of the same release (the image carries the application, not the Compose setup) and your settings file, then fill it as in the Quick start:

   ```bash
   git clone --branch v0.1.0 --depth 1 https://github.com/RSickenberg/polaris.git
   cd polaris
   cp .env.example .env.local
   ```

2. Pull and start it, then create your user:

   ```bash
   export POLARIS_VERSION=0.1.0
   docker compose -f compose.yaml -f compose.image.yaml pull
   docker compose -f compose.yaml -f compose.image.yaml up -d --wait
   docker compose -f compose.yaml -f compose.image.yaml exec php bin/console polaris:user:create you@example.com
   ```

The commands of the next sections use `make sf ARGS="..."`, which runs the development image. With the published image, run `docker compose -f compose.yaml -f compose.image.yaml exec php bin/console ...` instead. The image runs the migrations on start.

## Connect your Tesla

Run the steps in order. Every command runs in the container through `make sf`.

1. **Create the app.** On developer.tesla.com, create a Fleet API app. Its details page shows the **client ID** (a public identifier) and the **client secret** (keep it private); you need both in step 4. Its allowed origin must be the domain that will serve your public key (for example `polaris.example.com`).

2. **Generate the key pair.** The private key is stored encrypted and never shown; the command prints the public key.

   ```bash
   make sf ARGS="polaris:tesla:keygen"
   ```

   It refuses to replace an existing key unless you pass `--force` (you would then register the app again).

   **Already have a key pair** (its public key is published and maybe registered)? Import the private key instead of generating a new one. Polaris derives the public key from it, checks that it is a prime256v1 key (PKCS8 or SEC1 PEM, no passphrase) and stores it encrypted. The file is piped on the standard input, so it never appears in a command argument:

   ```bash
   docker compose exec -T php bin/console polaris:tesla:key:import < private-key.pem
   ```

   Use `docker compose exec -T` here, not `make sf`: `make sf` has no `-T`, so a piped file fails with "cannot attach stdin to a TTY-enabled container". Without piping, copy the file into the container, import it with `--file`, then delete it (not inside the repository folder, where it could be committed):

   ```bash
   docker compose cp private-key.pem php:/tmp/private-key.pem
   make sf ARGS="polaris:tesla:key:import --file=/tmp/private-key.pem"
   docker compose exec php rm /tmp/private-key.pem
   ```

   Then `key:check` (step 5) confirms it is the key served on your domain. Add `--force` to replace a key already stored.

3. **Serve the public key** at `https://<your domain>/.well-known/appspecific/com.tesla.3p.public-key.pem`. Set `POLARIS_KEY_HOSTING` in `.env.local`:
   - `polaris` (default): this app answers that URL, without a login. Nothing else to do.
   - `external`: the key lives on a static site (for example GitHub Pages). Say where it is with either (or both):
     - `POLARIS_KEY_HOSTING_DIR`, a local clone of the site: `keygen` writes the public key inside it; commit and push it yourself.
     - `POLARIS_KEY_HOSTING_URL`, the https URL of the PEM file: Polaris writes nothing, you publish the key you got from `keygen` yourself, and `key:check` reads that URL. Tesla always reads `https://<your domain>/.well-known/...`, so use the URL of that file (or one serving the same key); the check tells you when the URL differs.

4. **Store the credentials.** The client secret is asked with a hidden prompt (or read from the standard input), never from an argument. Options you leave out keep their stored value.

   ```bash
   make sf ARGS="polaris:tesla:configure --client-id=<client ID> --domain=polaris.example.com --region=eu"
   ```

   You are then asked for the client secret (hidden). With several regions and a given owner:

   ```bash
   make sf ARGS="polaris:tesla:configure --client-id=1a2b3c4d-0000-0000-0000-abcdef123456 --domain=keys.example.org --region=na --region=eu --email=you@example.com"
   ```

   Regions: `na` (North America and Asia-Pacific) and `eu` (Europe, Middle East and Africa); repeat `--region` for both. China is not supported: it needs a separate Tesla account and developer app.

   You can also edit the same settings in the web app, under Tesla app in the account menu (`/settings/tesla`). The client secret is write-only there: it is never shown, and leaving it empty keeps the stored one.

5. **Check, then register.**

   ```bash
   make sf ARGS="polaris:tesla:key:check"   # Tesla must be able to read your public key
   make sf ARGS="polaris:tesla:register"    # runs the check first, then registers each enabled region
   ```

   `register` sends nothing to Tesla if the check fails, and shows Tesla's error messages with a hint. The partner token is requested with the scopes `openid vehicle_device_data` (checked on a real run in `eu`); Tesla does not document the register response.

   To look at what Tesla answers for your vehicles (for a bug report, for example), use `make sf ARGS="polaris:tesla:debug:fetch list"`, then `state`, `data` or `cached` followed by a VIN. It prints the whole answer; add `--redact` to remove the VIN, ids, car names, tokens and location before you paste it. `data` is a billed live call, and an asleep car answers 408: the command never wakes it.

6. **Connect your Tesla account**, under Tesla app in the account menu (`/settings/tesla`), button Connect Tesla. You first give an explicit opt-in consent (what is read, how it is stored, how to disconnect), then pick one way:

   - **Sign in with Tesla.** Polaris sends you to Tesla with the scopes `openid offline_access vehicle_device_data vehicle_cmds` (the last one only so that you can wake your car from a refresh) and takes you back to `/tesla/callback`. Add that address to the allowed redirect URIs of your Tesla app. It is `DEFAULT_URI` followed by `/tesla/callback` (the default port is dropped), or the value of `POLARIS_TESLA_REDIRECT_URI` in `.env.local` when set; the connect page shows the exact value, which is sent unchanged to Tesla.

     The Tesla developer portal refuses `https://localhost` and any `http` address on port 80, so for a local install register `http://localhost:8000/tesla/callback` and serve the HTTP port of Docker Compose on 8000. Compose reads `.env`, not `.env.local`, so pass the port on the command line (the Caddy server answers it with a redirect to `https://localhost`, which keeps the code and the state):

     ```bash
     HTTP_PORT=8000 make up
     ```

     and set `POLARIS_TESLA_REDIRECT_URI=http://localhost:8000/tesla/callback` in `.env.local`. A real domain with `https` needs none of this.
   - **Paste the callback URL**, when your browser cannot open the Polaris address (a LAN or `.local` host): start with the button above, then copy the full address from the browser bar after approving and paste it on the same page. It goes through the same state check and code exchange.
   - **Paste a refresh token** obtained outside Polaris. Polaris uses it once right away (Tesla refresh tokens are single use), checks it works and stores only the new tokens.

   Tokens are stored encrypted. Polaris refreshes the access token `POLARIS_TESLA_REFRESH_MARGIN` seconds (600 by default) before it expires, under a lock (`LOCK_DSN`, Redis in Docker Compose), and keeps the new refresh token before using the access token. If Tesla refuses the refresh token (expired after 3 months, replaced, or your Tesla password was reset), the connection is shown as expired on `/settings/tesla` and you connect again. Disconnect there deletes the tokens and withdraws your consent; also revoke the access in your Tesla account.

   The dashboard has a **Refresh now** button that reads the odometer once. A scheduled read never wakes the car, and neither does a refresh until you say so: if the car is asleep (or offline, where Tesla gives no guarantee), a dialog shows what Tesla bills per wake (about $0.02) and asks whether to wake it ("Wake and refresh") or to keep the last reading. After a wake Polaris checks every 10 seconds, for up to 90 seconds, whether the car is online, then reads it. Wakes are capped per vehicle over any 24 hours (3 by default, 0 turns waking off) on the Tesla settings page; a wake does not bypass the one refresh per hour. Waking needs the `vehicle_cmds` scope: if your connection predates it, the dashboard offers "Reconnect to allow waking". Tesla bills every read, so Polaris allows one refresh per vehicle and hour. Set `POLARIS_MANUAL_REFRESH_LIMIT=0` in `.env.local` to lift the limit on your own install.

   To check the connection by hand, refresh the tokens from the console. It never prints a token, only the new expiry. Without `--force` it sends nothing to Tesla unless the token is within the refresh margin; a refresh token is single use, so do not force it needlessly:

   ```bash
   make sf ARGS="polaris:tesla:token:refresh --force"
   ```

7. **Add your vehicles and choose when the odometer is read.** Once Tesla is connected, open Import from Tesla on the home page (`/vehicles/import`), tick the vehicles of your account and import them. Or use the console: `make sf ARGS="polaris:vehicle:import"` adds every new vehicle (`--dry-run` lists them first, `--vin=` picks some). Only the list of your account is read: no car is contacted. To add a vehicle by hand, use `make sf ARGS="polaris:vehicle:add <VIN> 'Model 3'"`. By default Polaris reads it once a day at 21:00 in your time zone (set on the settings page), only when the car is already online: it never wakes the car. If the car is asleep or offline, it checks again every hour for 3 hours, then skips that day. Change the times (one or two a day, `HH:MM`, 24 hours) with `make sf ARGS="polaris:fetch:schedule 07:30 18:00"`; without arguments the command shows the current ones. The `worker` service does the reading, so it must be running. A value is stored only when it changed. Messages that fail are kept in the `failed` queue: list them with `make sf ARGS="messenger:failed:show"` and retry with `messenger:failed:retry`.

## Use it

- **Odometer readings.** Stored in metres and UTC whatever unit you type. Add one on `/vehicles/{id}/readings` (which lists the history) or from the console:

  ```bash
  make sf ARGS="polaris:odometer:add 12345"                                   # km, now, the only vehicle
  make sf ARGS="polaris:odometer:add 7600 --unit=mi --at=2026-10-01T08:00 --vehicle=<VIN or id>"
  ```

  A value lower than the previous reading is rejected; a backfilled reading must fit between its neighbours.

- **Lease terms**, on `/vehicles/{id}/lease`: start and end dates (a whole number of months), the allowance (per year or for the whole term, in km or miles), the odometer at the start, the excess cost per km with its currency (CHF, EUR, GBP, USD) and a tolerance (0 to 50 %). One contract per vehicle; an edit overwrites it.

- **Dashboard**, on `/vehicles/{id}`: the risk level, the share of the allowance driven, the allowance to date, the projection at lease end and the budget per day, week and month. Without a contract or a reading it says so and links to the page that fixes it.

- **Settings**, on `/settings`: email, language, time zone (dates are entered and shown in it, stored in UTC) and password. The interface follows your browser language (English or French) until you pick one.

## How the projection works

Every figure is taken at the latest odometer reading after the lease start.

- **Allowed to date:** the allowance earned so far, linear over the whole term (no yearly reset).
- **Margin:** allowed to date minus the distance driven; negative when over.
- **Pace:** blended as 0.6 x the last 30 days + 0.4 x overall (overall alone during the first 30 days). The weight is `polaris.lease.recent_pace_weight` in `config/services.yaml`, in basis points.
- **Projection (P):** the distance driven plus the blended pace until the lease end.

With A the total allowance:

- **Risk level:** `Over` when P > A; `Watch` when P > A x (1 - tolerance); otherwise `OK`. The tolerance is 0 by default, so `Watch` only appears when you set one.
- **Budget:** what is left of the allowance divided by the time left (a month is the term divided by its number of months).
- **Weekly cut:** (P - A) per week left; negative is the room you have.
- **Excess cost:** (P - A) x the excess cost per km, rounded half up once to the minor unit; 0 while P stays within A.
- **No data / lease ended:** without a reading after the lease start nothing is projected; once the lease has ended, budgets are zero and the excess cost keeps growing with every reading.

## Error reporting (optional)

Polaris can report its errors to [Sentry](https://sentry.io) so that bugs get fixed. It is **off by default**: a fresh install, and any install that does not set the variable below, sends nothing and opens no connection to Sentry.

**Where it goes.** Pick one in `.env.local` (see `.env.example`):

| `POLARIS_ERROR_REPORTING` | Reports go to |
|---|---|
| `off` or empty (default) | nowhere |
| `maintainer` | the Sentry project of the Polaris maintainer. Its address ships with the image, so it can change with a release without you editing anything |
| `custom` | your own Sentry project: set `SENTRY_DSN` to its DSN |

`SENTRY_TRACES_SAMPLE_RATE` (0 to 1, default `0.05`) is the share of requests and background jobs traced for performance; `0` sends no trace. Reporting only exists in the production image (`APP_ENV=prod`), and the image carries its version as the release, so a report names the version you run.

**What is sent**, once you opted in and the user did not opt out:

- errors (exception type and message, stack trace without function arguments or variables), and `warning` and `error` log lines written by Polaris;
- performance traces: the duration of requests, jobs, database queries (SQL text, never the values) and calls to Tesla (URL without the VIN);
- your user ID (a random ULID, no email, name or address), the environment (`prod`) and the release;
- odometer values and lease figures that appear in an error or a log line (for example the two values of a reading that was refused);
- the answer of the car (battery, charge, climate, doors and so on) only if the user ticked "Include what the car reports" in Settings. It never contains the location.

**What is never sent**: email addresses, passwords, Tesla tokens, client secrets, private keys, `POLARIS_ENCRYPTION_KEY`, VINs, locations, IP addresses, cookies, request or response bodies, request headers, query strings, database query parameters, function arguments and variables of the stack trace. A scrubber removes VINs, tokens, keys and emails from every message, URL and span before it leaves, and no trace header (`sentry-trace`, `baggage`) is ever added to a request to Tesla or to any other host. No profiling, session replay or cron monitoring.

**Opting out.**

- Instance: unset `POLARIS_ERROR_REPORTING` (or set it to `off`) and restart.
- User: *Settings* > *Error reporting*, untick "Let errors about my account be reported". Nothing about that user's activity is sent from the next report on, in requests and in background jobs. An error that cannot be tied to a known user is dropped, not sent. Errors with no user at all (the app failing to boot, the scheduler tick) follow the instance switch only.
- Accounts created before this feature existed start opted out; new accounts start opted in, which only matters once the instance owner turned reporting on.

## Development

The setup is based on [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker) and runs on [FrankenPHP](https://frankenphp.dev) with PHP 8.5. Every PHP command runs in the `php` container through the `Makefile` (`make` alone lists the targets).

| Service | Role | Host port (dev) |
| --- | --- | --- |
| `php` | FrankenPHP web server | https://localhost |
| `worker` | Scheduler tick and `messenger:consume scheduler_default async tesla` | none |
| `database` | PostgreSQL 18, UTC | `127.0.0.1:5432` |
| `redis` | Redis 8 | `127.0.0.1:6379` |
| `mailer` | Mailpit | UI http://localhost:8025, SMTP `127.0.0.1:1025` |

```bash
make sh                         # shell in the php container
make test                       # PHPUnit (ARGS="--filter=..."); builds the CSS and prepares the test database
make ci                         # every CI check: PHP-CS-Fixer, PHPStan, Deptrac, translations, PHPUnit
make migrate                    # apply the pending Doctrine migrations
make diff                       # generate a migration from the entity mapping
make seed                       # reset the dev database, load sample data, create the dev user
make cs-fix                     # apply the coding standard
make sf ARGS="about"            # any bin/console command
make composer ARGS="outdated"   # any Composer command
make down                       # stop everything (docker compose down -v also deletes the data)
```

- **CSS:** the `tailwind` service rebuilds the stylesheet in dev; run `make tailwind` once on a fresh checkout before `make test` (`test` and `ci` do it for you).
- **Ports:** change them with `HTTP_PORT`, `HTTPS_PORT`, `HTTP3_PORT`, `DATABASE_PORT`, `REDIS_PORT`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`. Xdebug is in the dev image: `XDEBUG_MODE=debug docker compose up -d`.
- **Production image locally:** `docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait`.
- **Dates:** everything runs in UTC ([ADR 0003](docs/adr/0003-utc-dates.md)); the time zone of a user only converts for display.
- **Schema:** it changes only through Doctrine migrations in `migrations/`; the `php` container applies pending ones at start.
- **Without Docker:** PHP 8.5 and Composer are enough for the console and the unit tests (`composer install`, `vendor/bin/phpunit tests/Unit`). Functional tests need PostgreSQL via `DATABASE_URL` in `.env.local`.

### Quality checks

CI runs one job per tool on every pull request and on `main`. Run them locally with `make ci`, or one at a time:

| Check | Command |
| --- | --- |
| PHPUnit | `composer test` / `make test` |
| PHPStan, level max | `composer phpstan` / `make phpstan` |
| PHP-CS-Fixer (`@Symfony`, PHP 8.5 migration), dry run | `composer cs` / `make cs` |
| Deptrac, the module boundaries of [ADR 0001](docs/adr/0001-feature-modules.md) | `composer deptrac` / `make deptrac` |
| Translations (`en` and `fr` complete) | `composer translations` / `make translations` |

Fix findings rather than adding PHPStan baselines or ignores.

## Repository layout

A standard Symfony application at the root (namespace `Polaris\`): `src/` is organized by feature module ([ADR 0001](docs/adr/0001-feature-modules.md)), with `config/`, `migrations/`, `templates/`, `translations/`, `tests/` (`Unit/` and `Functional/`) and `docs/adr/` for the decision records. The private hosted platform builds on this application's Docker image; nothing hosted-only lives here.

Stack: Symfony 8.1, PHP 8.5, PostgreSQL 18 with Doctrine, Twig, Carbon, Tailwind CSS 4 on AssetMapper with the Symfony UX Toolkit Shadcn kit (Stimulus, Turbo, Lucide icons), Symfony Security, English and French, ULID identifiers ([ADR 0002](docs/adr/0002-ulid-identifiers.md)). Planned: Redis for Messenger, Lock and Cache; Flowbite charts.

## Related repositories

- [polaris-ios-kit](https://github.com/RSickenberg/polaris-ios-kit): iOS translations and push notification handling (Apache-2.0).

## License

[PolyForm Noncommercial 1.0.0](LICENSE). You may run Polaris for yourself, for example on a NAS, in Home Assistant or in your own Docker, but not for commercial purposes and not as a service for others. Versions published before this change remain available under the AGPL-3.0-or-later. A commercial license is available, see [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). External contributions require the [Contributor License Agreement](CLA.md).

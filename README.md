# Polaris

Polaris tracks your Tesla's odometer against your lease allowance, projects the mileage at lease end and warns you before you go over.

- Reads the odometer from the official Tesla Fleet API once or twice a day, without waking the car.
- Projects the total at lease end and gives a remaining budget per day, week and month.
- Manual readings always work, with or without Tesla.

> **Status:** pre-1.0 (0.x). Read the release notes before you upgrade.

This is the self-hostable edition: one user, personal and noncommercial use. You connect your own Tesla developer app, so Tesla bills API usage to your own developer account.

## Install

Requirements: Docker with Compose v2.24 or later.

### 1. Get the files

```bash
git clone --branch v0.1.0 --depth 1 https://github.com/RSickenberg/polaris.git
cd polaris
cp .env.example .env.local
```

The image carries the application, not the Compose setup, so take the files of the release you run.

### 2. Fill `.env.local`

| Variable | Value |
| --- | --- |
| `APP_SECRET`, `POSTGRES_PASSWORD` | `openssl rand -hex 16` |
| `POLARIS_ENCRYPTION_KEY` | `openssl rand -base64 32` (read the warning below) |
| `MAILER_DSN`, `MAILER_FROM`, `DEFAULT_URI` | Your SMTP server, the sender address and the public address of the app. Needed for password reset. |

> [!IMPORTANT]
> `POLARIS_ENCRYPTION_KEY` encrypts the Tesla tokens, client secret and private key.
> - Back it up, apart from your database dumps.
> - Never change it once Tesla is connected: the stored values become unreadable and you must connect and register again.
> - Keep it in `.env.local` (never committed) or the Symfony vault (`make sf ARGS="secrets:set POLARIS_ENCRYPTION_KEY"`), never in the database or the logs.

### 3. Start

**Published image** (`linux/amd64` and `linux/arm64`, tag = version without the `v`). Pin a version rather than `latest`:

```bash
export POLARIS_VERSION=0.1.0   # Compose reads it from the shell or .env, not .env.local
docker compose -f compose.yaml -f compose.image.yaml pull
docker compose -f compose.yaml -f compose.image.yaml up -d --wait
```

**Or build from source:** `make start`.

Migrations run when the container starts.

### 4. Create your user and sign in

```bash
make sf ARGS="polaris:user:create you@example.com"
```

The password is asked twice, hidden, 12 characters minimum (or piped on stdin without a terminal). Options: `--locale=fr`, `--time-zone=Europe/Zurich`. There is one user and no sign-up page.

Open https://localhost, accept the self-signed certificate and sign in.

> [!NOTE]
> This README writes console commands as `make sf ARGS="<command>"`, which runs the development image. With the published image, run `docker compose -f compose.yaml -f compose.image.yaml exec php bin/console <command>` instead.

## Connect your Tesla

Run the steps in order.

### 1. Create a Tesla app

On developer.tesla.com, create a Fleet API app. Note its **client ID** and **client secret** (keep the secret private). Set its allowed origin to the domain that will serve your public key, for example `polaris.example.com`.

### 2. Generate the key pair

```bash
make sf ARGS="polaris:tesla:keygen"
```

It prints the public key and stores the private key encrypted (never shown). It refuses to replace an existing key unless you pass `--force`; you would then register again.

<details>
<summary>Already have a key pair? Import it instead</summary>

The key must be prime256v1, PKCS8 or SEC1 PEM, without a passphrase. Pipe it on stdin so it never appears in a command argument:

```bash
docker compose exec -T php bin/console polaris:tesla:key:import < private-key.pem
```

Use `docker compose exec -T`, not `make sf`: `make sf` has no `-T`, so a piped file fails with "cannot attach stdin to a TTY-enabled container". Without piping, copy the file in, import it, then delete it (outside the repository folder, where it could be committed):

```bash
docker compose cp private-key.pem php:/tmp/private-key.pem
make sf ARGS="polaris:tesla:key:import --file=/tmp/private-key.pem"
docker compose exec php rm /tmp/private-key.pem
```

Add `--force` to replace a stored key. Step 5 confirms it matches the key served on your domain.
</details>

### 3. Serve the public key

Tesla reads it at `https://<your domain>/.well-known/appspecific/com.tesla.3p.public-key.pem`. Set `POLARIS_KEY_HOSTING` in `.env.local`:

- `polaris` (default): Polaris answers that URL, without a login. Nothing else to do.
- `external`: the key lives on a static site (for example GitHub Pages). Set one or both:
  - `POLARIS_KEY_HOSTING_DIR`: a local clone of the site. `keygen` writes the key into it; you commit and push.
  - `POLARIS_KEY_HOSTING_URL`: the https URL of the PEM file. You publish the key yourself and `key:check` reads that URL. Use the URL Tesla reads (or one serving the same key); the check warns when it differs.

### 4. Store the credentials

```bash
make sf ARGS="polaris:tesla:configure --client-id=<client ID> --domain=polaris.example.com --region=eu"
```

You are then asked for the client secret (hidden, or from stdin, never an argument). Options you leave out keep their stored value.

- `--region`: `na` (North America and Asia-Pacific) or `eu` (Europe, Middle East and Africa). Repeat it for both. China is not supported.
- `--email=you@example.com`: the owner of the app.

The same settings are on the web, under **Tesla app** in the account menu (`/settings/tesla`). The client secret is write-only there; leave it empty to keep the stored one.

### 5. Check, then register

```bash
make sf ARGS="polaris:tesla:key:check"   # Tesla must be able to read your public key
make sf ARGS="polaris:tesla:register"    # runs the check first, then registers each enabled region
```

`register` sends nothing to Tesla if the check fails, and shows Tesla's errors with a hint.

### 6. Connect your Tesla account

Go to `/settings/tesla` > **Connect Tesla**, give your consent, then choose one way:

- **Sign in with Tesla.** Add the redirect URI shown on the connect page to the allowed redirect URIs of your Tesla app (by default `DEFAULT_URI` + `/tesla/callback`, or `POLARIS_TESLA_REDIRECT_URI` when set).
- **Paste the callback URL**, when your browser cannot open the Polaris address (LAN or `.local` host): start with the button above, approve, then paste the full address from the browser bar.
- **Paste a refresh token** obtained elsewhere. Polaris uses it once right away (Tesla refresh tokens are single use) and keeps only the new tokens.

Requested scopes: `openid offline_access vehicle_device_data vehicle_cmds` (`vehicle_cmds` only to wake the car from a refresh you confirm).

<details>
<summary>Local install on localhost</summary>

The Tesla portal refuses `https://localhost` and any `http` address on port 80. Instead:

1. Register `http://localhost:8000/tesla/callback` in your Tesla app.
2. Set `POLARIS_TESLA_REDIRECT_URI=http://localhost:8000/tesla/callback` in `.env.local`.
3. Start with `HTTP_PORT=8000 make up` (Compose reads `.env`, not `.env.local`). Caddy redirects that port to `https://localhost`, keeping the code and state.

A real domain with `https` needs none of this.
</details>

### 7. Import your vehicles

Open **Import from Tesla** on the home page (`/vehicles/import`), or:

```bash
make sf ARGS="polaris:vehicle:import"            # --dry-run lists first, --vin= picks some
make sf ARGS="polaris:vehicle:add <VIN> 'Model 3'"   # add one by hand
```

Only your account's vehicle list is read; no car is contacted.

### 8. Choose when the odometer is read (optional)

By default once a day at 21:00 in your time zone. Set one or two times (`HH:MM`, 24 h); without arguments the command shows the current ones:

```bash
make sf ARGS="polaris:fetch:schedule 07:30 18:00"
```

The `worker` service does the reading and must be running. A scheduled read never wakes the car: if it is asleep or offline, Polaris checks again every hour for 3 hours, then skips that day. A value is stored only when it changed.

## Use it

1. **Enter the lease terms** on `/vehicles/{id}/lease`: start and end dates (whole months), allowance (per year or whole term, km or miles), odometer at start, excess cost per km (CHF, EUR, GBP, USD) and a tolerance (0 to 50 %). One contract per vehicle; saving overwrites it.
2. **Read the dashboard** on `/vehicles/{id}`: risk level, share of the allowance driven, allowance to date, projection at lease end and budget per day, week and month. If a contract or reading is missing, it links to the page that fixes it.
3. **Add readings by hand** when needed, on `/vehicles/{id}/readings` (with the history) or:

   ```bash
   make sf ARGS="polaris:odometer:add 12345"   # km, now, the only vehicle
   make sf ARGS="polaris:odometer:add 7600 --unit=mi --at=2026-10-01T08:00 --vehicle=<VIN or id>"
   ```

   A value lower than the previous reading is rejected; a backfilled one must fit between its neighbours.

**Settings** (`/settings`): email, language (follows your browser, English or French, until you pick one), time zone (dates are shown in it, stored in UTC) and password. Forgot your password? Use the link on the login page (needs the mailer settings).

### Refresh now and waking the car

- **Refresh now** on the dashboard reads the odometer once. Tesla bills every read, so it is limited to once per vehicle and hour; set `POLARIS_MANUAL_REFRESH_LIMIT=0` in `.env.local` to lift it.
- If the car is asleep or offline, a dialog shows the cost of a wake (about $0.02) and asks: **Wake and refresh**, or keep the last reading. Polaris then checks every 10 seconds, for up to 90 seconds, whether the car came online.
- Wakes are capped per vehicle over 24 hours (3 by default, 0 turns waking off) on `/settings/tesla`. A wake still counts against the hourly refresh.
- A connection made before waking existed shows **Reconnect to allow waking**.

### Tesla tokens

Tokens are stored encrypted and refreshed `POLARIS_TESLA_REFRESH_MARGIN` seconds (600 by default) before they expire, under a lock (`LOCK_DSN`, Redis in Compose). If Tesla refuses the refresh token (unused for 3 months, replaced, or your Tesla password was reset), `/settings/tesla` shows the connection as expired: connect again. **Disconnect** there deletes the tokens and withdraws your consent; also revoke the access in your Tesla account.

## Troubleshooting

| To | Run |
| --- | --- |
| Test the token refresh | `make sf ARGS="polaris:tesla:token:refresh --force"`. Prints only the new expiry. Refresh tokens are single use: do not force it needlessly. |
| See Tesla's raw answer (for a bug report) | `make sf ARGS="polaris:tesla:debug:fetch list"`, then `state`, `data` or `cached` followed by a VIN. Add `--redact` to remove VIN, ids, car names, tokens and location. `data` is a billed live call; an asleep car answers 408 and is never woken. |
| List or retry failed reads | `make sf ARGS="messenger:failed:show"`, then `messenger:failed:retry` |

## How the projection works

Every figure is taken at the latest odometer reading after the lease start. With P the projection and A the total allowance:

| Figure | Rule |
| --- | --- |
| Allowed to date | The allowance earned so far, linear over the whole term (no yearly reset). |
| Margin | Allowed to date minus distance driven; negative when over. |
| Pace | 0.6 x last 30 days + 0.4 x overall (overall alone during the first 30 days). Weight: `polaris.lease.recent_pace_weight` in `config/services.yaml`, in basis points. |
| Projection (P) | Distance driven plus the pace until lease end. |
| Risk level | `Over` when P > A; `Watch` when P > A x (1 - tolerance); otherwise `OK`. With the default tolerance of 0, `Watch` never appears. |
| Budget | Allowance left divided by time left (a month is the term divided by its number of months). |
| Weekly cut | (P - A) per week left; negative is the room you have. |
| Excess cost | (P - A) x excess cost per km, rounded half up once to the minor unit; 0 while P stays within A. |

Without a reading after the lease start, nothing is projected. Once the lease has ended, budgets are zero and the excess cost grows with every reading.

## Development

Based on [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker), running [FrankenPHP](https://frankenphp.dev) with PHP 8.5. Every PHP command runs in the `php` container through the `Makefile` (`make` alone lists the targets).

| Service | Role | Host port (dev) |
| --- | --- | --- |
| `php` | FrankenPHP web server | https://localhost |
| `worker` | Scheduler tick and `messenger:consume scheduler_default async tesla` | none |
| `database` | PostgreSQL 18, UTC | `127.0.0.1:5432` |
| `redis` | Redis 8 | `127.0.0.1:6379` |
| `mailer` | Mailpit | UI http://localhost:8025, SMTP `127.0.0.1:1025` |

```bash
make start                      # build and start everything
make seed                       # reset the dev database, load sample vehicles for dev@example.test (password printed once)
make sh                         # shell in the php container
make test                       # PHPUnit (ARGS="--filter=..."); builds the CSS and prepares the test database
make ci                         # every CI check: PHP-CS-Fixer, PHPStan, Deptrac, translations, PHPUnit
make migrate                    # apply pending Doctrine migrations
make diff                       # generate a migration from the entity mapping
make cs-fix                     # apply the coding standard
make sf ARGS="about"            # any bin/console command
make composer ARGS="outdated"   # any Composer command
make down                       # stop everything (docker compose down -v also deletes the data)
```

Each CI check also runs alone: `make phpstan` (level max), `make cs` (dry run), `make deptrac` (module boundaries), `make translations` (`en` and `fr` complete), `make test`; or the same names with `composer`. Fix findings rather than adding PHPStan baselines or ignores.

- **CSS:** the `tailwind` service rebuilds it in dev; on a fresh checkout run `make tailwind` once (`test` and `ci` do it for you).
- **Ports:** `HTTP_PORT`, `HTTPS_PORT`, `HTTP3_PORT`, `DATABASE_PORT`, `REDIS_PORT`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`.
- **Xdebug:** `XDEBUG_MODE=debug docker compose up -d`.
- **Production image locally:** `docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait`.
- **Mail in dev:** Mailpit at http://localhost:8025.
- **Dates:** UTC everywhere; the user's time zone only converts for display.
- **Schema:** only through Doctrine migrations in `migrations/`; the `php` container applies pending ones at start.
- **Without Docker:** PHP 8.5 and Composer run the console and unit tests (`composer install`, `vendor/bin/phpunit tests/Unit`). Functional tests need PostgreSQL via `DATABASE_URL` in `.env.local`.

### Repository layout and stack

A standard Symfony application at the root (namespace `Polaris\`). `src/` is organized by feature module. The private hosted platform builds on this application's Docker image; nothing hosted-only lives here.

Stack: Symfony 8.1, PHP 8.5, PostgreSQL 18 with Doctrine, Messenger and Lock on Redis, Twig, Carbon, Tailwind CSS 4 on AssetMapper with the Symfony UX Toolkit Shadcn kit (Stimulus, Turbo, Lucide icons), ApexCharts, Symfony Security, English and French, ULID identifiers. Planned: Redis as the cache.

## Related repositories

- [polaris-ios-kit](https://github.com/RSickenberg/polaris-ios-kit): iOS translations and push notification handling (Apache-2.0).

## License

[PolyForm Noncommercial 1.0.0](LICENSE). Run Polaris for yourself (on a NAS, in Home Assistant, in your own Docker), but not for commercial purposes and not as a service for others. Versions published before this change remain available under the AGPL-3.0-or-later. A commercial license is available: see [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). External contributions require the [Contributor License Agreement](CLA.md).

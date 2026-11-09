# Self-hosting guide

How to run Polaris at home (a NAS, a small VPS, a Home Assistant host) with Docker, and connect it to your Tesla. You need Docker and a terminal. You do not need to know Symfony or the Tesla Fleet API.

Every command can be pasted as is. Values in `<angle brackets>` are yours to fill in.

## Contents

- [What you are installing](#what-you-are-installing)
- [Requirements](#requirements)
- [Choose a setup](#choose-a-setup)
- [1. Get the files](#1-get-the-files)
- [2. Configure](#2-configure)
- [3. Start](#3-start)
- [The console command](#the-console-command)
- [4. Create your user](#4-create-your-user)
- [Environment variables](#environment-variables)
- [Tesla app registration](#tesla-app-registration)
- [Backups](#backups)
- [Restore](#restore)
- [Upgrades](#upgrades)
- [Troubleshooting](#troubleshooting)
- [What was and was not tested](#what-was-and-was-not-tested)

## What you are installing

Four containers started by Docker Compose:

| Service | Role | Keeps data in |
| --- | --- | --- |
| `php` | The web server (FrankenPHP with Caddy). Runs the database migrations when it starts. | `caddy_data`, `caddy_config` |
| `worker` | Reads the odometer at your times and runs the daily jobs. | none |
| `database` | PostgreSQL 18. Your readings, lease and encrypted Tesla secrets. | `database_data` |
| `redis` | Redis 8. The job queue and locks. | `redis_data` |

Polaris is for one person and one vehicle, for personal and noncommercial use ([licence](../LICENSE)). You connect your own Tesla developer app, and Tesla bills its use to your own developer account ([ADR 0004](adr/0004-bring-your-own-tesla-app.md)).

## Requirements

- **Docker** with **Compose v2.24 or later**. The Compose files use `env_file` with `required: false`; Docker's reference documents that form, but I could not confirm from Compose's release notes in which version it appeared, so 2.24 is the version the project has always asked for. Check with `docker compose version`.
- **CPU:** `amd64` or `arm64` (the published image has both; a Raspberry Pi 4 or 5 on a 64-bit system is `arm64`).
- **Memory and disk**, measured on Docker Desktop (`arm64`) with one vehicle and one reading, idle:

  | | Measured |
  | --- | --- |
  | Memory of the four containers (`docker stats`) | `php` 112 MiB, `worker` 34 MiB, `database` 33 MiB, `redis` 7 MiB: about 190 MiB in all |
  | Images on disk | Polaris 659 MB, PostgreSQL 425 MB, Redis 160 MB: about 1.2 GB |
  | Volumes of a new install | `database_data` 49 MB, the other three a few kB each |

  A year of readings is small; I measured an empty install, not a full one.
- **Inbound ports:** 80 and 443 (TCP) and 443 (UDP, HTTP/3) when you use Polaris' own HTTPS. Port 80 is needed to get the certificate and redirects to HTTPS.
- **Outbound access** from the host:

  | To | For |
  | --- | --- |
  | `ghcr.io` | Pulling the Polaris image |
  | `auth.tesla.com`, `fleet-auth.prd.vn.cloud.tesla.com` | Signing in to Tesla and refreshing tokens |
  | `fleet-api.prd.eu.vn.cloud.tesla.com` or `fleet-api.prd.na.vn.cloud.tesla.com` | The Fleet API, for your region |
  | Your SMTP server | Password reset emails |
  | Let's Encrypt (or ZeroSSL) | The HTTPS certificate of a public domain |
  | Sentry | Only if you turn on [error reporting](../README.md#error-reporting-optional) |

## Choose a setup

| Setup | When | Status |
| --- | --- | --- |
| **1. Public domain, Polaris' own HTTPS** | The simplest: a domain name points at your host, ports 80 and 443 reach it, and Caddy (inside the `php` container) gets the certificate by itself. | The stack, the login and the public key URL were run on `localhost` with Caddy's own certificate. Getting a certificate for a public name was **not run**: it is Caddy's documented behaviour. |
| **2. Behind your own reverse proxy** | You already run Traefik, Nginx Proxy Manager or similar on 80 and 443. | **Untested.** Notes below. |
| **3. Home network only** | No public domain. | **Untested.** Notes below. |

Tesla must be able to read your public key over `https` on a domain it can reach (see [Serve the public key](#3-serve-the-public-key)). A Polaris that only answers on your home network cannot serve it to Tesla.

<details>
<summary>Setup 2 notes (untested)</summary>

- Your proxy terminates HTTPS and forwards to the `php` container. Do not publish ports 80 and 443 of Polaris on the host if the proxy uses them: set `HTTP_PORT`, `HTTPS_PORT` and `HTTP3_PORT` in `.env` to free ports (for example `8080`, `8443`, `8443`) and send the proxy to the `php` service.
- `SERVER_NAME` in `.env` is the site address Caddy answers on. With a plain `http://` address Caddy does not ask for a certificate, for example `SERVER_NAME=http://polaris.example.com`.
- Set `TRUSTED_PROXIES` in `.env.local` to the address of your proxy as the container sees it (comma-separated, for example `172.18.0.0/16`). Polaris then trusts `X-Forwarded-For`, `-Proto`, `-Port` and `-Host`. Empty (the default) trusts none.
- `DEFAULT_URI` stays the public `https://` address.
</details>

<details>
<summary>Setup 3 notes (untested)</summary>

- Use `SERVER_NAME=polaris.lan` or similar. Caddy makes a certificate your browser does not trust: accept the warning.
- Tesla must still read the public key over `https`. Put the key on a static site you control and set `POLARIS_KEY_HOSTING=external` (see [Serve the public key](#3-serve-the-public-key)). Polaris itself then never has to be reachable from the internet.
- Tesla's sign-in redirects your browser to the redirect URI of your Tesla developer app. On a LAN host that address may not open in your browser: use the **Paste the callback URL** way to connect (see [Connect your Tesla account](#6-connect-your-tesla-account)).
- The Tesla portal refuses `https://localhost` and any `http` address on port 80 as redirect URI. For a try on one computer, register `http://localhost:8000/tesla/callback`, set `POLARIS_TESLA_REDIRECT_URI` to the same value in `.env.local`, and set `HTTP_PORT=8000` in `.env`: Caddy redirects that port to `https://localhost`, keeping the code and state.
</details>

## 1. Get the files

The image carries the application, not the Compose files, so take the files of the release you run. Pick a version on the [releases page](https://github.com/RSickenberg/polaris/releases) and use it without the `v` where the text says `<version>` (for example `0.5.0`).

```bash
mkdir polaris && cd polaris
for f in compose.yaml compose.image.yaml; do
  curl -fsSLO "https://raw.githubusercontent.com/RSickenberg/polaris/v<version>/$f"
done
```

Replace `<version>` in the URL too. The commands in this guide run from this folder.

> [!IMPORTANT]
> Pin a version and keep it in `.env` (next step). There is no `latest` tag until the first stable release. The `nightly` image is built every night from the `dev` branch and replaces itself: it can break, and its database migrations are not guaranteed to upgrade to the next release. Do not use it for data you care about.

## 2. Configure

Two files, because Docker Compose and the application read different ones:

- **`.env`** is read by Docker Compose itself: which files to use, the version, the address and the ports.
- **`.env.local`** holds the secrets and the application settings. Every container gets its variables.

### `.env`

```bash
cat > .env <<'EOF'
COMPOSE_FILE=compose.yaml:compose.image.yaml
POLARIS_VERSION=<version>
SERVER_NAME=polaris.example.com
EOF
```

Replace the version and the domain. `COMPOSE_FILE` makes every later `docker compose` command use the published image, so you never type `-f`. `SERVER_NAME` is the domain Caddy gets a certificate for (default `localhost`).

### `.env.local`

This writes the three secrets for you. Replace the last three values with yours:

```bash
cat > .env.local <<EOF
APP_SECRET=$(openssl rand -hex 16)
POSTGRES_PASSWORD=$(openssl rand -hex 16)
POLARIS_ENCRYPTION_KEY=$(openssl rand -base64 32)
DEFAULT_URI=https://polaris.example.com
MAILER_DSN=smtp://<user>:<password>@smtp.example.com:587
MAILER_FROM=polaris@example.com
EOF
chmod 600 .env.local
```

`DEFAULT_URI` is the public address of the app, with the port if it is not 443. Emails (password reset) and the default Tesla redirect address are built from it. `MAILER_DSN` and `MAILER_FROM` are needed for the password reset; without a real `MAILER_DSN` no email leaves the app. The other variables are in the [table below](#environment-variables).

> [!IMPORTANT]
> `POLARIS_ENCRYPTION_KEY` encrypts your Tesla tokens, client secret and private key ([ADR 0007](adr/0007-secrets-encrypted-at-rest.md)).
> - Save it now, apart from your database backups (a password manager). A backup of the database without it cannot be used for Tesla.
> - Never change it once Tesla is connected: the stored values become unreadable and you must connect and register again.
> - Never put it in the database, a log or a repository.

## 3. Start

```bash
docker compose pull
docker compose up -d --wait
```

`--wait` returns when the four services are healthy. The `php` container creates the database tables when it starts (it applies pending migrations every time). Open `https://<your domain>/login`: you should see the sign-in page. There is no user yet.

## The console command

Some steps use Polaris' console. In this guide it is always written as:

```bash
docker compose exec php bin/console <command>
```

Two rules:

- Keep the command as it is on a terminal. When a command reads a **file or a secret piped on standard input**, add `-T` after `exec` (`docker compose exec -T php bin/console ...`); without it Docker answers "cannot attach stdin to a TTY-enabled container" or the input is lost.
- `docker compose exec` works only while the stack is running.

`docker compose exec php bin/console list polaris` lists every Polaris command.

## 4. Create your user

```bash
docker compose exec php bin/console polaris:user:create you@example.com --time-zone=Europe/Zurich --locale=en
```

The password is asked twice, hidden, 12 characters at least. `--locale` is `en` or `fr`; both options are optional. There is one user and no sign-up page ([ADR 0006](adr/0006-single-user-login.md)): the command refuses a second user.

Without a terminal (a script), send the password twice on standard input, one per line:

```bash
printf '%s\n%s\n' '<password>' '<password>' | docker compose exec -T php bin/console polaris:user:create you@example.com
```

Open `https://<your domain>/login` and sign in. A forgotten password is reset from the link on the login page, which needs the mailer settings.

Next, add the lease terms and readings as described in [Use it](../README.md#use-it) of the README. Manual readings always work, with or without Tesla. Read on to connect your Tesla.

## Environment variables

"Compose" means `.env` (or your shell), read by Docker Compose. "App" means `.env.local`.

| Variable | Required | Default | Example | Set in | What it does |
| --- | --- | --- | --- | --- | --- |
| `COMPOSE_FILE` | yes | none | `compose.yaml:compose.image.yaml` | Compose | The Compose files to use, so `docker compose` runs the published image. |
| `POLARIS_VERSION` | yes | none (Compose stops and says so) | `0.5.0` | Compose | The image tag. |
| `SERVER_NAME` | for a public domain | `localhost` | `polaris.example.com` | Compose | The site address Caddy serves and gets a certificate for. |
| `HTTP_PORT` | no | `80` | `8080` | Compose | Host port for HTTP. |
| `HTTPS_PORT` | no | `443` | `8443` | Compose | Host port for HTTPS. |
| `HTTP3_PORT` | no | `443` | `8443` | Compose | Host port for HTTP/3 (UDP). |
| `POSTGRES_VERSION` | no | `18` | `18` | Compose | PostgreSQL image tag. Do not change a major version of existing data by hand. |
| `POSTGRES_USER`, `POSTGRES_DB` | no | `polaris` | | Compose | **Leave them.** The application connects as `polaris` to the database `polaris`; changing these breaks the connection. |
| `APP_SECRET` | yes | none | `<generated>` | App | Signs sessions and links. 32 random characters (`openssl rand -hex 16`). |
| `POSTGRES_PASSWORD` | yes | none | `<generated>` | App | The database password: the database container creates the account with it, the app connects with it. Set it before the first start; changing it later does not change the database. |
| `POLARIS_ENCRYPTION_KEY` | yes | none | `<generated>` | App | Encrypts the Tesla secrets. `openssl rand -base64 32`. Back it up; never change it. |
| `DEFAULT_URI` | yes for a real install | `http://localhost` | `https://polaris.example.com` | App | The public address of the app, with the port if not 443. Used in emails and the default Tesla redirect address. |
| `MAILER_DSN` | for password reset | `null://null` (no email) | `smtp://user:pass@smtp.example.com:587` | App | Where to send emails. |
| `MAILER_FROM` | for password reset | `polaris@localhost` | `polaris@example.com` | App | The sender address of emails. |
| `TRUSTED_PROXIES` | behind a proxy | empty (none trusted) | `172.18.0.0/16` | App | Addresses of your reverse proxy, comma-separated. |
| `POLARIS_KEY_HOSTING` | no | `polaris` | `external` | App | Where Tesla finds the public key: `polaris` (this app) or `external` (a static site). |
| `POLARIS_KEY_HOSTING_DIR` | with `external` | empty | `/keys` | App | A folder of the container where `keygen` writes the PEM. |
| `POLARIS_KEY_HOSTING_URL` | with `external` | empty | `https://keys.example.com/.well-known/appspecific/com.tesla.3p.public-key.pem` | App | The https URL of the PEM you publish yourself; `key:check` reads it. |
| `POLARIS_TESLA_REDIRECT_URI` | no | `DEFAULT_URI` + `/tesla/callback` | `http://localhost:8000/tesla/callback` | App | The OAuth redirect address, sent unchanged to Tesla. |
| `POLARIS_TESLA_REFRESH_MARGIN` | no | `600` | `600` | App | Seconds before expiry at which the Tesla token is renewed. |
| `POLARIS_MANUAL_REFRESH_LIMIT` | no | `1` | `0` | App | `1` limits "Refresh now" to once an hour per vehicle (Tesla bills each read); `0` lifts the limit. |
| `POLARIS_ERROR_REPORTING` | no | `off` | `custom` | App | `off`, `maintainer` or `custom`. See [Error reporting](../README.md#error-reporting-optional) for what is sent. |
| `SENTRY_DSN` | with `custom` | empty | `<your DSN>` | App | Your own Sentry project. |
| `SENTRY_TRACES_SAMPLE_RATE` | no | `0.05` when empty | `0` | App | Share of requests traced, 0 to 1. |

Set by the Compose files, so do not set them: `APP_ENV` (`prod` in the image), `DATABASE_URL`, `REDIS_URL`, `LOCK_DSN`, `MESSENGER_TRANSPORT_DSN`.

The `database` and `redis` containers also load `.env.local`. They ignore what they do not use; keep the file private (`chmod 600`).

## Tesla app registration

Polaris reads your odometer through the official Tesla Fleet API with **your own** Tesla developer app. The order matters: do each step before the next.

Tesla's documentation was read on 2026-11-09. Where it does not describe a screen or a rule, this guide says "not verified" instead of describing it from memory.

### 1. Create a Tesla developer app

Sources: [Fleet API, Getting started](https://developer.tesla.com/docs/fleet-api/getting-started/what-is-fleet-api) (read 2026-11-09), [Billing and limits](https://developer.tesla.com/docs/fleet-api/billing-and-limits) (read 2026-11-09).

- A Tesla account with a verified email and multi-factor authentication turned on.
- Request an application on developer.tesla.com. Tesla asks for legal business details, an application name (unique), a description and the purpose of use, and the scopes. Whether a private individual is accepted, how the request is approved and what the portal screens look like: **not verified** (Tesla's page does not describe them).
- Ask for the scopes `openid`, `offline_access`, `vehicle_device_data` and `vehicle_cmds`. `vehicle_cmds` is used only to wake the car from a refresh you confirmed.
- Note the app's **client ID** and **client secret**. Tesla's getting-started page does not mention them, where they appear is **not verified**. Keep the secret private.
- **Allowed origin:** the domain that serves your public key, for example `polaris.example.com`. Tesla's register page says the domain you register must match the root domain of an allowed origin of the app.
- **Redirect URI:** add `<DEFAULT_URI>/tesla/callback` (or your `POLARIS_TESLA_REDIRECT_URI`). Tesla's pages do not say how redirect addresses are entered in the portal: **not verified**.
- **Fleet API Agreement:** Polaris asks for your consent to store the tokens before it connects (step 6). The agreement itself and where you accept it: **not verified**.
- **Billing:** Tesla's page says the Fleet API is "pay-per-use" and gives a monthly discount of $10 for individual developers and small applications, rounded to the nearest $0.01. The page lists no price per request and shows no update date, so no figure is quoted here: read the current [Billing and limits](https://developer.tesla.com/docs/fleet-api/billing-and-limits) page, and set a billing method in your developer account if Tesla asks. Polaris keeps its reads few (once or twice a day, never waking the car on its own).

### 2. Generate the key pair

```bash
docker compose exec php bin/console polaris:tesla:keygen
```

It prints the public key and stores the private key encrypted (never shown). It refuses to replace an existing key unless you pass `--force`, and you would then register again.

<details>
<summary>Already have a key pair? Import it instead</summary>

The key must be prime256v1, PKCS8 or SEC1 PEM, without a passphrase. Send it on standard input (so it never appears in a command argument), with `-T`:

```bash
docker compose exec -T php bin/console polaris:tesla:key:import < private-key.pem
```

Add `--force` to replace a stored key. Do not leave the private key in a folder that is committed anywhere.
</details>

### 3. Serve the public key

Tesla reads the key at `https://<your domain>/.well-known/appspecific/com.tesla.3p.public-key.pem` and it must stay there. Set `POLARIS_KEY_HOSTING` in `.env.local`:

- `polaris` (default): Polaris answers that URL without a login. Nothing else to do. This needs the domain to reach Polaris over https (setup 1 or 2).
- `external`: the key lives on a static site you control (for example GitHub Pages). Set one or both of `POLARIS_KEY_HOSTING_DIR` (a folder of the container where `keygen` writes the file; mount your site's clone into it with a Compose override) and `POLARIS_KEY_HOSTING_URL` (the https URL of the PEM; `key:check` reads it).

After changing `.env.local`, apply it with `docker compose up -d`.

### 4. Store the credentials

```bash
docker compose exec php bin/console polaris:tesla:configure --client-id=<client ID> --domain=polaris.example.com --region=eu --email=you@example.com
```

You are then asked for the client secret (hidden). It never goes in an argument. Without a terminal, pipe it and add `-T`:

```bash
printf '%s\n' '<client secret>' | docker compose exec -T php bin/console polaris:tesla:configure --client-id=<client ID> --domain=polaris.example.com --region=eu --email=you@example.com
```

- `--region`: `na` (North America and Asia-Pacific) or `eu` (Europe, Middle East and Africa). Repeat it for both. China is a separate Tesla account and developer app and is not supported.
- Options you leave out keep their stored value.

The same settings are on the web, in **Tesla settings** (account menu, `/settings/tesla`). The client secret is write-only there.

### 5. Check, then register

```bash
docker compose exec php bin/console polaris:tesla:key:check
docker compose exec php bin/console polaris:tesla:register
```

`key:check` fetches your public key URL the way Tesla will and compares it to the stored key. `register` runs the check first and sends nothing to Tesla when it fails; then, for each enabled region, it gets a partner token and calls Tesla's register endpoint ([Partner endpoints](https://developer.tesla.com/docs/fleet-api/endpoints/partner-endpoints), read 2026-11-09, which says every app must register in each region it uses and that the key must stay hosted at the well-known address). It shows Tesla's errors with a hint.

### 6. Connect your Tesla account

Go to **Tesla settings** (`/settings/tesla`) > **Connect Tesla**, give your consent that Polaris stores the tokens, then choose one way:

- **Sign in with Tesla.** The redirect address shown on the connect page must be in the allowed redirect addresses of your Tesla developer app (step 1).
- **Paste the callback URL**, when your browser cannot open the Polaris address: start with the button, approve at Tesla, then paste the full address from the browser bar.
- **Paste a refresh token** obtained elsewhere. Polaris uses it once right away (Tesla refresh tokens are single use) and keeps only the new ones.

Requested scopes: `openid offline_access vehicle_device_data vehicle_cmds`. Tesla refresh tokens expire after 3 months unused ([Third-party tokens](https://developer.tesla.com/docs/fleet-api/authentication/third-party-tokens), read 2026-11-09): if `/settings/tesla` shows the connection as expired, connect again. **Disconnect** there deletes the tokens and withdraws your consent; also revoke the access in your Tesla account.

### 7. Import your vehicle

Open **Import from Tesla** on the home page, or:

```bash
docker compose exec php bin/console polaris:vehicle:import
```

Only your account's vehicle list is read; no car is contacted. A self-hosted Polaris tracks **one vehicle**: if your account lists several, pick one with `--vin=<VIN>`. `polaris:vehicle:add <VIN> 'Model 3'` adds one by hand.

### 8. Choose when the odometer is read

By default once a day at 21:00 in your time zone. Set one or two times (`HH:MM`, 24 h):

```bash
docker compose exec php bin/console polaris:fetch:schedule 07:30 18:00
```

The `worker` service does the reading and must be running. A scheduled read never wakes the car: if it is asleep or offline, Polaris checks again every hour for 3 hours, then skips that day.

## Backups

You need **two things**, kept apart:

1. **The database.** Readings, the lease, the Tesla app settings and the encrypted tokens and keys.
2. **`.env.local`**, above all `POLARIS_ENCRYPTION_KEY`. The dump holds the Tesla secrets only in encrypted form ([ADR 0007](adr/0007-secrets-encrypted-at-rest.md)); without the key they cannot be read, and without `POSTGRES_PASSWORD` and `APP_SECRET` you start with new ones. Do not store the key in clear next to the dump: a copy of both together is everything an attacker needs. Keep `.env.local` in a password manager or an encrypted store.

Also keep your `.env`, which is short.

### The database

With the stack running, from the folder of the stack:

```bash
docker compose exec -T database pg_dump -U polaris -Fc polaris > polaris-$(date +%F).dump
```

The file `polaris-<date>.dump` lands in the folder where you run the command (the redirect writes it on the host, not in the container). `-Fc` is PostgreSQL's compressed format; it can only be read back with `pg_restore`. Check that it is not empty (`ls -l polaris-*.dump`: an empty database is already about 30 kB), and copy it away from this host. Do it from a scheduler of your host (cron, a NAS task) if you want it regular.

You can stop the stack and copy the `database_data` volume instead, but the dump is smaller, restores into a newer PostgreSQL and needs no downtime.

### The other volumes

- `redis_data`: the job queue and locks. Not needed: a lost queue only drops reads waiting at that moment, and the next scheduled slot runs normally.
- `caddy_data`, `caddy_config`: the certificate and Caddy's settings. Not needed: Caddy asks for a new certificate when it starts. Do not wipe them in a loop, as certificate authorities limit how many they issue.

### A complement: one file per vehicle

On `/vehicles/<id>/data` (sidebar item **Data**) you can download one JSON file with the lease, the readings and the daily snapshots, or from the console:

```bash
docker compose exec php bin/console polaris:vehicle:data:export <VIN> --file=/tmp/model-3.json
docker compose cp php:/tmp/model-3.json .
```

It holds no VIN, no Tesla token and no raw Tesla answers, so it is **not** a full restore (you would connect Tesla again). It is useful to keep your lease history if you rebuild from scratch. The import (`polaris:vehicle:data:import <VIN> <file>`, or the page) only adds, so importing a file twice changes nothing.

## Restore

On a new host, or after `docker compose down -v` deleted the volumes. You need the dump, `.env.local` and `.env`, and the **same version** of Polaris as the dump (or newer, see [Upgrades](#upgrades)).

1. Put `compose.yaml`, `compose.image.yaml`, `.env` and `.env.local` in an empty folder (the files of [step 1](#1-get-the-files)).
2. Start only the database, and wait until it is healthy:

   ```bash
   docker compose up -d --wait database
   ```

3. Load the dump into the empty database:

   ```bash
   docker compose exec -T database pg_restore -U polaris -d polaris --no-owner < polaris-<date>.dump
   ```

4. Start everything:

   ```bash
   docker compose up -d --wait
   ```

Sign in with your usual password. The `php` container finds the tables already at the latest migration and changes nothing. If Tesla was connected, it still is: `docker compose exec php bin/console polaris:tesla:key:check` confirms the key is readable.

Do not start the whole stack before the restore: the `php` container would create empty tables and `pg_restore` would then report errors for objects that exist.

> [!WARNING]
> `docker compose down -v` deletes the volumes, so your data. `docker compose down` (without `-v`) is safe.

## Upgrades

1. Read the [release notes](https://github.com/RSickenberg/polaris/releases). Polaris is 0.x: things can change.
2. **Back up first** (above).
3. Change `POLARIS_VERSION` in `.env`, and fetch the Compose files of the new version again (step 1: the files can change between versions).
4. Apply:

   ```bash
   docker compose pull
   docker compose up -d --wait
   ```

The `php` container applies the pending database migrations at start, all or none (`--all-or-nothing`). If it does not become healthy, `docker compose logs php` says why.

**Going back.** The migrations are not written to be undone, and the guide promises no downgrade. To return to an older version, restore the backup you took before the upgrade (see [Restore](#restore)) with the older `POLARIS_VERSION`.

**Nightly.** The `nightly` image is a beta; its migrations may not upgrade to the next release. Use it with a database you can lose.

## Troubleshooting

| Problem | What to do |
| --- | --- |
| Compose says `POLARIS_VERSION is missing a value` | Add `POLARIS_VERSION=<version>` to `.env`. |
| `php` stays `unhealthy` or restarts | `docker compose logs php`. A database error usually means `POSTGRES_PASSWORD` in `.env.local` is not the one the database was created with: the database keeps the first one. Put the first one back, or delete the `database_data` volume if there is nothing to keep. |
| The links in emails point at `localhost` | `DEFAULT_URI` is missing from `.env.local`. Add it and run `docker compose up -d`. |
| The browser warns about the certificate | `SERVER_NAME` is `localhost` or a name that is not public: Caddy used its own certificate. Use a public domain for a trusted one. |
| `key:check` says it cannot fetch the URL | The domain does not reach Polaris over https yet (DNS, ports 80 and 443, or `SERVER_NAME`), or the key is hosted elsewhere: set `POLARIS_KEY_HOSTING_URL`. |
| "cannot attach stdin to a TTY-enabled container" | Add `-T` after `exec`. |
| Test the Tesla token refresh | `docker compose exec php bin/console polaris:tesla:token:refresh --force`. Tokens are single use: do not force it needlessly. |
| See Tesla's raw answer (for a bug report) | `docker compose exec php bin/console polaris:tesla:debug:fetch list`, then `state`, `data` or `cached` followed by a VIN; add `--redact` to remove VIN, ids, car names, tokens and location. `data` is a billed call. |
| List or retry failed reads | `docker compose exec php bin/console messenger:failed:show`, then `messenger:failed:retry`. |
| See what the stack is doing | `docker compose ps` and `docker compose logs -f worker`. |

Security problems go to [SECURITY.md](../SECURITY.md), not to a public issue.

## What was and was not tested

Run for this guide, in an empty folder, with an image built from the branch that fixes the start-up problems of 0.4.0 (the published 0.4.0 image does not start: its database URL has no password, see the pull request that fixes it; use the first version released after it), with the files and commands above: start, the login page (HTTP 200 over Caddy's own certificate on `localhost`), create the user (standard input; the hidden prompt is the same command without `-T`), add a vehicle and a reading, `keygen`, `configure` with fake values, `key:check` (it reports the unreachable domain), the public key URL, backup, `down -v`, restore, restart, and the reading and the user back. Signing in through the browser after the restore was not exercised (the test certificate is self-signed).

**Not run**, so unverified: a certificate for a public domain; setups 2 and 3; anything that talks to Tesla (portal, `register`, sign-in, `import`, reads, wake); the upgrade path between two published versions; the Symfony vault (`secrets:set`) instead of `.env.local`; `pg_restore` into a PostgreSQL major version other than 18.

# Polaris

Polaris tracks your Tesla's odometer against your lease allowance, projects the mileage at the end of the lease and warns you before you go over.

> **Status:** early design. Nothing is usable yet.

## What it will do

- Read the odometer from the official Tesla Fleet API, once or twice a day, without waking the car. A manual refresh (at most once per hour) can wake it on request.
- Compare the kilometres driven with the lease allowance and project the total at lease end.
- Give a remaining budget per day, week and month, and alert when the projection crosses the limit.

## This repository

The open-source, self-hostable edition of Polaris: a Symfony application. It is published as a Docker image on GitHub Container Registry.

Each user connects their own Tesla developer application, so Tesla API usage is billed to the user's own Tesla developer account.

## Repository layout

A standard Symfony application at the repository root (namespace `Polaris\`):

```
polaris/
  bin/console
  config/
  public/index.php
  src/          # application code, Polaris\
  tests/        # PHPUnit tests
```

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

Secrets are never committed. Generate them once in `.env.local` (git-ignored); Symfony and every container read it:

```
php -r "echo 'APP_SECRET='.bin2hex(random_bytes(16)).PHP_EOL;" >> .env.local
php -r "echo 'POSTGRES_PASSWORD='.bin2hex(random_bytes(16)).PHP_EOL;" >> .env.local
```

Build and start everything (`compose.override.yaml` adds the development settings automatically):

```
docker compose build
docker compose up -d --wait
```

Open https://localhost and accept the self-signed certificate: the Symfony welcome page answers. The first start runs `composer install` if `vendor/` is empty.

Everyday commands:

```
docker compose exec php bin/console about
docker compose exec php bin/phpunit
docker compose logs -f php worker
docker compose restart worker   # after changing message handlers
docker compose down             # add -v to also delete the database and Redis data
```

Host ports can be changed with environment variables: `HTTP_PORT`, `HTTPS_PORT`, `HTTP3_PORT`, `DATABASE_PORT`, `REDIS_PORT`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`. Xdebug is installed in the dev image; enable step debugging with `XDEBUG_MODE=debug docker compose up -d`.

To try the production image locally:

```
docker compose -f compose.yaml -f compose.prod.yaml up -d --build --wait
```

Everything runs in UTC: the containers set `TZ=UTC`, PHP sets `date.timezone = UTC`, PostgreSQL uses `timezone = UTC`, and the kernel forces UTC again when it boots.

Without Docker, PHP 8.5 and Composer are enough to run the console and the tests: `composer install`, `bin/console about`, `vendor/bin/phpunit`.

## Planned stack

Symfony 8.1, PHP 8.5, PostgreSQL, Redis (Messenger queues), Tailwind CSS with the Symfony UX Toolkit Shadcn kit, Flowbite charts. All dates are handled in UTC.

## Related repositories

- [polaris-ios-kit](https://github.com/RSickenberg/polaris-ios-kit): iOS translations and push notification handling (Apache-2.0).

## License

[AGPL-3.0-or-later](LICENSE). A commercial license is available for uses that cannot comply with the AGPL, see [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). External contributions require the [Contributor License Agreement](CLA.md).

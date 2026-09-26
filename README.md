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

## Development

Requirements: PHP 8.5 and Composer. Docker support is coming.

```
php -r "echo 'APP_SECRET='.bin2hex(random_bytes(16)).PHP_EOL;" >> .env.local
composer install
bin/console about
vendor/bin/phpunit
```

All dates run in UTC: the kernel forces the runtime time zone to UTC when it boots.

## Planned stack

Symfony 8.1, PHP 8.5, PostgreSQL, Redis (Messenger queues), Tailwind CSS with the Symfony UX Toolkit Shadcn kit, Flowbite charts. All dates are handled in UTC.

## Related repositories

- [polaris-ios-kit](https://github.com/RSickenberg/polaris-ios-kit): iOS translations and push notification handling (Apache-2.0).

## License

[AGPL-3.0-or-later](LICENSE). A commercial license is available for uses that cannot comply with the AGPL, see [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). External contributions require the [Contributor License Agreement](CLA.md).

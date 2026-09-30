# AGENTS.md

This is a Symfony project. Check `composer.json` for the exact Symfony/PHP version
in use, and read `symfony.lock` to see which recipes ran. Don't assume Doctrine,
Twig, API Platform, Messenger, or Lock are installed unless one of those says so.

## Ask before generating

If the task doesn't specify, ask rather than guess:

- Persistence: Doctrine ORM, Doctrine ODM, or none?
- Interface: server-rendered (Twig), API (Serializer, maybe API Platform), or both?
- Auth: SecurityBundle, and which authenticator?

If you can't ask (no interactive channel), state the assumption you're making and
pick the smallest option (e.g. no persistence layer) rather than scaffolding a
full stack nobody asked for.

## Adding features: Flex, not hand-wiring

Install new capabilities with `composer require <package>` (e.g. `symfony/lock`,
`symfony/messenger`, `orm-pack`) and let the Flex recipe register the bundle and
generate its config. Don't hand-edit `config/bundles.php` or hand-write a bundle's
base config; that's what the recipe is for. Don't skip a good-fit component just
because it isn't installed yet; installing it is one command.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html to write idiomatic
Symfony:

- Use PHP attributes for framework metadata, and not only on controllers:
  `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]` on actions, `#[Assert\...]`
  on properties, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, and
  `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]` on services. No YAML or
  XML routing.
- Rely on autowiring and autoconfiguration. Type-hint constructor arguments and
  let the container resolve them. Where a type-hint can't express it, stay in the
  class with `#[Autowire]` (parameters, env vars, expressions) or `#[Target]` (one
  of several implementations of an interface). A YAML service definition is the
  last resort, not the first.
- Controllers extend `AbstractController`, stay thin, and delegate to services.
- Use the framework for what it already does: Form for server-rendered forms,
  Validator for validation, Serializer for JSON, Messenger for async work,
  Security (voters, authenticators) for access control, Twig `path()`/`url()`
  instead of hardcoded URLs.
- Before hand-writing infrastructure (locks, queues, caches, HTTP clients,
  mailers, schedulers) or reaching for a third-party library, check whether a
  Symfony component covers it. It usually does.

Three specifics worth spelling out, because they are easy to get wrong:

- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]` on action
  arguments, which wires up Serializer and Validator for you, instead of calling
  `json_decode()` or `SerializerInterface` by hand. If neither package is
  installed yet, `composer require` them rather than falling back to manual
  parsing.
- Use constructor property promotion, and `readonly` for DTOs and value objects.
  Don't mark a service `readonly` if it might become `lazy: true`: a lazy proxy
  can't extend a `readonly` class.
- Use `symfony/lock` (`LockFactory`) for mutual exclusion. A hand-built flag or
  lock file looks fine in review and is usually wrong under concurrency.

## Everyday workflow

- Run the app with `symfony serve -d`, and commands with `symfony console ...`
  (or `bin/console` when the Symfony CLI isn't available).
- When something fails, read `var/log/dev.log` and the web profiler
  (`/_profiler`) before changing code.
- If `maker-bundle` is installed, prefer `bin/console make:*` with every argument
  passed up front and `--no-interaction` where supported: makers prompt on a
  terminal by default, which hangs a non-interactive shell. If a maker still
  needs interactive input, hand-write the code instead.
- If Doctrine ORM is installed, schema changes go through migrations
  (`bin/console make:migration`, then `doctrine:migrations:migrate`), never
  `doctrine:schema:update` or hand-written SQL.
- `.env` is committed and holds defaults only. Real secrets belong in `.env.local`
  (git-ignored) or the secrets vault (`bin/console secrets:set`), read via
  `%env(...)%`.

## Testing

Install `symfony/test-pack` if it isn't already. Functional/HTTP tests extend
`WebTestCase`; service-level tests extend `KernelTestCase`. Run
`php bin/phpunit` (falls back to `vendor/bin/phpunit`). A feature isn't done
until it has a test that exercises it the way a caller would, an HTTP request for
a controller or a service call for a service, not just "it didn't throw."

## Code style

Symfony's coding standard, the `@Symfony` php-cs-fixer ruleset (a PSR-12-derived
superset). Run `vendor/bin/php-cs-fixer fix` if `friendsofphp/php-cs-fixer` is
installed; it isn't part of the skeleton by default.

## Discover, don't guess

Framework APIs change between versions and your training data may be stale. Look
things up in the project instead of relying on memory:

- `bin/console about`: versions, environment, paths.
- `bin/console debug:router`, `debug:container`, `debug:autowiring <name>`,
  `debug:config <bundle>`, `config:dump-reference <bundle>`: what exists and how
  it is configured.
- `bin/console lint:container`, plus `lint:twig templates/` and
  `lint:yaml config/` where those packages are installed: validate before running.
- Read the installed source and docblocks under `vendor/`.
- Docs: https://symfony.com/doc/current/ (switch to the version matching
  `composer.json` if it differs).

## Polaris project rules

Everything above is the generic Symfony guidance from the recipe. The rules below
are specific to Polaris and win when the two disagree.

### Context

- Polaris tracks a Tesla's odometer against the lease allowance, projects the
  mileage at lease end and warns before the limit. Decisions are recorded in
  `docs/adr/`; read them before changing structure.
- This repository is public (AGPL-3.0-or-later) and is the self-hostable
  application. A private hosted layer builds on its Docker image and plugs in
  only through `src/Extension/`. Never add hosted-only code here (billing, plans,
  sign-up policy, APNs keys).
- Stack in place: Symfony 8.1, PHP 8.5, PostgreSQL 18 with Doctrine ORM and
  Migrations, Carbon, PHPUnit. Planned, not installed yet: Redis (Messenger,
  Lock, Cache), Tailwind with the Symfony UX Toolkit Shadcn kit, Flowbite charts
  (ApexCharts) behind one reusable Stimulus controller. Check `composer.json`
  before assuming a package is there.
- Run everything through Docker and the `Makefile` (see README): `make start`,
  `make test`, `make ci`, `make sf ARGS="..."`. This replaces the generic
  `symfony serve` and `php bin/phpunit` advice above.
- Work is tracked on the GitHub Project "Polaris"
  (https://github.com/users/RSickenberg/projects/4). Each story has acceptance
  criteria and "blocked by" links: read the issue before starting and do not
  start a story whose blockers are still open.

### Language and writing

- Code, comments, commit messages, docs and issues are in English, even when the
  conversation is in French.
- Do not use the em dash character in prose, comments, strings or docs.
- If something cannot be verified (a Tesla API behaviour, a package version),
  say so instead of guessing. Check `vendor/` sources and official docs first.

### Code organization (ADR 0001)

- `src/` is organized by feature module, as namespaces (no bundles): `Shared`,
  `Account`, `Vehicle`, `Lease`, `Reading`, `Tesla`, `Fetching`, `Alert`,
  `Dashboard`, `Extension`.
- Inside a module, use the usual Symfony folders (`Entity/`, `Repository/`,
  `Service/`, `Message/`, `MessageHandler/`, `Controller/`, `Form/`, `Command/`,
  `Twig/Components/`) plus `Domain/` for calculations and value objects.
- `Domain/` is plain PHP: only its own module's `Domain/`, `Shared\Domain`,
  `Psr\Clock\ClockInterface` and `CarbonImmutable`; nothing from Symfony,
  Doctrine or HTTP, and no other module's `Domain/`. Unit-test it without booting
  the kernel.
- A module uses another module only through its `Service/` classes, its entities,
  its `Domain/` types, or Messenger messages and events. Never through its
  controllers, forms, repositories or handlers. Nothing depends on `Dashboard`.
- Templates go in `templates/<module>/`, component templates in
  `templates/components/<Module>/`.
- MakerBundle needs absolute class names, for example
  `bin/console make:entity '\Polaris\Lease\Entity\LeaseContract' --no-interaction`.
- Deptrac enforces these boundaries in CI (`make deptrac`). Fix the code, not
  the Deptrac rules, unless an ADR changes them.

### Data types

- Use DTOs (readonly classes) instead of associative arrays for
  application-specific data, and backed enums instead of magic strings or array
  keys. Arrays are fine for plain lists and framework APIs that require them.
- Entities are identified by a ULID created in the constructor (ADR 0002):
  `#[ORM\Id] #[ORM\Column(type: UlidType::NAME)] private Ulid $id;` with
  `$this->id = new Ulid();`, never `#[ORM\GeneratedValue]`. Bind ids in raw
  DBAL queries with the `ulid` type.

### Dates, units and business rules

- UTC everywhere (ADR 0003): storage, computation, API, logs. The kernel forces UTC at boot.
  Get the current time from `ClockInterface`, never from `new \DateTimeImmutable()`
  or `time()`. Use `CarbonImmutable`, never mutable `Carbon`. The user's time zone
  is only for display and for converting fetch slots.
- Distances are stored as integer metres. The Tesla odometer is in miles:
  1 mi = 1.609344 km.
- The lease allowance is linear over the whole term: total km = km per year x
  years, no yearly reset.

### Tesla Fleet API

- Never wake the car automatically. `wake_up` (after the first release, #32) is
  only called on a manual refresh the user confirmed, within the daily wake cap.
- Scheduled reads run only when the vehicle state is `online`. Manual refresh is
  limited to once per hour per vehicle.
- Request the minimal OAuth scopes. Tokens are encrypted at rest; refresh tokens
  are single-use and are rotated under a lock.
- Tesla can revoke API access at any time: manual odometer entry must always keep
  working.

### Secrets

- Never commit a secret, not even a development `APP_SECRET`. GitGuardian scans
  every pull request. Generated values go in `.env.local`.
- `.env.example` lists every variable that must be set per install, with empty
  values. When a change introduces such a variable (a secret, a password, an API
  key), add it there, empty, in the same pull request.

### Git and delivery

- Conventional Commits (`feat`, `fix`, `docs`, `refactor`, `test`, `build`,
  `ci`, `chore`); pull request titles are checked in CI.
- Versions and `CHANGELOG.md` are produced by release-it from the commits: do not
  edit them by hand.
- One issue per pull request, small, with "Closes #N". Pull requests are
  squash-merged.
- No `.bak` or backup copies of files: git is the history.

### Definition of done

- Tests pass (unit tests for `Domain/`, functional tests for controllers and
  commands), PHPStan passes, php-cs-fixer is clean, Deptrac passes (`make ci`
  runs all four), docs are updated, and the story's acceptance criteria are all met.

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
- This repository is public under the PolyForm Noncommercial License 1.0.0 (ADR 0008) and is the
  self-hostable application, for personal use only. A private hosted layer builds on its Docker image and plugs in
  only through `src/Extension/`. Never add hosted-only code here (billing, plans,
  sign-up policy, APNs keys).
- Stack in place: Symfony 8.1, PHP 8.5, PostgreSQL 18 with Doctrine ORM and
  Migrations, Security (form login, login throttling with the Rate Limiter), Carbon, PHPUnit, Tailwind CSS 4 through symfonycasts/tailwind-bundle on
  AssetMapper (no npm for application assets), Stimulus, Turbo, TwigComponent, UX Icons
  the Symfony UX Toolkit Shadcn kit and ApexCharts 8.3.1 (importmap, pinned; Community License,
  which does not cover embedding it in a product used by other people, so the hosted layer needs
  an OEM license; its ESM build injects its own CSS and imports `apexcharts/core`) behind one
  reusable Stimulus controller. Messenger with Redis streams and Scheduler run the fetches (see
  "Scheduled fetching"). Planned, not installed yet: Redis as the cache. Check `composer.json` before assuming a package is there.
- UI: kit components are installed with `bin/console ux:install <name> --kit shadcn` into
  `templates/components/` (Stimulus controllers into `assets/controllers/`) and are then ours
  to edit, but keep edits minimal so a later `ux:install` diff stays readable; the only
  edits so far replace hardcoded English text with translation keys. `symfony/ux-toolkit`
  is a dev dependency: the installed files are committed. Polaris's own components go in
  `templates/components/<Module>/` (the shell is `Shared`; the dashboard building blocks `KpiCard`,
  `LeaseProgress`, `BudgetBadge`, `RiskAlert` and `MileageChart` are `Dashboard`, anonymous components built on the
  kit Card, Progress, Badge and Alert; `MileageChart` only feeds `chart_controller.js`).
  `assets/controllers/chart_controller.js` is the one generic chart controller: chart type, series,
  options, `locale` and `timeZone` are Stimulus values, options strings written `var(--token)` are
  resolved from the theme tokens, it redraws when the `dark` class of `<html>` changes, formats axes
  and tooltips with Intl, and destroys the chart on disconnect and before the Turbo cache. The mileage
  curves are built by `Dashboard\Domain\MileageSeriesBuilder` (readonly DTOs, no Lease domain types)
  from the assessment the dashboard already computed. The driven and projected curves take the risk color of the
  alert through the `--risk-ok`, `--risk-watch` and `--risk-over` tokens of `app.css` (the Tailwind green, amber and red
  of `LeaseProgress`, lighter in dark mode). The last reading is shown with the `humanized_datetime` Twig filter
  ("Today at ...", "Yesterday at ...", older dates in full), whose days are those of the user's time zone and whose
  "now" comes from the clock. The zoom presets (7 days, 30 days, 1 year, to the lease end,
  whole lease) are `Dashboard\Domain\MileageRangeBuilder`: look-back windows end now and start no earlier than the
  lease start, the forecast window only exists with a projection, and the buttons drive the controller's `zoom` action. Icons are Lucide, imported with
  `bin/console ux:icons:import lucide:<name>` into `assets/icons/` and committed, so there is
  no runtime call to Iconify. Dark mode is the `dark` class on `<html>`, set before the first
  paint by the inline script of `base.html.twig` and kept by `theme_controller.js`. Pages
  extend `base.html.twig` and fill the `main` block.
  Nothing styles bare elements: `app.css` holds only the theme variables, so titles,
  paragraphs and lists carry their own utility classes. Forms render through the global form
  theme `templates/shared/form_theme.html.twig` (registered in `twig.form_themes`), which extends
  Symfony's `form_div_layout.html.twig` and overrides only the blocks it needs: each row is a kit
  `Field` (`Field:Label`, the control, `Field:Description` for `help`, one `Field:Error` per error),
  text-like widgets are `Input`, choice selects are `NativeSelect` (size `sm`, to match the Input
  height) and buttons are `Button`. Symfony's `form_row` already hands `aria-describedby` and
  `aria-invalid` to the widget through `attr`; the theme only forwards them. Checkbox widgets (alone, or as an expanded
  choice: one line per choice, label without asterisk) are the kit `Checkbox`; radio, file, range and color
  widgets are not themed yet and fall back to the default layout. Tables use
  `Table` inside a `Card`, success flashes use `Alert` through `shared/_flashes.html.twig`. The
  installed kit files are unedited, apart from the translated text noted above.
- The `tailwind` compose service watches and builds the CSS in dev (binary and output in
  `var/tailwind`, a volume shared with `php`); `make tailwind` builds once, and `make test`
  and `make ci` do it first because the pages link the built stylesheet. The prod image runs
  `tailwind:build --minify` before `asset-map:compile`; CI builds it before PHPUnit.
- `twig-tailwind-extra`, which the kit templates need, ships a recipe in recipes-contrib.
  `extra.symfony.allow-contrib` is `false`, so Flex skips it: its bundle is registered by hand
  in `config/bundles.php`.
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

### Accounts and access (Account module)

- The self-hosted app has a single user. `Account\Entity\User` (table `app_user`, as `user` is
  reserved in PostgreSQL) holds the email (unique, trimmed, lower-cased), the password hash
  (property `password`), `locale` and `timeZone` (an IANA name, `UTC` by default). The only way
  to create it is `Account\Service\UserCreator`, which the console command
  `polaris:user:create` calls. Whether a user may be created is decided by
  `Extension\SignUpPolicy`: the self-hosted default `SingleUserSignUpPolicy` allows one user,
  and the hosted layer replaces the policy. The command takes the password from a hidden prompt or the standard input, never
  from an argument or option, and never prints it.
- Access is denied by default: `access_control` in `config/packages/security.yaml` ends with
  `^/` requires `ROLE_USER`, and the public exceptions (`/login`, `/locale/`) are listed above
  it. A new page needs nothing; a new public page needs an entry there and in
  `PUBLIC_ROUTES` of `AccessControlTest`, which walks the router and fails on any route an
  anonymous visitor can open. The login page is `Account\Controller\LoginController`
  (`account_login`, `templates/account/login.html.twig`, the bare layout through the `body`
  block of `base.html.twig`); the logout control is in the sidebar footer.
- The settings page (`Account\Controller\SettingsController`, `/settings`) has two forms, `profile`
  (email, locale, time zone) and `password` (current password, new one twice), both going through
  `Account\Service\UserSettings`. A changed email or password makes the session stale, so the
  controller signs the user in again with `Security::login()`. When API tokens exist (ADR 0005), a
  password change must revoke them in `UserSettings::changePassword()`. The sidebar user menu is in
  `templates/components/Shared/AppSidebar.html.twig`, the avatar letters come from
  `Account\Domain\Initials`.
- Password reset (`Account\Controller\PasswordResetController`, `Account\Service\PasswordResetService`):
  `PasswordResetRequest` stores a selector and the sha256 of a verifier (`Account\Domain\ResetToken`),
  never the link. Asking never reveals whether the email exists, is limited by the `password_reset`
  rate limiter (per client address) and sends at most one link every 2 minutes per account. The
  link is built from `DEFAULT_URI`, not the request host. `UserSettings::changePassword()` removes
  the pending requests, so both the reset and the settings page cancel old links. Emails are in the
  language of the user (`account` domain); the mailer needs `MAILER_DSN`, `MAILER_FROM` and `DEFAULT_URI`.
- The firewall form login and logout use the stateless CSRF token ids `authenticate` and
  `logout`; `login_throttling` allows 5 attempts per 15 minutes. The `dev` firewall that leaves
  the profiler, the toolbar and the AssetMapper routes open is driven by the parameter
  `polaris.security.open_paths`, which matches nothing in production.
- Pages that show or take a date read the time zone from the signed-in user
  (`#[CurrentUser] User $user`); `Vehicle::$owner` links every vehicle to its user (required). A user
  reaches only their own vehicles: every action that takes a vehicle carries
  `#[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]`,
  decided by `Vehicle\Security\VehicleVoter` (another user's vehicle is a 404), and a query that
  lists vehicles filters on `owner`. A new page about a vehicle needs the attribute, and a test in
  `VehicleOwnershipTest`.
- In functional tests, sign in with `Polaris\Tests\Support\SignsIn`; do not post the login
  form unless the test is about the login.

### Tesla key pair (Tesla module)

- `Tesla\Entity\TeslaApp` (owned by a `User`) holds the prime256v1 public key in clear (PKIX PEM) and the
  private key as the `Shared\Service\SecretEncrypter` ciphertext (ADR 0007: libsodium secretbox, `v1:` prefix,
  key in `POLARIS_ENCRYPTION_KEY`). The private key PEM is PKCS8; vehicle-command's loaders read both
  PKCS8 and SEC1 (checked in its source, not run). Never log, dump or echo it: `Tesla\Domain\KeyPair` and
  `TeslaApp::__serialize()` leave it out.
- `polaris:tesla:keygen` (`Tesla\Service\TeslaKeyService`) refuses to replace a key without `--force`.
- `Extension\PublicKeyHosting` decides where Tesla finds the public key. The default `ConfiguredPublicKeyHosting`
  follows `POLARIS_KEY_HOSTING`: `polaris` (`PolarisPublicKeyHosting`, `Tesla\Controller\PublicKeyController`
  serves the only app's key whatever the Host, stateless, public) or `external` (`ExternalPublicKeyHosting`
  writes the PEM into `POLARIS_KEY_HOSTING_DIR` when set, and `POLARIS_KEY_HOSTING_URL`, the https URL of the PEM,
  makes `publicKeyUrl()` non-null so `PublicKeyChecker` reads that URL and notes the well-known URL Tesla reads;
  either one is enough; the route then answers 404). The hosted layer replaces the alias and must implement
  `publicKeyUrl()` (null to keep using the app domain).
- The well-known route is public: its `access_control` entry precedes the catch-all and `tesla_public_key` is in
  `PUBLIC_ROUTES` of `AccessControlTest`.

### Tesla app credentials and registration (Tesla module)

- `TeslaApp` also holds `clientId`, `encryptedClientSecret` (SecretEncrypter, kept out of `__serialize`), `domain`
  (the host that serves the public key, `Tesla\Domain\AppDomain`; not derived from `DEFAULT_URI`, since external
  hosting uses another domain) and `enabledRegions` (`Tesla\Domain\FleetRegion` codes). The row is created by
  keygen, so `Tesla\Service\TeslaAppSettings::save()` and `polaris:tesla:configure` need the key pair first.
  `TeslaOwnerFinder` resolves the user for every Tesla command.
- `FleetRegion` is `na`, `eu` and `cn`. China is a separate Tesla account and developer app: the case exists only
  to be refused (`isSupported()` is false, `tokenUrl()` throws, settings and `register` reject it). The token host
  for NA and EMEA is the global `fleet-auth.prd.vn.cloud.tesla.com`.
- `polaris:tesla:key:import` (`KeyPairImporter`, `TeslaKeyService::import()`) keeps an existing prime256v1 private key
  (PKCS8 or SEC1 PEM, no passphrase) read from the standard input or `--file`, never an argument: the public key is
  derived from it, the private key is re-exported as PKCS8 and stored encrypted like a generated one, and `--force`
  rules are those of keygen. It never prints the private key.
- `polaris:tesla:key:check` (`PublicKeyChecker`) fetches the well-known URL without following redirects and compares
  it to the stored key. `polaris:tesla:register` (`PartnerRegistrationService`) runs it first and sends nothing to
  Tesla on failure; per enabled region it calls `FleetApiClient` for a partner token, `POST /api/1/partner_accounts`
  and `GET /api/1/partner_accounts/public_key?domain=`, and one region failing does not stop the others.
- Unverified (Tesla does not document them): the register body
  (`{"domain": ...}`), the shape of Tesla error bodies (`FleetApiError` reads `error`, `error_description`,
  `error_message`, `message`, else a truncated body) and of the public key response (`response.public_key`, PEM or
  hex of the uncompressed point, see `PublicKeyFormat`). The first real run confirmed that the partner token needs a scope (`openid vehicle_device_data`; without one the key read-back answered 403 "missing scopes") and that register works for `eu`.
- The settings page (`Tesla\Controller\TeslaSettingsController`, `/settings/tesla`, linked from the account menu) edits
  the same settings through `TeslaAppSettings`, with the form model `Tesla\Form\Model\TeslaAppSettingsFormData`.
  The app is looked up by owner (no id in the URL), the client secret field is write-only (never rendered, empty
  keeps the stored one), China is listed but disabled and rejected server-side. Without a key pair it shows how to
  run keygen. It does not register: `key:check` and `register` stay on the console. Pass the app to the template as
  `teslaApp`: `app` is the Twig global.
- Tests never reach the network: they use `MockHttpClient`, and functional tests swap it in with
  `getContainer()->set('http_client', $client)` (public alias `test.polaris.http_client` in `when@test`).

### Tesla connection and tokens (Tesla module)

- `Tesla\Entity\OAuthToken` (one per `TeslaApp`) holds the access and refresh tokens as `SecretEncrypter` ciphertext
  (left out of `__serialize`), `expiresAt` (UTC, from `expires_in` and the clock), the granted scopes, a
  `ConnectionStatus` (`Connected`, `Expired`), the `ConnectionMethod` and the user's Fleet API base URL.
  `TeslaApp::$consentGivenAt` records the opt-in consent (Fleet API Agreement clause 9.3.7); every way to connect
  is refused without it (`ConnectionRejected::NO_CONSENT`), and the pages redirect to `/settings/tesla/consent`.
- `Tesla\Service\OAuthClient` does the authorize URL, the code exchange (needs `audience` and the client secret), the
  refresh (only `client_id` and `refresh_token`) and `GET /api/1/users/region`. PKCE is not documented by Tesla and not
  used. It shares `SendsFleetApiRequests` (redaction of secrets, no redirects) with `FleetApiClient`. No call is
  retried: a refresh token is single use.
- `Tesla\Service\TeslaConnectionService` is the only way to connect (redirect callback, pasted callback URL, pasted
  refresh token) and to disconnect. The `state` is kept in the session by `TeslaConnectionController` and checked in
  the service. The redirect URI comes from `RedirectUriProvider` (`POLARIS_TESLA_REDIRECT_URI`, else `DEFAULT_URI` +
  `/tesla/callback`) and is sent unchanged to `/authorize` and `/token`.
- Anything that needs a Tesla access token calls `Tesla\Service\TokenProvider::accessToken()`
  (`LockedTokenProvider`): it refreshes `POLARIS_TESLA_REFRESH_MARGIN` seconds before expiry under a `symfony/lock`
  lock per app (`LOCK_DSN`, Redis in Compose), reads the row again once the lock is held, and commits the new refresh
  token in a transaction before returning the access token. A refresh token Tesla refuses (`login_required`,
  `invalid_grant`, any 401) marks the row `Expired` and throws `TeslaNotConnected`; an outage leaves the row alone.
  `polaris:tesla:token:refresh [--force]` runs it by hand (it prints only the new expiry, never a token).
- Unverified (Tesla does not document them): the shape of the `users/region` answer (`response.region`,
  `response.fleet_api_base_url`), what happens when the account region differs from the audience, and whether
  `http://localhost:8000/tesla/callback` completes the flow end to end.

### Vehicles (Vehicle module)

- A vehicle is created by `Vehicle\Service\TeslaVehicleImporter` (list of the Tesla account through `VehicleClient::listVehicles()`,
  first page only; no car is contacted), by `polaris:vehicle:import [--vin=] [--dry-run]`, by the page `/vehicles/import`
  (`Vehicle\Controller\VehicleImportController`, route priority above `/vehicles/{id}`, translation domain `vehicle`) or by hand
  with `polaris:vehicle:add <vin> <name>`. The importer reads the listing again when importing: a posted VIN is only a choice
  among the vehicles Tesla returned. A VIN is unique across users, so one that exists is "already added". The name is the Tesla
  display name, else `Tesla` and the last 4 characters of the VIN. The home page links to the import page.

### Scheduled fetching (Fetching module)

- Messenger (`config/packages/messenger.yaml`) has three Redis stream transports on `MESSENGER_TRANSPORT_DSN`: `async`
  (nothing routed yet), `tesla` (`FetchOdometerMessage`, no Messenger retry: a billed read is repeated only by the hourly
  rule below) and `failed` (`failure_transport`, retried by hand with `messenger:failed:retry`). The `worker` service
  (`frankenphp/docker-worker.sh`) consumes `scheduler_default async tesla` explicitly, never `--all`, which would also
  drain `failed` in a loop. Tests use `in-memory://` transports (`messenger.transport.tesla`).
- `Scheduler\FetchScheduleProvider` has one recurring message, `DispatchDueFetches` every 5 minutes, stateful and locked.
  The slots are not cron entries: `Service\DueFetchDispatcher` turns each vehicle's local slots into UTC instants with
  `Domain\SlotCalculator` on every tick (owner's time zone, so daylight saving is followed with no restart), and queues a
  `FetchOdometerMessage` for each slot whose window is open (`Domain\FetchWindow`: from the slot to 3 hours after) and
  that has no `FetchSlotRun` yet. The row is written first and is unique on (vehicle, slot), so repeated ticks and
  restarts queue a slot once; a window that closed while the worker was down is not run late.
- Slots are `FetchSchedule` (one per vehicle, `HH:MM` local times, 1 or 2; none stored means 21:00), set with
  `polaris:fetch:schedule [--vin=] [first [second]]` through `Service\FetchScheduleService`.
- `Service\OdometerFetcher` runs the message with `TeslaOdometerReader` (never wakes the car). Not online: it re-queues the
  message with `attempt + 1` and a `DelayStamp` of one hour, 4 state checks at most per slot (`FetchWindow::MAX_ATTEMPTS`),
  then the slot is `Skipped`. A value is stored by `OdometerReadingService::recordFromApi()` only when it differs from
  the previous reading (`readAt` is Tesla's `vehicle_state.timestamp`); a value lower than a stored reading is
  `Rejected`, logged as a warning, and never retried. `TeslaNotConnected` ends the slot as `NotConnected` with a warning;
  other `FleetApiError`s are rethrown to the `failed` transport.
- `FetchOdometerMessage` has scalar fields and a null `slotTimestamp` for a fetch that belongs to no slot (never retried):
  the manual refresh dispatches it, and new fields (such as an `allowWake` flag) are added at the end with a default.
  The per-vehicle lock between a scheduled and a manual fetch is not there yet: it belongs with the manual refresh.

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
- `Service/` keeps the services and their interfaces at the top level; the value types sit in subfolders named
  after their kind: `Exception/`, `Dto/` (data and result objects), `Enum/`, and `Client/` for HTTP clients. The
  folders are part of the public `Api` layer, so other modules may use them like any `Service/` class.
- Templates go in `templates/<module>/`, component templates in
  `templates/components/<Module>/`.
- MakerBundle needs absolute class names, for example
  `bin/console make:entity '\Polaris\Lease\Entity\LeaseContract' --no-interaction`.
- Deptrac enforces these boundaries in CI (`make deptrac`). Fix the code, not
  the Deptrac rules, unless an ADR changes them.

### Exceptions

- PHPStan checks `@throws`: a method that lets `\Random\RandomException`, `\SodiumException`, `\JsonException`, a Doctrine
  `ORMException` or `NonUniqueResultException`, or a `symfony/lock` exception through must list it (see
  `exceptions.checkedExceptionClasses` in `phpstan.dist.neon`). Controllers, commands and tests are entry points and
  are exempt. A caller of a documented method documents it too, unless it catches it.

### Assertions

- `\assert()` is only for narrowing a type or stating an invariant that cannot fail (for example after a
  Carbon call PHPStan types as `static|string`). Never use it to validate input, to guard a state that can
  happen, or for anything with a side effect: production runs with `zend.assertions = -1` (set in
  `frankenphp/conf.d/20-app.prod.ini`), which compiles it out, while dev and tests keep it on.

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

### Vehicle reads (Tesla module)

- `Tesla\Service\VehicleClient` (implemented by `Client\TeslaFleetClient`) lists vehicles
  (`GET /api/1/vehicles`, first page only), reads a state (`/vehicles/{vin}`, `VehicleState`) and a live odometer
  (`/vehicles/{vin}/vehicle_data`, `OdometerReading`). Callers pass the `TeslaApp` and a `Vin`: the client gets the
  token from `TokenProvider` and the base URL from the stored `OAuthToken`, lets `TeslaNotConnected` through
  (before any HTTP call) and has no wake method. It sends no `endpoints` query, so never `location_data`. The
  odometer is `Distance::fromMiles()` of `vehicle_state.odometer`; a missing or invalid one is
  `FleetApiError::unexpected`, as is an unknown state value.
- `Tesla\Service\TeslaOdometerReader::read()` is the read flow: state first, then `vehicle_data` only when the
  car is `online`. `asleep` and `offline` give `NoReadingReason::Asleep|Offline` without asking the car, and a 408
  on the live call (it fell asleep in between) gives `Unavailable`, not retried. To get a reading the user wakes the
  car in the Tesla app and refetches. It never wakes the car (#32 decides how: the default is the Tesla app, with an
  opt-in daily automatic wake at an hour the user chooses, which needs an ADR and a change of the rule above).
- `vehicle_data_cached` is not used: a real answer of an offline car has no `odometer` in its `vehicle_state`
  (car name, version, tyre pressures, doors and lock only). Its `timestamp` blocks are epoch milliseconds.
- Retries are on this client only (`RetryableHttpClient` built in its constructor): 500, 502, 503, 504 and 540, at
  most 2 retries, delay `polaris.tesla.retry_delay_ms` (500 ms, doubling, capped at 4 s, `0` in tests);
  `Retry-After` is honoured by the Symfony client. 408 is never retried (the car is not online and every status
  below 500 is billed), nor 401, 403, 404, 412, 421 or 429. `OAuthClient` and `FleetApiClient` keep the plain
  `http_client`: a refresh token is single use and must never be sent twice. Do not configure `retry_failed`
  globally.
- The fetch job (Fetching module, later issue) compares a reading with the last stored one and saves it; this
  module only reads.
- `polaris:tesla:debug:fetch list|state|data|cached [vin] [--redact]` prints the raw answer of one of these reads, whole
  by default; `--redact` removes the VIN, ids, car names, tokens, location and `drive_state`, to paste it in an issue
  or to build a fixture. `data` is a billed live call. GET only, never `wake_up`.
- Confirmed by real EU answers: the shape of the vehicle list and of a vehicle (`state` `online` and `offline`
  seen), a live `vehicle_data` (`response.vehicle_state.odometer` as a float, `vehicle_state.timestamp` in epoch
  milliseconds, used as the read time; the clock only when it is missing) and the 408 body (`error: "vehicle
  unavailable: vehicle is offline or asleep"`).
- The odometer of the API is always in miles, never kilometres, whatever `gui_settings.gui_distance_units` (the
  display setting) says; the answer carries no unit field.
- Unverified (Tesla does not document them, or no real answer was seen): the paging parameters of `/vehicles` (only the answer's `pagination` object was seen); the `endpoints`
  parameter; the `asleep` state value; that a 408 is the answer of a car that fell asleep after an `online`
  state.

### Translations

- Locales: `en` (default and fallback) and `fr`, set in `config/packages/translation.yaml`.
  The locale of a request is decided by `Shared\EventListener\LocaleListener` through the
  framework-free `Shared\Domain\LocaleNegotiator`: the user's preference
  (`Shared\Service\PreferredLocaleProvider`), then the best `Accept-Language` match among
  the enabled locales, then `en`.
- The language switcher (`Shared\Controller\LocaleController`, `/locale/{locale}`) stores the
  choice in the `polaris_locale` cookie, read by `Shared\Service\CookiePreferredLocaleProvider`,
  and dispatches `Shared\Event\LocaleSwitched`. `Account\Service\UserPreferredLocaleProvider`
  decorates the cookie provider and reads the signed-in user's locale from the session, where
  `Account\EventListener\RememberLocaleOnLogin` and `SaveSwitchedLocale` put it (and the latter
  also writes `User.locale`). It cannot read the token: `LocaleListener` runs at priority 20,
  before the firewall (8). A new language also needs its `locale.<code>` key in `shared`.
- Never write user-facing text in a template, form, flash message, validator or email: use
  a key. Exceptions: the console commands stay in English, and brand names (`Polaris`).
- One XLIFF 1.2 domain per module: `translations/<domain>.<locale>.xlf` with `<domain>` the
  module name in lowercase (`reading`, `lease`, ...), plus `shared` for text used by several
  modules. The source is the key, the target is the text, the `id` repeats the key.
  Constraint and callback messages must live in Symfony's `validators` domain; their keys
  start with the module (`reading.odometer.too_large`).
- Keys are lowercase `snake_case` segments joined by `.`, at most three levels, without the
  module prefix (the domain is the prefix): `page.history.title`, `form.odometer.label`,
  `form.submit`, `flash.reading_added`, `rejected.in_future`, `table.empty`.
  Form labels and buttons are `form.<field>.label` and `form.submit`. Pass the domain
  explicitly: `|trans(domain='reading')`, `$translator->trans($key, $params, 'reading')`,
  or `translation_domain` on the form type.
- A message that formats a number or a date goes in `<domain>+intl-icu.<locale>.xlf` (ICU
  MessageFormat, `{limit, number}`, `{at, date, medium}`), and the code passes raw numbers
  and timestamps. Never write an apostrophe in these files (ICU treats it as an escape).
  Other messages use `%name%` parameters.
- Numbers and dates: format them in Twig for the active locale (`format_number`,
  `format_currency`, `localized_datetime(timeZone)`), never with `number_format()` or a fixed
  date pattern. Dates stay stored and computed in UTC; the time zone only converts for display.
- Add a key: add it to the `en` and the `fr` file of its domain in the same change.
  Add a language: copy every `*.en.xlf` to `*.<locale>.xlf`, translate the targets, add the
  locale to `enabled_locales` and to the `fr` checks below, and extend the tests.
- CI runs `composer translations` (`make translations`): `lint:xliff translations` (syntax),
  `lint:translations` (messages parse) and `debug:translation fr --only-missing` (a key used
  in the code but absent from `fr` exits non-zero). That command cannot see keys built at run
  time (enum values such as `source.manual`), so `TranslationFilesTest` also compares every
  `en` file with its `fr` twin key by key and placeholder by placeholder.

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

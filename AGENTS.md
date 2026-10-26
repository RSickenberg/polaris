# AGENTS.md

Symfony project. Check `composer.json` for Symfony/PHP versions and `symfony.lock` for the recipes that ran. Don't assume Doctrine, Twig, API Platform, Messenger or Lock are installed unless those say so.

## Ask before generating

If unspecified, ask rather than guess: persistence (Doctrine ORM, ODM, none), interface (Twig, API, both), auth (SecurityBundle, which authenticator). With no interactive channel, state the assumption and pick the smallest option (e.g. no persistence layer).

## Adding features: Flex, not hand-wiring

Install capabilities with `composer require <package>` and let the Flex recipe register the bundle and config. Don't hand-edit `config/bundles.php` or a bundle's base config. Don't skip a good-fit component because it isn't installed yet.

## Conventions

Follow https://symfony.com/doc/current/best_practices.html:

- PHP attributes for framework metadata: `#[Route]`, `#[MapRequestPayload]`, `#[IsGranted]`, `#[Assert\...]`, `#[AsCommand]`, `#[AsEventListener]`, `#[AsMessageHandler]`, `#[AsAlias]` / `#[AsTaggedItem]` / `#[Autoconfigure]`. No YAML or XML routing.
- Autowiring and autoconfiguration. Where a type-hint can't express it, use `#[Autowire]` (parameters, env, expressions) or `#[Target]`. YAML service definitions are the last resort.
- Controllers extend `AbstractController`, stay thin, delegate to services.
- Use the framework: Form, Validator, Serializer, Messenger, Security (voters, authenticators), Twig `path()`/`url()`. Before hand-writing infrastructure (locks, queues, caches, HTTP clients, mailers, schedulers) or adding a third-party library, check for a Symfony component.
- Bind request data with `#[MapRequestPayload]` / `#[MapQueryString]`, not `json_decode()` or manual `SerializerInterface`; `composer require` the packages if missing.
- Constructor property promotion; `readonly` for DTOs and value objects. Don't mark a service `readonly` if it might become `lazy: true`.
- Mutual exclusion uses `symfony/lock` (`LockFactory`), never a hand-built flag or lock file.

## Everyday workflow

- Run the app with `symfony serve -d`, commands with `symfony console ...` (or `bin/console`). In Polaris use Docker and the Makefile instead (see below).
- On failure read `var/log/dev.log` and the profiler (`/_profiler`) before changing code.
- With `maker-bundle`, prefer `bin/console make:*` with all arguments up front and `--no-interaction`; if a maker still prompts, hand-write the code.
- Doctrine schema changes go through migrations (`make:migration`, `doctrine:migrations:migrate`), never `doctrine:schema:update` or hand-written SQL.
- `.env` is committed with defaults only. Secrets go in `.env.local` (git-ignored) or the vault (`bin/console secrets:set`), read via `%env(...)%`.

## Testing

Install `symfony/test-pack` if missing. Functional tests extend `WebTestCase`, service tests `KernelTestCase`. Run `php bin/phpunit`. A feature isn't done without a test that exercises it as a caller would (HTTP request for a controller, service call for a service).

## Code style

Symfony coding standard (`@Symfony` php-cs-fixer ruleset). Run `vendor/bin/php-cs-fixer fix`.

## Discover, don't guess

Look things up in the project, not from memory: `bin/console about`, `debug:router`, `debug:container`, `debug:autowiring <name>`, `debug:config <bundle>`, `config:dump-reference <bundle>`, `lint:container`, `lint:twig templates/`, `lint:yaml config/`; read `vendor/` sources and docblocks; docs at https://symfony.com/doc/current/ (match the `composer.json` version).

## Polaris project rules

These win over the generic guidance above.

### Context

- Polaris tracks a Tesla's odometer against the lease allowance, projects mileage at lease end and warns before the limit. Decisions are in `docs/adr/`; read them before changing structure.
- Public repo under PolyForm Noncommercial 1.0.0 (ADR 0008): the self-hostable app, personal use only. A private hosted layer builds on its Docker image and plugs in only through `src/Extension/`. Never add hosted-only code here (billing, plans, sign-up policy, APNs keys).
- Stack: Symfony 8.1, PHP 8.5, PostgreSQL 18 with Doctrine ORM and Migrations, Security (form login, login throttling with Rate Limiter), Carbon, PHPUnit, Tailwind CSS 4 via symfonycasts/tailwind-bundle on AssetMapper (no npm for app assets), Stimulus, Turbo, TwigComponent, UX Icons, UX Toolkit Shadcn kit, UX Live Component ^3.6 (route `/_components`, behind the catch-all `ROLE_USER`), ApexCharts 8.3.1 (importmap, pinned; Community License does not cover embedding in a product used by others, so the hosted layer needs an OEM license; its ESM build injects its own CSS and imports `apexcharts/core`) behind one Stimulus controller. Messenger (Redis streams) and Scheduler run the fetches. Planned, not installed: Redis as the cache. Check `composer.json` before assuming a package.
- UI:
  - Kit components: `bin/console ux:install <name> --kit shadcn` into `templates/components/` (controllers into `assets/controllers/`); ours to edit but keep edits minimal so a later `ux:install` diff stays readable (so far only translation keys replace hardcoded English). `symfony/ux-toolkit` is a dev dependency; installed files are committed.
  - Polaris components: `templates/components/<Module>/` (`Shared` is the shell; `Dashboard` has `KpiCard`, `LeaseProgress`, `PaceRow`, `RiskAlert`, `MileageChart`, anonymous, built on kit Card, Progress, Badge, Alert).
  - `Fetching:OdometerRefreshButton` (`Fetching\Twig\Components\OdometerRefreshButton`, template `templates/components/Fetching/`) is the first PHP-backed Live Component; `config/packages/twig_component.yaml` maps `Polaris\Fetching\Twig\Components\` to name prefix `Fetching`. It sits at the top right of the dashboard, level with the title, while the lease runs (not without lease or reading, not once ended). Live Component 3.6 guards actions only by POST plus `X-Requested-With` (no CSRF token) and has no `#[IsGranted]`: the component checks `VehiclePermission::Manage` itself on every request (render and action) and answers 404. A `LiveProp` named like a component method breaks hydration.
  - `assets/controllers/chart_controller.js` is the one generic chart controller: type, series, options, `locale`, `timeZone` are Stimulus values; option strings `var(--token)` resolve from theme tokens; it redraws when `<html>` `dark` changes, formats with Intl, and destroys the chart on disconnect and before the Turbo cache. `Dashboard\Domain\MileageSeriesBuilder` builds the curves (readonly DTOs, no Lease domain types) from the dashboard's assessment; driven/projected curves take the alert's risk color via `--risk-ok`, `--risk-watch`, `--risk-over` in `app.css` (lighter in dark mode). Zoom presets (7 days, 30 days, 1 year, to lease end, whole lease) are `Dashboard\Domain\MileageRangeBuilder`: look-back windows end now and start no earlier than the lease start; the forecast window exists only with a projection; buttons drive the controller's `zoom` action.
  - Flash messages and notices are toasts: `base.html.twig` includes `shared/_flashes.html.twig` once (the fixed top-right `toast-region`, `aria-live`; login includes it itself, so pages don't). It renders the session flashes as `Shared:Toast` (kit Alert, `data-flash`, variants `success`, `warning`, `info`, `danger`; the `error` flash type is a `danger` toast; danger stays until dismissed, others leave after 6 s, paused on hover or focus) and hands one empty toast per variant to `toast_region_controller.js`, which shows any `polaris:toast` window event (`detail: {variant, message}`). A Live Component announces a toast by rendering a hidden `toast-announce` element whose `id` changes per message (a morphed element that keeps its `id` says nothing twice). Inside a `<twig:...>` tag the variable `variant` is the child's: set anything derived from your own beforehand.
  - The last reading uses the `humanized_datetime` Twig filter ("Today at ...", "Yesterday at ...", older in full), days in the user's time zone, "now" from the clock.
  - Icons are Lucide: `bin/console ux:icons:import lucide:<name>` into `assets/icons/`, committed (no runtime Iconify call).
  - Dark mode is the `dark` class on `<html>`, set before first paint by the inline script of `base.html.twig`, kept by `theme_controller.js`. Pages extend `base.html.twig` and fill `main`.
  - Nothing styles bare elements: `app.css` holds only theme variables; titles, paragraphs and lists carry utility classes.
  - Forms use the global theme `templates/shared/form_theme.html.twig` (in `twig.form_themes`), extending `form_div_layout.html.twig` and overriding only needed blocks: rows are kit `Field` (`Field:Label`, control, `Field:Description` for `help`, one `Field:Error` per error); text-like widgets `Input`; choice selects `NativeSelect` (size `sm`); buttons `Button`; checkboxes (alone or expanded choice, one line per choice, no asterisk) kit `Checkbox`. `form_row` already passes `aria-describedby`/`aria-invalid` via `attr`; the theme forwards them. Radio, file, range, color widgets are not themed (default layout). Tables: `Table` inside `Card`. Success flashes: `Alert` via `shared/_flashes.html.twig`.
- The `tailwind` compose service watches/builds CSS in dev (binary and output in `var/tailwind`, volume shared with `php`); `make tailwind` builds once; `make test` and `make ci` do it first. The prod image runs `tailwind:build --minify` before `asset-map:compile`; CI builds it before PHPUnit.
- `twig-tailwind-extra` (needed by kit templates) ships a recipes-contrib recipe; `extra.symfony.allow-contrib` is `false`, so its bundle is registered by hand in `config/bundles.php`.
- Run everything through Docker and the Makefile (see README): `make start`, `make test`, `make ci`, `make sf ARGS="..."` (replaces `symfony serve` / `php bin/phpunit`).
- Work is tracked on the GitHub Project "Polaris" (https://github.com/users/RSickenberg/projects/4). Read the story's acceptance criteria and "blocked by" links; don't start a story whose blockers are open.

### Language and writing

- Code, comments, commits, docs and issues in English, even if the conversation is in French.
- No em dash in prose, comments, strings or docs.
- If something can't be verified (Tesla API behaviour, package version), say so; check `vendor/` and official docs first.

### Accounts and access (Account module)

- Single user when self-hosted. `Account\Entity\User` (table `app_user`) holds email (unique, trimmed, lower-cased), password hash (property `password`), `locale`, `timeZone` (IANA, `UTC` default). Created only by `Account\Service\UserCreator` (console `polaris:user:create`), gated by `Extension\SignUpPolicy` (default `SingleUserSignUpPolicy` allows one user; the hosted layer replaces it). The command reads the password from a hidden prompt or stdin, never an argument/option, and never prints it.
- Access is denied by default: `access_control` in `config/packages/security.yaml` ends with `^/` requiring `ROLE_USER`; public exceptions (`/login`, `/locale/`) sit above. A new public page needs an entry there and in `PUBLIC_ROUTES` of `AccessControlTest`, which walks the router and fails on any anonymously reachable route. Login: `Account\Controller\LoginController` (`account_login`, `templates/account/login.html.twig`, bare layout via the `body` block of `base.html.twig`); logout is in the sidebar footer.
- Settings (`Account\Controller\SettingsController`, `/settings`): forms `profile` (email, locale, time zone) and `password` (current, new twice) via `Account\Service\UserSettings`. A changed email or password stales the session, so the controller re-signs in with `Security::login()`. When API tokens exist (ADR 0005), `changePassword()` must revoke them. Sidebar menu: `templates/components/Shared/AppSidebar.html.twig`; avatar letters: `Account\Domain\Initials`.
- Password reset (`PasswordResetController`, `PasswordResetService`): `PasswordResetRequest` stores a selector and the sha256 of a verifier (`Account\Domain\ResetToken`), never the link. Asking never reveals whether the email exists, is limited by the `password_reset` limiter (per client address), and sends at most one link per 2 minutes per account. Link built from `DEFAULT_URI`, not the request host. `changePassword()` removes pending requests. Emails use the user's language (`account` domain); the mailer needs `MAILER_DSN`, `MAILER_FROM`, `DEFAULT_URI`.
- Form login and logout use stateless CSRF ids `authenticate` and `logout`; `login_throttling` allows 5 attempts per 15 minutes. The `dev` firewall (profiler, toolbar, AssetMapper open) is driven by `polaris.security.open_paths`, which matches nothing in production.
- Pages showing or taking dates read the time zone from the user (`#[CurrentUser] User $user`). `Vehicle::$owner` (required) links every vehicle to its user; users reach only their own: every action taking a vehicle carries `#[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]` (`Vehicle\Security\VehicleVoter`), and vehicle-listing queries filter on `owner`. A new vehicle page needs the attribute and a test in `VehicleOwnershipTest`.
- Functional tests sign in with `Polaris\Tests\Support\SignsIn`; post the login form only when testing login.

### Tesla key pair (Tesla module)

- `Tesla\Entity\TeslaApp` (owned by a `User`) holds the prime256v1 public key in clear (PKIX PEM) and the private key as `Shared\Service\SecretEncrypter` ciphertext (ADR 0007: libsodium secretbox, `v1:` prefix, key `POLARIS_ENCRYPTION_KEY`). Private PEM is PKCS8; vehicle-command reads PKCS8 and SEC1 (checked in source, not run). Never log, dump or echo it: `Tesla\Domain\KeyPair` and `TeslaApp::__serialize()` leave it out.
- `polaris:tesla:keygen` (`TeslaKeyService`) refuses to replace a key without `--force`.
- `Extension\PublicKeyHosting` decides where Tesla finds the public key. Default `ConfiguredPublicKeyHosting` follows `POLARIS_KEY_HOSTING`: `polaris` (`PolarisPublicKeyHosting`; `Tesla\Controller\PublicKeyController` serves the only app's key whatever the Host, stateless, public) or `external` (`ExternalPublicKeyHosting` writes the PEM into `POLARIS_KEY_HOSTING_DIR` when set; `POLARIS_KEY_HOSTING_URL`, the https URL of the PEM, makes `publicKeyUrl()` non-null so `PublicKeyChecker` reads that URL; either is enough; the route then 404s). The hosted layer replaces the alias and implements `publicKeyUrl()` (null keeps the app domain).
- The well-known route is public: its `access_control` entry precedes the catch-all and `tesla_public_key` is in `PUBLIC_ROUTES`.

### Tesla app credentials and registration (Tesla module)

- `TeslaApp` also holds `clientId`, `encryptedClientSecret` (SecretEncrypter, out of `__serialize`), `domain` (host serving the public key, `Tesla\Domain\AppDomain`; not derived from `DEFAULT_URI`) and `enabledRegions` (`Tesla\Domain\FleetRegion`). Keygen creates the row, so `TeslaAppSettings::save()` and `polaris:tesla:configure` need the key pair first. `TeslaOwnerFinder` resolves the user for every Tesla command.
- `FleetRegion`: `na`, `eu`, `cn`. China is a separate Tesla account/developer app: the case exists only to be refused (`isSupported()` false, `tokenUrl()` throws, settings and `register` reject it). The NA and EMEA token host is `fleet-auth.prd.vn.cloud.tesla.com`.
- `polaris:tesla:key:import` (`KeyPairImporter`, `TeslaKeyService::import()`) keeps an existing prime256v1 key (PKCS8 or SEC1 PEM, no passphrase) from stdin or `--file`, never an argument: derives the public key, re-exports PKCS8, stores encrypted; `--force` rules as keygen; never prints the private key.
- `polaris:tesla:key:check` (`PublicKeyChecker`) fetches the well-known URL without redirects and compares to the stored key. `polaris:tesla:register` (`PartnerRegistrationService`) runs it first and sends nothing on failure; per enabled region it gets a partner token, `POST /api/1/partner_accounts`, `GET /api/1/partner_accounts/public_key?domain=`; one region failing doesn't stop the others.
- Unverified (undocumented): the register body (`{"domain": ...}`); Tesla error body shape (`FleetApiError` reads `error`, `error_description`, `error_message`, `message`, else truncated body); public key response (`response.public_key`, PEM or hex of the uncompressed point, see `PublicKeyFormat`). The first real run confirmed the partner token needs a scope (`openid vehicle_device_data`; without one the key read-back gave 403 "missing scopes") and that register works for `eu`.
- Settings page (`Tesla\Controller\TeslaSettingsController`, `/settings/tesla`, linked from the account menu) edits the same settings via `TeslaAppSettings` and `Tesla\Form\Model\TeslaAppSettingsFormData`. App found by owner (no id in URL); client secret field write-only (never rendered, empty keeps stored); China listed but disabled and rejected server-side; without a key pair it shows how to run keygen; it doesn't register (`key:check`, `register` stay on the console). Pass the app to the template as `teslaApp` (`app` is the Twig global).
- Tests never reach the network: `MockHttpClient`, swapped in with `getContainer()->set('http_client', $client)` (public alias `test.polaris.http_client` in `when@test`).

### Tesla connection and tokens (Tesla module)

- `Tesla\Entity\OAuthToken` (one per `TeslaApp`): access and refresh tokens as SecretEncrypter ciphertext (out of `__serialize`), `expiresAt` (UTC), scopes, `ConnectionStatus` (`Connected`, `Expired`), `ConnectionMethod`, the user's Fleet API base URL. `TeslaApp::$consentGivenAt` records the opt-in consent (Fleet API Agreement 9.3.7); every way to connect is refused without it (`ConnectionRejected::NO_CONSENT`) and pages redirect to `/settings/tesla/consent`.
- `Tesla\Service\OAuthClient`: authorize URL, code exchange (needs `audience` and client secret), refresh (only `client_id` and `refresh_token`), `GET /api/1/users/region`. No PKCE (undocumented by Tesla). Shares `SendsFleetApiRequests` (secret redaction, no redirects) with `FleetApiClient`. No call is retried: a refresh token is single use.
- `TeslaConnectionService` is the only way to connect (redirect callback, pasted callback URL, pasted refresh token) and disconnect. `state` is kept in the session by `TeslaConnectionController` and checked in the service. Redirect URI from `RedirectUriProvider` (`POLARIS_TESLA_REDIRECT_URI`, else `DEFAULT_URI` + `/tesla/callback`), sent unchanged to `/authorize` and `/token`.
- Anything needing an access token calls `TokenProvider::accessToken()` (`LockedTokenProvider`): refreshes `POLARIS_TESLA_REFRESH_MARGIN` seconds before expiry under a `symfony/lock` lock per app (`LOCK_DSN`, Redis in Compose), re-reads the row once locked, commits the new refresh token in a transaction before returning. A refused refresh token (`login_required`, `invalid_grant`, any 401) marks the row `Expired` and throws `TeslaNotConnected`; an outage leaves the row alone. `polaris:tesla:token:refresh [--force]` runs it by hand (prints only the new expiry).
- Unverified: the `users/region` answer shape (`response.region`, `response.fleet_api_base_url`); behaviour when account region differs from audience; whether `http://localhost:8000/tesla/callback` completes the flow.

### Vehicles (Vehicle module)

- Created by `Vehicle\Service\TeslaVehicleImporter` (Tesla account list via `VehicleClient::listVehicles()`, first page only; no car contacted), `polaris:vehicle:import [--vin=] [--dry-run]`, page `/vehicles/import` (`VehicleImportController`, route priority above `/vehicles/{id}`, domain `vehicle`), or `polaris:vehicle:add <vin> <name>`. The importer re-reads the listing: a posted VIN is only a choice among returned vehicles. VIN is unique across users (existing = "already added"). Name: Tesla display name, else `Tesla` + last 4 VIN characters. The home page links to the import page.

### Scheduled fetching (Fetching module)

- Messenger (`config/packages/messenger.yaml`): three Redis stream transports on `MESSENGER_TRANSPORT_DSN`: `async` (nothing routed), `tesla` (`FetchOdometerMessage`, no Messenger retry: a billed read repeats only via the hourly rule) and `failed` (`failure_transport`, retried by hand with `messenger:failed:retry`). The `worker` service (`frankenphp/docker-worker.sh`) consumes `scheduler_default async tesla` explicitly, never `--all` (it would drain `failed` in a loop). Tests use `in-memory://` transports (`messenger.transport.tesla`).
- `Scheduler\FetchScheduleProvider` has one recurring message, `DispatchDueFetches`, every minute on the minute (UTC cron; `every('5 minutes')` counts from worker start), stateful, not locked (a lock held by a killed worker would silence ticks after restart; `FetchSlotRun` uniqueness already prevents double queuing). Slots aren't cron entries: `Service\DueFetchDispatcher` converts each vehicle's local slots to UTC with `Domain\SlotCalculator` every tick (owner's time zone, so DST needs no restart) and queues a `FetchOdometerMessage` for each slot whose window is open (`Domain\FetchWindow`: slot to +3 h) and has no `FetchSlotRun`. The row is written first and unique on (vehicle, slot); a window closed while the worker was down is not run late.
- Slots: `FetchSchedule` (one per vehicle, 1 or 2 `HH:MM` local times; none stored = 21:00), set by `polaris:fetch:schedule [--vin=] [first [second]]` via `Service\FetchScheduleService`.
- `Service\OdometerFetcher` runs the message with `TeslaOdometerReader` (never wakes the car). Not online: re-queues with `attempt + 1` and a `DelayStamp` to the next hour after the slot (`FetchWindow::retryDelaySeconds()`, so a late first check doesn't shift the rest), max 4 state checks (`FetchWindow::MAX_ATTEMPTS`), then `Skipped`. `OdometerReadingService::recordFromApi()` stores a value only if it differs from the previous (`readAt` = Tesla's `vehicle_state.timestamp`); lower than a stored reading = `Rejected`, warning, never retried. `TeslaNotConnected` ends the slot `NotConnected` with a warning; other `FleetApiError`s are rethrown to `failed`.
- `FetchOdometerMessage` has scalar fields; null `slotTimestamp` = a fetch with no slot (manual refresh, never retried). New fields (e.g. `allowWake`, #32) go at the end with a default; `refreshId` is the latest.
- `OdometerFetcher` runs one fetch per vehicle at a time, scheduled or manual, under a `symfony/lock` lock `odometer_fetch_<vehicle base32>` (TTL 60 s, non-blocking). Held: a manual refresh ends `RefreshOutcome::Busy` and asks nothing; a scheduled check is re-queued 30 s later with the same `attempt`.
- Manual refresh: `Service\ManualRefreshService::request()` is the only entry (component and a later `POST /api/vehicles/{id}/refresh` use it and read `RefreshRequestResult`: `Accepted` = 202, `RateLimited` with `retryAfter` = 429). The limit is decided there, not in the UI. `Extension\ManualRefreshPolicy` says whether a vehicle is limited (self-hosted default `ConfiguredManualRefreshPolicy`, `POLARIS_MANUAL_REFRESH_LIMIT`: `1` limits, `0` lifts it; the hosted layer replaces it and always limits). The `manual_refresh` limiter (`fixed_window`, 1/hour, key = vehicle id, default `cache.rate_limiter` pool = filesystem app cache: one web container only, move to Redis with the cache) counts every accepted request whatever the car answers. The Symfony limiter reads the wall clock, so tests age its stored `Window` rather than freeze time. An accepted request writes a `RefreshAttempt` (`Queued`) and dispatches `FetchOdometerMessage` with null slot and `refreshId`; the fetcher settles it (`RefreshOutcome`: `Stored`, `Unchanged`, `Asleep`, `Offline`, `Unavailable`, `NotConnected`, `Rejected`, `Busy`, `Failed`); the component polls every 2 s (`data-poll`) for at most 2 minutes. Never wakes the car (#32).

### Code organization (ADR 0001)

- `src/` is organized by feature module as namespaces (no bundles): `Shared`, `Account`, `Vehicle`, `Lease`, `Reading`, `Tesla`, `Fetching`, `Alert`, `Dashboard`, `Extension`.
- Inside a module: `Entity/`, `Repository/`, `Service/`, `Message/`, `MessageHandler/`, `Controller/`, `Form/`, `Command/`, `Twig/Components/`, plus `Domain/` for calculations and value objects.
- `Domain/` is plain PHP: only its own module's `Domain/`, `Shared\Domain`, `Psr\Clock\ClockInterface`, `CarbonImmutable`; nothing from Symfony, Doctrine, HTTP, nor another module's `Domain/`. Unit-test it without the kernel.
- A module uses another only through its `Service/` classes, entities, `Domain/` types, or Messenger messages/events; never its controllers, forms, repositories or handlers. Nothing depends on `Dashboard`.
- `Service/` keeps services and interfaces at top level; value types go in subfolders by kind: `Exception/`, `Dto/` (data and result objects), `Enum/`, `Client/` (HTTP clients). They are part of the public `Api` layer.
- Templates: `templates/<module>/`; component templates: `templates/components/<Module>/`.
- MakerBundle needs absolute class names: `bin/console make:entity '\Polaris\Lease\Entity\LeaseContract' --no-interaction`.
- Deptrac enforces this in CI (`make deptrac`). Fix the code, not the rules, unless an ADR changes them.

### Exceptions

- PHPStan checks `@throws`: a method letting `\Random\RandomException`, `\SodiumException`, `\JsonException`, Doctrine `ORMException` or `NonUniqueResultException`, or a `symfony/lock` exception through must list it (`exceptions.checkedExceptionClasses` in `phpstan.dist.neon`). Controllers, commands and tests are exempt. A caller of a documented method documents it too unless it catches it.

### Assertions

- `\assert()` only narrows a type or states an invariant that cannot fail (e.g. after a Carbon call PHPStan types as `static|string`). Never use it to validate input, guard a possible state, or for side effects: production runs `zend.assertions = -1` (`frankenphp/conf.d/20-app.prod.ini`); dev and tests keep it on.

### Data types

- DTOs (readonly classes) instead of associative arrays for app data; backed enums instead of magic strings or array keys. Arrays fine for plain lists and framework APIs.
- Entities get a ULID in the constructor (ADR 0002): `#[ORM\Id] #[ORM\Column(type: UlidType::NAME)] private Ulid $id;` with `$this->id = new Ulid();`, never `#[ORM\GeneratedValue]`. Bind ids in raw DBAL with the `ulid` type.

### Dates, units and business rules

- UTC everywhere (ADR 0003): storage, computation, API, logs; the kernel forces UTC. Current time from `ClockInterface`, never `new \DateTimeImmutable()` or `time()`. `CarbonImmutable`, never `Carbon`. The user's time zone is only for display and converting fetch slots.
- Distances are stored as integer metres. The Tesla odometer is in miles: 1 mi = 1.609344 km.
- The lease allowance is linear over the whole term: total km = km per year x years, no yearly reset.

### Tesla Fleet API

- Never wake the car automatically. `wake_up` (after the first release, #32) is only called on a manual refresh the user confirmed, within the daily wake cap.
- Scheduled reads run only when the state is `online`. Manual refresh: once per hour per vehicle.
- Minimal OAuth scopes. Tokens encrypted at rest; refresh tokens single-use, rotated under a lock.
- Tesla can revoke API access at any time: manual odometer entry must always keep working.

### Vehicle reads (Tesla module)

- `Tesla\Service\VehicleClient` (impl. `Client\TeslaFleetClient`) lists vehicles (`GET /api/1/vehicles`, first page only), reads a state (`/vehicles/{vin}`, `VehicleState`) and a live odometer (`/vehicles/{vin}/vehicle_data`, `OdometerReading`). Callers pass `TeslaApp` and `Vin`; the client gets the token from `TokenProvider` and the base URL from the stored `OAuthToken`, lets `TeslaNotConnected` through (before any HTTP call), has no wake method, and sends no `endpoints` query (never `location_data`). Odometer = `Distance::fromMiles()` of `vehicle_state.odometer`; a missing/invalid one or an unknown state value is `FleetApiError::unexpected`.
- `TeslaOdometerReader::read()`: state first, then `vehicle_data` only when `online`. `asleep`/`offline` give `NoReadingReason::Asleep|Offline` without asking the car; a 408 on the live call gives `Unavailable`, not retried. The user wakes the car in the Tesla app and refetches. It never wakes the car (#32 decides how: default is the Tesla app, with an opt-in daily automatic wake at a user-chosen hour, which needs an ADR and a change of the rule above).
- `vehicle_data_cached` is unused: a real offline answer has no `odometer` in `vehicle_state`. Its `timestamp` blocks are epoch milliseconds.
- Retries only on this client (`RetryableHttpClient` built in its constructor): 500, 502, 503, 504, 540; max 2 retries; delay `polaris.tesla.retry_delay_ms` (500 ms, doubling, cap 4 s, `0` in tests); `Retry-After` honoured. Never retry 408 (car not online; every status below 500 is billed), 401, 403, 404, 412, 421, 429. `OAuthClient` and `FleetApiClient` keep the plain `http_client` (single-use refresh token must never be sent twice). Don't configure `retry_failed` globally.
- The fetch job compares a reading with the last stored one and saves it; this module only reads.
- `polaris:tesla:debug:fetch list|state|data|cached [vin] [--redact]` prints the raw answer, whole by default; `--redact` removes VIN, ids, car names, tokens, location, `drive_state` (for issues/fixtures). `data` is a billed live call. GET only, never `wake_up`.
- Confirmed by real EU answers: vehicle list and vehicle shape (`state` `online`, `offline`), live `vehicle_data` (`response.vehicle_state.odometer` float, `vehicle_state.timestamp` epoch ms, used as read time, clock only if missing), 408 body (`error: "vehicle unavailable: vehicle is offline or asleep"`).
- The API odometer is always miles, whatever `gui_settings.gui_distance_units` says; no unit field.
- Unverified: `/vehicles` paging parameters (only the answer's `pagination` object seen); the `endpoints` parameter; the `asleep` state value; that a 408 means a car that fell asleep after an `online` state.

### Translations

- Locales `en` (default, fallback) and `fr` in `config/packages/translation.yaml`. `Shared\EventListener\LocaleListener` picks the request locale through the framework-free `Shared\Domain\LocaleNegotiator`: user preference (`Shared\Service\PreferredLocaleProvider`), then best `Accept-Language` match, then `en`.
- Switcher `Shared\Controller\LocaleController` (`/locale/{locale}`) stores the `polaris_locale` cookie (`CookiePreferredLocaleProvider`) and dispatches `Shared\Event\LocaleSwitched`. `Account\Service\UserPreferredLocaleProvider` decorates the cookie provider and reads the user's locale from the session, where `RememberLocaleOnLogin` and `SaveSwitchedLocale` put it (the latter also writes `User.locale`). It can't read the token: `LocaleListener` runs at priority 20, before the firewall (8). A new language also needs its `locale.<code>` key in `shared`.
- Never write user-facing text in a template, form, flash, validator or email: use a key. Exceptions: console commands (English), brand names (`Polaris`).
- One XLIFF 1.2 domain per module: `translations/<domain>.<locale>.xlf`, `<domain>` = lowercase module name, plus `shared` for multi-module text. Source = key, target = text, `id` repeats the key. Constraint and callback messages live in `validators` with keys starting with the module (`reading.odometer.too_large`).
- Keys: lowercase `snake_case` segments joined by `.`, max three levels, no module prefix: `page.history.title`, `form.odometer.label`, `form.submit`, `flash.reading_added`, `rejected.in_future`, `table.empty`. Form labels/buttons: `form.<field>.label`, `form.submit`. Pass the domain explicitly: `|trans(domain='reading')`, `$translator->trans($key, $params, 'reading')`, or `translation_domain` on the form type.
- A message formatting a number or date goes in `<domain>+intl-icu.<locale>.xlf` (ICU: `{limit, number}`, `{at, date, medium}`); code passes raw numbers/timestamps. No apostrophes in these files (ICU escape; use `’`). Other messages use `%name%`.
- Format numbers and dates in Twig for the active locale (`format_number`, `format_currency`, `localized_datetime(timeZone)`), never `number_format()` or fixed patterns. Stored and computed in UTC; the time zone only converts for display.
- Add a key: `en` and `fr` file of its domain in the same change. Add a language: copy every `*.en.xlf` to `*.<locale>.xlf`, translate targets, add the locale to `enabled_locales` and the `fr` checks, extend tests.
- CI runs `composer translations` (`make translations`): `lint:xliff translations`, `lint:translations`, `debug:translation fr --only-missing` (a key used in code but absent from `fr` fails). It can't see run-time keys (enum values like `source.manual`), so `TranslationFilesTest` compares every `en` file with its `fr` twin key by key and placeholder by placeholder.

### Secrets

- Never commit a secret, not even a dev `APP_SECRET` (GitGuardian scans every PR). Generated values go in `.env.local`.
- `.env.example` lists every per-install variable (secret, password, API key) with an empty value; add new ones in the same PR.

### Git and delivery

- Conventional Commits (`feat`, `fix`, `docs`, `refactor`, `test`, `build`, `ci`, `chore`); PR titles are checked in CI.
- Versions and `CHANGELOG.md` come from release-it: don't edit by hand.
- One issue per small PR, with "Closes #N"; squash-merged.
- No `.bak` or backup copies: git is the history.

### Definition of done

- Tests pass (unit for `Domain/`, functional for controllers and commands), PHPStan, php-cs-fixer and Deptrac pass (`make ci` runs all four), docs updated, story acceptance criteria met.

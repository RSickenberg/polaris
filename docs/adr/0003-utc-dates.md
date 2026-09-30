# ADR 0003: Store, compute and exchange every date in UTC

- Status: Accepted
- Date: 2026-09-30

## Context

Polaris records odometer readings at precise instants, compares them with a lease that runs over calendar dates, schedules fetches at local times chosen by the user, and will expose the same data to an iOS app through a REST API.

Mixing time zones across the server, the database, the worker and the API makes values drift: a reading can move by an hour around a daylight saving change, and two parts of the system can disagree on which day an instant belongs to. The projection counts days between instants, so it needs days of a fixed length.

## Decision

- Every instant is stored, computed and exchanged in UTC.
- `Polaris\Kernel` forces the PHP default time zone to UTC at boot. Containers run with `TZ=UTC`, PostgreSQL with `timezone=UTC`.
- Dates are `Carbon\CarbonImmutable`, never the mutable `Carbon`.
- Doctrine columns hydrate as `CarbonImmutable` through the types in `src/Shared/Doctrine/Type/`, built on `carbonphp/carbon-doctrine-types`. The date-time type converts to UTC before writing and parses as UTC.
- The current time comes from Symfony Clock (`Psr\Clock\ClockInterface`), so tests can freeze it. Code never calls `new CarbonImmutable()` or `CarbonImmutable::now()` for business time.
- API and JSON output use RFC 3339 in UTC, for example `2026-09-25T19:00:00Z`.
- The user's IANA time zone (for example `Europe/Zurich`) is stored only to display dates and to convert local fetch slots (such as "21:00 local") to UTC each day, which keeps slots correct across daylight saving changes.
- Lease start and end are calendar dates, compared in UTC by the projection.

## Consequences

- One time reference for the server, the worker, the database, the API and the iOS app.
- A day is always 86,400 seconds in calculations, which keeps paces and budgets exact.
- Every display of a date needs an explicit conversion to the user's time zone.
- Local fetch slots must be recomputed each day rather than stored as fixed UTC times.

## Alternatives considered

- Store in the user's time zone: breaks once several users or several time zones share one database, and makes durations ambiguous around daylight saving changes.
- Store with an offset (`timestamptz` rendered per session): correct in PostgreSQL, but leaves room for PHP and the database to disagree; forcing UTC everywhere is simpler to reason about.
- Native `DateTimeImmutable` instead of Carbon: workable, but Carbon's immutable API makes calendar arithmetic shorter and clearer.

## References

- [Symfony Clock component (8.1)](https://symfony.com/doc/current/components/clock.html)
- [Carbon releases](https://github.com/briannesbitt/Carbon/releases)
- [carbon-doctrine-types](https://github.com/CarbonPHP/carbon-doctrine-types)
- [ADR 0001: Organize the application by feature modules](0001-feature-modules.md)

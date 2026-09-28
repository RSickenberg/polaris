# ADR 0002: Identify entities with ULIDs

- Status: Accepted
- Date: 2026-09-28

## Context

Polaris needs primary keys for its entities, starting with `Vehicle` and `LeaseContract` (issue #13). The Doctrine recipe defaults to auto-incremented integers.

Identifiers will appear in URLs, in the REST API for the iOS app, in push payloads and in the private hosted layer. Sequential integers there reveal how many records exist and let anyone guess neighbouring ones. They are also only unique within one database, and a self-hosted install may later be imported into, or exported from, the hosted service.

A database-generated key only exists after the first flush, so a new entity has no identity until then and every `getId()` returns a nullable value.

## Decision

- Every entity is identified by a ULID (`Symfony\Component\Uid\Ulid`, from `symfony/uid`).
- The entity creates it in its constructor with `new Ulid()`. There is no `#[ORM\GeneratedValue]`, and `getId()` returns a non-null `Ulid`.
- The column uses the Doctrine bridge type `UlidType::NAME` (`ulid`), which PostgreSQL stores in a native `UUID` column (16 bytes, RFC 4122 form). PHP code, logs and URLs use the 26-character base 32 form.
- `Domain/` does not depend on `symfony/uid` (ADR 0001, rule 1). Calculations do not need identifiers; if one ever must cross into `Domain/`, a module's `Service/` passes it as a string.

```php
#[ORM\Id]
#[ORM\Column(type: UlidType::NAME)]
private Ulid $id;

public function __construct(/* ... */)
{
    $this->id = new Ulid();
    // ...
}
```

## Consequences

- Identifiers are opaque and cannot be enumerated, which suits the API and the hosted layer.
- Identifiers are unique across installs, so data can move between a self-hosted install and the hosted service without renumbering.
- An entity has its identity before it is persisted: it can be referenced, dispatched in a Messenger message or logged right away.
- ULIDs start with a millisecond timestamp, so they sort in creation order and B-tree index inserts stay mostly sequential, unlike random UUIDs (v4).
- Keys take 16 bytes instead of 4 (or 8). This is negligible at Polaris's scale.
- The embedded timestamp comes from the system clock, not `ClockInterface`. It orders rows; it is never read as business time. Use a dedicated, clock-driven column for any time the application relies on.
- Raw SQL and DBAL queries must bind identifiers with the `ulid` type (`[$id], [UlidType::NAME]`); an unbound `Ulid` is sent in its base 32 form, which PostgreSQL rejects as a `UUID`.

## Alternatives considered

- Auto-incremented integers: smallest and simplest, but enumerable and local to one database, and only known after the flush.
- UUID v4: opaque and globally unique, but random, so index inserts scatter and ids do not sort by creation.
- UUID v7: time-ordered like a ULID and a standard (RFC 9562). Either would fit; ULID was chosen for its shorter, case-insensitive text form in URLs. Both use the same `UUID` column, so switching later only changes the generator.
- Doctrine's `doctrine.ulid_generator`: keeps generation out of the entity, but the id only exists after `persist()`.

## References

- [Symfony UID component (8.1)](https://symfony.com/doc/current/components/uid.html)
- [ULID specification](https://github.com/ulid/spec)
- [ADR 0001: Organize the application by feature modules](0001-feature-modules.md)

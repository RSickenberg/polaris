# ADR 0001: Organize the application by feature modules

- Status: Accepted
- Date: 2026-09-26

## Context

Polaris is a single Symfony 8.1 application at the repository root (namespace `Polaris\`). It will grow beyond the MVP: a private hosted layer built on top of it, a REST API for an iOS app, several external integrations (Tesla Fleet API, mail, push), and a long maintenance life.

The Symfony 8.1 best practices say not to create bundles to organize application logic, and to use PHP namespaces to structure the code instead. They also describe a default structure organized by technical type (`src/Controller`, `src/Entity`, ...). That default keeps Flex recipes and MakerBundle working without configuration, but it only separates code by type: the boundaries between business areas, and between the public app and the hosted layer, exist by convention only.

We accept a slightly higher setup cost to get boundaries that stay clear over years.

## Decision

### Feature modules, as namespaces

`src/` is organized by business area. Each module is a namespace, not a bundle. Inside a module, the usual Symfony type folders are kept, so the code still reads like a Symfony project.

| Module | Responsibility |
| --- | --- |
| `Shared` | Value objects and helpers used everywhere: `Distance` (stored in metres), `Money`, clock helpers |
| `Account` | Users, login, API tokens, devices |
| `Vehicle` | Vehicles (VIN, display name, owner) |
| `Lease` | Lease contracts, allowance, projection, budgets, risk levels |
| `Reading` | Odometer readings, manual entry, history |
| `Tesla` | Fleet API integration: client, key pair, partner registration, OAuth tokens |
| `Fetching` | Scheduled and manual odometer fetches, rate limits, wake rules |
| `Alert` | Emails and push notifications |
| `Dashboard` | Pages that compose other modules for display |
| `Extension` | The contract with the hosted layer: interfaces and their self-hosted defaults |

A module uses only the folders it needs:

```
src/Lease/
  Domain/          # plain PHP: ProjectionCalculator, Allowance, RiskLevel, budgets
  Entity/          # Doctrine entities, mapped with attributes
  Repository/
  Service/         # use cases called by controllers, commands and other modules
  Message/         # Messenger messages
  MessageHandler/
  EventListener/
  Controller/
  Form/
  Command/
  Twig/Components/
```

Templates live in `templates/<module>/` (for example `templates/lease/settings.html.twig`). Twig components live in the module, their templates in `templates/components/<Module>/`.

### Dependency rules

1. `Domain/` is plain PHP. It may use `Shared\Domain` and `Psr\Clock\ClockInterface`, nothing from Symfony, Doctrine or HTTP. This is where the calculations live, and they are unit-tested without booting the kernel.
2. A module calls another module only through that module's `Service/` classes, its entities (read access and Doctrine relations), or Messenger messages and events. Never through another module's controllers, forms, repositories or handlers.
3. `Dashboard` may read from every module; no module depends on `Dashboard`.
4. The hosted layer (`polaris-backend`) hooks into Polaris only through `Extension/` interfaces, replaced with service decoration.
5. The rules are enforced by Deptrac in CI (issue #11), so a violation fails the build instead of relying on review.

### Framework wiring (deviations from recipe defaults)

- Doctrine: one attribute mapping over `src/` with prefix `Polaris` instead of the recipe's `src/Entity` and `App\Entity`.
- Services: `config/services.yaml` loads `Polaris\` from `../src/` and excludes `Kernel.php` and `../src/*/Entity/`.
- Routes: the Symfony 8.1 recipe imports attribute routes from controller services (`resource: routing.controllers`), not from a directory; controllers anywhere in `src/` should be picked up. To confirm with the first controller.
- MakerBundle: pass absolute class names, for example `bin/console make:entity '\Polaris\Lease\Entity\LeaseContract'`.

## Consequences

- Everything about one business area sits together, which keeps changes local as the code grows.
- The boundary with the hosted layer is explicit and checked.
- The calculations stay framework-free and fast to test.
- Cost: a few configuration lines that differ from recipe defaults, longer MakerBundle commands, and Deptrac to maintain.
- If a second application ever needs to reuse part of Polaris, the module boundaries make extracting a bundle a mechanical refactor.

## Alternatives considered

- Symfony default structure by type: simplest to start, but business boundaries and the hosted contract rely on convention only.
- Bundles per feature: explicitly discouraged by the Symfony best practices for application code.
- Full hexagonal layering (Domain, Application, Infrastructure, UI in every module): more ceremony than this project needs; only the `Domain/` part is kept.

## References

- [Symfony best practices (8.1)](https://symfony.com/doc/current/best_practices.html)
- [Symfony bundles (8.1)](https://symfony.com/doc/current/bundles.html)
- [Deptrac](https://github.com/deptrac/deptrac)

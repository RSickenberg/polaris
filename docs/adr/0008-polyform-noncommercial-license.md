# ADR 0008: License Polaris under PolyForm Noncommercial

- Status: Accepted
- Date: 2026-10-15

## Context

Polaris was published under the AGPL-3.0-or-later. That licence lets anyone host the application and charge for it, as long as they publish their changes. The maintainer's own hosted platform is meant to be the billable offer, and the self-hosted edition is meant for people who run the image on their own NAS, Home Assistant or Docker host.

## Decision

- From this change on, the repository is under the [PolyForm Noncommercial License 1.0.0](../../LICENSE) (SPDX `PolyForm-Noncommercial-1.0.0`). Personal, noncommercial use is allowed; commercial use, including hosting for others, needs a separate license ([COMMERCIAL-LICENSE.md](../../COMMERCIAL-LICENSE.md)).
- The copyright is held by the maintainer alone, so the licence can change. Versions already published under the AGPL stay available under it to those who received them; the grant cannot be withdrawn.
- No outside contribution is merged until a Contributor License Agreement that allows relicensing is published (CLA.md).
- Third-party components of the image keep their own licences.

## Consequences

- The project is source-available, no longer open source in the OSI sense. Docs say "self-hostable", not "open source".
- A free noncommercial fork is allowed by the licence. A company running Polaris for its own business is not.
- The hosted layer, owned by the maintainer, is not affected.

## Alternatives considered

- Keep the AGPL: anyone may host it commercially.
- Elastic License 2.0 or FSL: bar only offering it as a service, so companies could self-host. Wider than the intent.
- A custom licence limited to running the official image: closer to the intent, but needs a lawyer.

## References

- [PolyForm Noncommercial 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0)
- [ADR 0004: Each user brings their own Tesla developer app](0004-bring-your-own-tesla-app.md)

# ADR 0006: Protect the self-hosted app with a single-user form login, denied by default

- Status: Accepted
- Date: 2026-10-15

## Context

A self-hosted Polaris holds one person's odometer data and, later, their Tesla tokens. It has to sit behind a login, without a sign-up flow, and a page added later must not ship unprotected by mistake. The hosted layer has its own account policy and builds on this image.

## Decision

- One web firewall with Symfony `form_login` and `logout`, both with stateless CSRF (token ids `authenticate` and `logout`). The API firewall of [ADR 0005](0005-api-opaque-tokens.md) comes separately.
- A self-hosted install has a single user. `Account\Service\UserCreator` is the only place that creates it and refuses a second one; `polaris:user:create` is the only front end. There is no sign-up page. The hosted layer decorates `UserCreator` to apply its own policy.
- The password is at least 12 characters, with no composition rules, and is stored only as a hash. The command reads it from a hidden prompt or the standard input, never from an argument or option. `NotCompromisedPassword` is not used: it calls an external API and self-hosters may be offline.
- Login throttling: 5 failed attempts per email and IP address per 15 minutes, plus Symfony's global per-IP limit (5 times that). The state lives in `cache.rate_limiter`, which is shared by the workers of one container.
- Access is denied by default: the last `access_control` rule requires `ROLE_USER` for `^/`. The public exceptions are `/login` and `/locale/`, listed before it. `AccessControlTest` walks the router and fails on any route an anonymous visitor can open that is not on its allow-list.
- The firewall that leaves the profiler, the toolbar and the AssetMapper routes open is driven by the parameter `polaris.security.open_paths`, which matches nothing in production.
- `LocaleListener` runs before the firewall, so the token is not available when the locale is negotiated. The user's locale is copied into the session on login and on a language switch, and read from there by a provider that decorates the cookie one.
- The table is `app_user`, as `user` is reserved in PostgreSQL. Every `Vehicle` has a required owner.

## Consequences

- A new page is protected without any change; a public page needs an explicit, tested entry.
- A forgotten password is reset by email: a single-use link valid for an hour, with only a hash of its secret stored, an answer that does not tell whether the email has an account, and a link built from the configured public address. The app therefore needs a working mailer.
- Throttling counters are per container: several containers behind a load balancer need a shared pool (Redis, planned).
- A vehicle is reachable only by its owner (`VehicleVoter`, answering 404 for anyone else), so a second user, for example in the hosted layer, never sees another's data.

## Password reset

- `/forgot-password` emails a link to `/reset-password/{token}`. The token is a 20-character selector plus a 43-character verifier; the database holds the selector and the sha256 of the verifier, so a leak gives no usable link. It is valid for one hour and deleted once used, or when the password changes by any route.
- The answer is the same for an unknown email. At most 5 links are asked for per client address and hour, and an account gets no new link within 2 minutes of the previous one.
- The link is built from `DEFAULT_URI`, not from the Host header, which an attacker controls.
- Built from Symfony components (Mailer, Rate Limiter) rather than a third-party reset bundle.

## Alternatives considered

- Allow several users: needs roles and ownership checks that a single-owner install does not need.
- A sign-up page for the first user: leaves the app open until someone signs up.
- Allow-list of protected paths instead of default deny: a forgotten path is public.
- Read `User.locale` from the token: not available at the listener's priority; moving the listener after the firewall would be too late for the translator and Twig.

## References

- [Symfony security (8.1)](https://symfony.com/doc/current/security.html)
- [ADR 0003: Store, compute and exchange every date in UTC](0003-utc-dates.md)
- [ADR 0005: Authenticate the API with opaque tokens issued by Polaris](0005-api-opaque-tokens.md)

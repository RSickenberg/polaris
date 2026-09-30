# ADR 0005: Authenticate the API with opaque tokens issued by Polaris

- Status: Accepted (applies when the REST API is built)
- Date: 2026-09-30

## Context

A future SwiftUI iOS app will talk to Polaris through a REST API (API Platform, OpenAPI). The Symfony backend is the single source of truth: it owns accounts, authentication, readings, projections and Tesla tokens. The app only displays data and sends requests.

Symfony's first-party `access_token` authenticator validates tokens but does not issue them, so Polaris has to decide how tokens are created, stored, rotated and revoked.

## Decision

- The iOS app logs in with the same Polaris account as the web (email and password). No Sign in with Tesla on the phone, no separate identity provider.
- `POST /api/auth/login` (Symfony `json_login` on an `api_login` firewall, with login throttling) returns a short-lived access token and a long-lived refresh token.
- The `api` firewall uses the `access_token` authenticator with a Bearer header and a custom `AccessTokenHandlerInterface` that looks the token up in the database.
- Tokens are opaque random strings, stored hashed in an `ApiToken` entity in the `Account` module: user, type (access or refresh), device name, `expiresAt`, `lastUsedAt`, `revokedAt`, all UTC (ADR 0003).
- Starting durations, configurable: access token about 15 minutes; refresh token about 30 days, single-use and rotated on every `POST /api/auth/refresh`.
- Web Settings lists the user's devices and can revoke each one; a password change revokes every API token.
- The app keeps its tokens in the iOS Keychain. It never receives Tesla credentials or tokens and never calls Tesla directly.

## Consequences

- Any token can be revoked at once, per device, because every request checks the database.
- Only hashes are stored, so a database leak does not expose usable tokens.
- Each API request costs a token lookup; acceptable at Polaris's scale, and cacheable later if needed.
- Refresh-token rotation needs the same care as Tesla token rotation: a transaction so two concurrent refreshes cannot both succeed.

## Alternatives considered

- JWT access tokens: no database lookup per request, but revoking one before it expires needs a deny list, which brings the lookup back.
- An OAuth 2 / OpenID Connect server (for example a dedicated bundle or an external identity provider): standard, but far more machinery than a first-party app with one client needs.
- Session cookies for the app: simple, but a poor fit for a native client and for the API's statelessness.

## References

- [Symfony access tokens (8.1)](https://symfony.com/doc/current/security/access_token.html)
- [ADR 0003: Store, compute and exchange every date in UTC](0003-utc-dates.md)

# ADR 0004: Each user brings their own Tesla developer app

- Status: Accepted
- Date: 2026-09-30

## Context

Polaris reads the odometer through the official Tesla Fleet API. Access requires a developer app registered on developer.tesla.com, a public key hosted on a domain declared in the app's allowed origins, and a partner registration per region. Tesla bills the app's API usage to its developer account, pay-per-use since 1 January 2025, with a $10 monthly discount per developer account.

A single shared app for every Polaris user would put all users' tokens, API bills and access behind one account, domain and agreement, in both the self-hosted edition and the hosted platform.

## Decision

- Polaris ships no Tesla credentials. Each user registers their own Fleet API app and enters its client ID, client secret and region in Polaris.
- Two ways to connect, the user picks one: the in-app OAuth flow (authorize, callback, code exchange with the user's client ID and secret), or pasting a refresh token obtained outside Polaris, which Polaris then only refreshes.
- Polaris can generate the secp256r1 key pair and call the register endpoint (`polaris:tesla:keygen`, `polaris:tesla:register`). The private key never leaves the server.
- Self-hosted: the user hosts the public key on a domain they own. Hosted platform: the platform serves each user's public key on a per-user subdomain, if Tesla accepts it (open question in the architecture doc).
- Scopes requested: `openid`, `offline_access`, `vehicle_device_data`; `vehicle_cmds` only if the optional wake ships.
- Client secret, private key and tokens are encrypted at rest with a key from Symfony secrets.

## Consequences

- No third party holds the user's Tesla tokens, and the platform has no Tesla bill: each user's usage is billed to their own developer account, normally inside the monthly discount at Polaris's fetch rates.
- Tesla can suspend one app without affecting other users.
- Onboarding is heavier: each user creates a developer app, accepts the Fleet API Agreement as a Partner and sets a billing method with Tesla. The hosted platform's main job is a guided enrollment wizard.
- Whether a private individual can create an app, and whether a per-user subdomain is accepted as register domain, are unverified (spike #15 and the architecture doc's open questions).

## Alternatives considered

- One Polaris developer app for all users: simplest onboarding, but the platform pays every user's API usage, holds everyone's tokens and depends on a single app staying approved.
- A third-party API proxy (for example a paid Tesla API relay): no developer app to create, but a third party holds the tokens and bills per vehicle.

## References

- [Tesla Fleet API, What is Fleet API](https://developer.tesla.com/docs/fleet-api/getting-started/what-is-fleet-api)
- [Tesla Fleet API, Third-party tokens](https://developer.tesla.com/docs/fleet-api/authentication/third-party-tokens)
- [Tesla Fleet API, Partner endpoints](https://developer.tesla.com/docs/fleet-api/endpoints/partner-endpoints)
- [Tesla Fleet API, Billing and limits](https://developer.tesla.com/docs/fleet-api/billing-and-limits)
- [Tesla Fleet API, Announcements](https://developer.tesla.com/docs/fleet-api/announcements)

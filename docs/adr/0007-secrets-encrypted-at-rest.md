# ADR 0007: Encrypt stored secrets with libsodium and a key outside the database

- Status: Accepted
- Date: 2026-10-15

## Context

[ADR 0004](0004-bring-your-own-tesla-app.md) stores the Tesla private key, the client secret and the tokens in the database, "encrypted at rest with a key from Symfony secrets". A database dump or backup must not be enough to use them, and the format must allow the key to change later.

## Decision

- `Shared\Service\SecretEncrypter` encrypts with libsodium `secretbox` (XSalsa20-Poly1305), a random 24-byte nonce per value, through `ext-sodium`, which PHP 8.5 ships with. No third-party library.
- The stored value is `v1:` followed by the base64 of nonce and box. The prefix names the format: a later version can change the algorithm or add a key identifier, and `decrypt()` rejects an unknown version with `DecryptionFailed`. Rotation itself is not built yet.
- The key is 32 random bytes in base64, read from `POLARIS_ENCRYPTION_KEY` (`openssl rand -base64 32`). Symfony exposes secrets of the vault (`bin/console secrets:set`) as environment variables, so either source works. The key is not in the database, `.env` or the repository; `.env.example` lists it empty and `.env.test` holds a fake one.
- Losing the key makes the stored secrets unreadable. The Tesla credentials would be entered again and a new key pair generated, which means registering with Tesla again. Back the key up with the database.
- Entities keep only the ciphertext, and a safe `__serialize` leaves it out of the session and any serialization.

## Consequences

- A leaked database dump alone does not expose a Tesla secret. A host compromise that exposes both the key and the database does.
- The same service will encrypt the client secret and the tokens (#24, #25).

## Alternatives considered

- OpenSSL AES-256-GCM: equally sound, but more parameters to get wrong (IV, tag handling) than `secretbox`.
- A third-party bundle for encrypted columns: one more dependency for a few columns.
- Storing the private key in clear: refused by ADR 0004.

## References

- [PHP, Sodium secretbox](https://www.php.net/manual/en/function.sodium-crypto-secretbox.php)
- [Symfony, secrets](https://symfony.com/doc/current/configuration/secrets.html)

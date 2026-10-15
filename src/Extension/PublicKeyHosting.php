<?php

declare(strict_types=1);

namespace Polaris\Extension;

/**
 * Where Tesla finds the public key: https://<domain>/.well-known/appspecific/com.tesla.3p.public-key.pem.
 *
 * The self-hosted default is chosen by configuration (POLARIS_KEY_HOSTING): Polaris serves the key
 * itself, or the key is written to a directory of a static site hosted elsewhere. The hosted layer
 * replaces it (service decoration or a new alias), for example to serve a key per subdomain.
 * Only the public key ever goes through this interface.
 */
interface PublicKeyHosting
{
    /**
     * Makes a new public key available, when hosting needs an action (a file to write).
     *
     * @throws \RuntimeException when the key cannot be published
     */
    public function publish(string $publicKeyPem): void;

    /**
     * Whether Polaris answers the well-known URL itself.
     */
    public function servesPublicKey(): bool;
}

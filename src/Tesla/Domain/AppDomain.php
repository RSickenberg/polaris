<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * The domain that hosts the public key (https://<domain>/.well-known/appspecific/...): a bare,
 * lower-cased host name, without scheme, port or path. Whether Tesla accepts a given domain is not
 * checked here.
 */
final readonly class AppDomain implements \Stringable
{
    private const int MAX_LENGTH = 253;

    private function __construct(private string $host)
    {
    }

    /**
     * @throws InvalidAppDomain
     */
    public static function fromString(string $value): self
    {
        $host = strtolower(trim($value));

        if ('' === $host) {
            throw new InvalidAppDomain('The domain is empty.');
        }

        if (\strlen($host) > self::MAX_LENGTH) {
            throw new InvalidAppDomain('The domain is too long.');
        }

        if (str_contains($host, '://') || str_contains($host, '/')) {
            throw new InvalidAppDomain('Give the domain only, without https:// or a path.');
        }

        if (str_contains($host, ':')) {
            throw new InvalidAppDomain('Give the domain only, without a port.');
        }

        // At least two labels, each of letters, digits and inner hyphens.
        if (1 !== preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $host)) {
            throw new InvalidAppDomain('The domain is not a valid host name, for example polaris.example.com.');
        }

        return new self($host);
    }

    public function toString(): string
    {
        return $this->host;
    }

    public function publicKeyUrl(): string
    {
        return 'https://' . $this->host . '/.well-known/appspecific/com.tesla.3p.public-key.pem';
    }

    public function __toString(): string
    {
        return $this->host;
    }
}

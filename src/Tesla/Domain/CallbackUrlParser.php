<?php

declare(strict_types=1);

namespace Polaris\Tesla\Domain;

/**
 * Reads the code, the state and the error out of a callback URL that the user pasted. Only the query
 * string matters: the host is the one the browser could not reach.
 */
final class CallbackUrlParser
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidCallbackUrl
     */
    public static function parse(#[\SensitiveParameter] string $url): CallbackParameters
    {
        $query = parse_url(trim($url), \PHP_URL_QUERY);

        if (!\is_string($query) || '' === $query) {
            throw new InvalidCallbackUrl('The URL has no query string.');
        }

        parse_str($query, $fields);

        return new CallbackParameters(
            self::string($fields['code'] ?? null),
            self::string($fields['state'] ?? null),
            self::string($fields['error'] ?? null),
        );
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}

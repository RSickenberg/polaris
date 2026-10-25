<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Exception;

use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Service\Enum\FleetApiOperation;
use Symfony\Component\HttpFoundation\Response;

/**
 * A failed Fleet API call, with a message for the person running the command: what was attempted,
 * what the status usually means, and Tesla's own words. The error body of Tesla is not documented,
 * so only the OAuth fields (error, error_description) and a few common ones are read, and anything
 * else falls back to a truncated body.
 */
final class FleetApiError extends \RuntimeException
{
    /**
     * Tesla's own status for a vehicle that answered with an error; Symfony has no constant for it.
     */
    public const int HTTP_DEVICE_UNEXPECTED_RESPONSE = 540;

    private const int MAX_DETAIL = 200;

    private function __construct(
        string $message,
        public readonly FleetApiOperation $operation,
        public readonly FleetRegion $region,
        public readonly ?int $httpStatus = null,
        public readonly ?string $oauthError = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param list<string> $secrets values that must never appear in the message
     */
    public static function fromResponse(
        FleetApiOperation $operation,
        FleetRegion $region,
        int $httpStatus,
        string $body,
        array $secrets = [],
    ): self {
        $detail = self::detail($body, $secrets);

        return new self(
            \sprintf(
                '%s in region %s failed: %s (HTTP %d%s).',
                $operation->describe(),
                $region->value,
                self::hint($httpStatus),
                $httpStatus,
                '' === $detail ? '' : ': ' . $detail,
            ),
            $operation,
            $region,
            $httpStatus,
            self::oauthErrorCode($body),
        );
    }

    public static function unreachable(
        FleetApiOperation $operation,
        FleetRegion $region,
        string $reason,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            \sprintf('%s in region %s failed: Tesla could not be reached (%s).', $operation->describe(), $region->value, $reason),
            $operation,
            $region,
            null,
            null,
            $previous,
        );
    }

    public static function unexpected(FleetApiOperation $operation, FleetRegion $region, string $what): self
    {
        return new self(
            \sprintf('%s in region %s failed: %s.', $operation->describe(), $region->value, $what),
            $operation,
            $region,
        );
    }

    /**
     * Whether Tesla refused the refresh token itself (not the network, not the app): it is expired, was
     * replaced by a newer one, or the password was reset. Tesla documents 401 login_required for it; the
     * standard OAuth invalid_grant is accepted too.
     */
    public function refreshTokenRejected(): bool
    {
        return FleetApiOperation::Refresh === $this->operation
            && (\in_array($this->oauthError, ['invalid_grant', 'login_required'], true) || Response::HTTP_UNAUTHORIZED === $this->httpStatus);
    }

    /**
     * HTTP 408: the car was not online when the request was made. Not a failure of Polaris or Tesla.
     */
    public function isVehicleNotOnline(): bool
    {
        return Response::HTTP_REQUEST_TIMEOUT === $this->httpStatus;
    }

    private static function oauthErrorCode(string $body): ?string
    {
        $data = json_decode($body, true);
        $code = \is_array($data) ? ($data['error'] ?? null) : null;

        return \is_string($code) && '' !== $code ? $code : null;
    }

    private static function hint(int $status): string
    {
        return match (true) {
            Response::HTTP_BAD_REQUEST === $status => 'Tesla rejected the request',
            Response::HTTP_UNAUTHORIZED === $status => 'Tesla did not accept the credentials, check the client ID and the client secret',
            Response::HTTP_FORBIDDEN === $status => 'Tesla refused access, check that the app is approved and that the domain matches an allowed origin',
            Response::HTTP_NOT_FOUND === $status => 'Tesla does not know this endpoint or domain',
            Response::HTTP_REQUEST_TIMEOUT === $status => 'the vehicle is not online',
            Response::HTTP_PRECONDITION_FAILED === $status => 'a Tesla precondition is not met, check that the app is registered and that the vehicle supports the call',
            Response::HTTP_MISDIRECTED_REQUEST === $status => 'the Tesla account is in another region, check the enabled regions of the app',
            Response::HTTP_UNPROCESSABLE_ENTITY === $status => 'Tesla could not validate the request or the public key',
            Response::HTTP_TOO_MANY_REQUESTS === $status => 'Tesla is rate-limiting the requests, try again later',
            self::HTTP_DEVICE_UNEXPECTED_RESPONSE === $status => 'the vehicle returned an error, it may need a reboot or service',
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR => 'Tesla failed to answer, try again later',
            default => 'Tesla answered an unexpected status',
        };
    }

    /**
     * @param list<string> $secrets
     */
    private static function detail(string $body, array $secrets): string
    {
        $data = json_decode($body, true);
        $text = $body;

        if (\is_array($data)) {
            $parts = [];
            foreach (['error', 'error_description', 'error_message', 'message'] as $field) {
                if (\is_string($data[$field] ?? null) && '' !== $data[$field]) {
                    $parts[] = $data[$field];
                }
            }
            $text = implode(', ', array_unique($parts));
        }

        foreach ($secrets as $secret) {
            if ('' !== $secret) {
                $text = str_replace($secret, '***', $text);
            }
        }

        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        return mb_strlen($text) > self::MAX_DETAIL ? mb_substr($text, 0, self::MAX_DETAIL) . '...' : $text;
    }
}

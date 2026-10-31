<?php

declare(strict_types=1);

namespace Polaris\Shared\Reporting;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Logs\Log;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes from what the SDK is about to send everything that must never leave the instance: VINs (also inside Tesla
 * Fleet API URLs and span descriptions), bearer tokens and Authorization values, client secrets, private keys, the
 * encryption key, emails, locations. Vehicle data (the answer of the car) goes unless the user allowed it.
 *
 * Odometer values and lease figures are not touched: the owner decided they help to debug.
 */
final readonly class Scrubber
{
    public const string FILTERED = '[Filtered]';

    /** A VIN: 17 characters without I, O or Q, with at least one digit. */
    private const string VIN = '/(?<![A-Za-z0-9])(?=[A-HJ-NPR-Z]*\d)[A-HJ-NPR-Z0-9]{17}(?![A-Za-z0-9])/';

    private const array PATTERNS = [
        // A private key, whole.
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s',
        // Authorization headers and bearer tokens, JSON web tokens.
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i',
        '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/',
        // A secret encrypted by SecretEncrypter (ADR 0007).
        '/\bv1:[A-Za-z0-9+\/=]{16,}/',
        // Secrets in a query string or a form body.
        '/\b((?:access_token|refresh_token|client_secret|code|state|password|token)=)[^&\s"\']+/i',
        // An email address.
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
    ];

    private const string SENSITIVE_KEY = '/secret|token|password|passwd|authorization|cookie|private|api[_-]?key|credential|csrf|encryption|session|email|e-mail/i';

    private const array LOCATION_KEYS = [
        'latitude', 'longitude', 'heading', 'native_latitude', 'native_longitude', 'native_location_supported',
        'native_type', 'gps_as_of', 'location_data', 'location',
    ];

    /** Keys that hold the answer of the car (see Tesla\Domain\VehicleDataRedactor for what is stripped before it is stored). */
    private const array VEHICLE_DATA_KEYS = [
        'vehicle_data', 'vehicle_state', 'drive_state', 'charge_state', 'climate_state', 'gui_settings',
        'vehicle_config', 'payload',
    ];

    public function __construct(
        #[Autowire('%env(default::POLARIS_ENCRYPTION_KEY)%')]
        #[\SensitiveParameter]
        private ?string $encryptionKey = null,
    ) {
    }

    public function scrubString(string $value): string
    {
        if (null !== $this->encryptionKey && \strlen($this->encryptionKey) >= 8) {
            $value = str_replace($this->encryptionKey, self::FILTERED, $value);
        }

        foreach (self::PATTERNS as $pattern) {
            $value = preg_replace_callback(
                $pattern,
                static fn (array $match): string => isset($match[1]) ? $match[1] . self::FILTERED : self::FILTERED,
                $value,
            ) ?? self::FILTERED;
        }

        return preg_replace(self::VIN, self::FILTERED, $value) ?? self::FILTERED;
    }

    /**
     * @param bool $details whether vehicle data may stay
     */
    public function scrub(mixed $value, bool $details, ?string $key = null): mixed
    {
        if (null !== $key) {
            $lower = mb_strtolower($key);
            if (\in_array($lower, self::LOCATION_KEYS, true)) {
                return null;
            }

            if (1 === preg_match(self::SENSITIVE_KEY, $key)) {
                return null === $value ? null : self::FILTERED;
            }

            if (!$details && \in_array($lower, self::VEHICLE_DATA_KEYS, true)) {
                return null === $value ? null : self::FILTERED;
            }
        }

        if (\is_string($value)) {
            return $this->scrubString($value);
        }

        if ($value instanceof \Throwable) {
            return $this->scrubString($value::class . ': ' . $value->getMessage());
        }

        if (\is_object($value)) {
            return $value instanceof \JsonSerializable ? $this->scrub($value->jsonSerialize(), $details) : $this->scrubString($value::class);
        }

        if (\is_array($value)) {
            $clean = [];
            foreach ($value as $name => $item) {
                $clean[$name] = $this->scrub($item, $details, \is_string($name) ? $name : null);
            }

            return $clean;
        }

        return $value;
    }

    /**
     * Event and transaction, with their spans.
     */
    public function scrubEvent(Event $event, bool $details): Event
    {
        if (null !== $event->getMessage()) {
            $event->setMessage(
                $this->scrubString($event->getMessage()),
                array_map($this->scrubString(...), $event->getMessageParams()),
                null === $event->getMessageFormatted() ? null : $this->scrubString($event->getMessageFormatted()),
            );
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($this->scrubString($exception->getValue()));
        }

        $event->setExtra($this->mapOf($this->scrub($event->getExtra(), $details)));
        $event->setRequest($this->mapOf($this->scrub($event->getRequest(), $details)));
        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, $this->mapOf($this->scrub($context, $details)));
        }

        $tags = [];
        foreach ($event->getTags() as $tag => $tagValue) {
            $tags[$tag] = $this->scrubString($tagValue);
        }
        $event->setTags($tags);

        if (null !== $event->getTransaction()) {
            $event->setTransaction($this->scrubString($event->getTransaction()));
        }

        $event->setBreadcrumb(array_map(fn (Breadcrumb $breadcrumb): Breadcrumb => $this->scrubBreadcrumb($breadcrumb), $event->getBreadcrumbs()));

        foreach ($event->getSpans() as $span) {
            if (null !== $span->getDescription()) {
                $span->setDescription($this->scrubString($span->getDescription()));
            }
            $span->setData($this->mapOf($this->scrub($span->getData(), $details)));
        }

        return $event;
    }

    public function scrubLog(Log $log, bool $details): Log
    {
        $log->setBody($this->scrubString($log->getBody()));

        foreach ($log->attributes()->all() as $name => $attribute) {
            $clean = $this->scrub($attribute->getValue(), $details, $name);
            if (null === $clean) {
                $log->attributes()->forget($name);

                continue;
            }

            $log->setAttribute($name, $clean);
        }

        return $log;
    }

    public function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $clean = $breadcrumb;
        if (null !== $breadcrumb->getMessage()) {
            $clean = $clean->withMessage($this->scrubString($breadcrumb->getMessage()));
        }

        // A breadcrumb is written before anyone knows whose report it joins: vehicle data never stays in one.
        foreach ($clean->getMetadata() as $name => $metadata) {
            $clean = $clean->withMetadata((string) $name, $this->scrub($metadata, false, (string) $name));
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOf(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }
}

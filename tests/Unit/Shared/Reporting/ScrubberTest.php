<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Shared\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Shared\Reporting\Scrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

#[CoversClass(Scrubber::class)]
final class ScrubberTest extends TestCase
{
    private const string VIN = '5YJ3E7EB2NF000091';
    private const string KEY = 'c2VjcmV0LWtleS1mb3ItdGhlLXRlc3Q=';

    private Scrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new Scrubber(self::KEY);
    }

    public function testAVinIsRemovedAlsoInsideAFleetApiUrl(): void
    {
        $url = 'https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . self::VIN . '/vehicle_data?endpoints=vehicle_state';

        self::assertSame(
            'https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/[Filtered]/vehicle_data?endpoints=vehicle_state',
            $this->scrubber->scrubString($url),
        );
    }

    public function testAUlidAndAnOdometerValueAreKept(): void
    {
        $text = 'vehicle 01JC3F0ZQ4N8T2W6X9Y1A5B7CD read_m=12345678';

        self::assertSame($text, $this->scrubber->scrubString($text));
    }

    public function testTokensSecretsKeysAndEmailsAreRemovedFromText(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\nMIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg\n-----END PRIVATE KEY-----";
        $text = implode(' | ', [
            'Authorization: Bearer abc.def-123',
            'jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVP',
            'code=secret-code&state=abc',
            'mail jane.doe@example.test',
            $pem,
            'key ' . self::KEY,
            'cipher v1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        ]);

        $clean = $this->scrubber->scrubString($text);

        foreach (['abc.def-123', 'eyJhbGci', 'secret-code', 'jane.doe@example.test', 'MIGHAgEA', self::KEY, 'AAAAAAAAAAAAAAAA'] as $leak) {
            self::assertStringNotContainsString($leak, $clean);
        }
    }

    public function testSensitiveAndLocationKeysAreRemovedAtAnyDepth(): void
    {
        $clean = $this->scrubber->scrub([
            'vehicle' => '01JC3F0ZQ4N8T2W6X9Y1A5B7CD',
            'read_m' => 12_345,
            'client_secret' => 'shh',
            'headers' => ['Authorization' => 'Bearer x', 'Accept' => 'application/json'],
            'drive' => ['latitude' => 46.2, 'longitude' => 6.1, 'speed' => 0],
        ], false);

        self::assertSame([
            'vehicle' => '01JC3F0ZQ4N8T2W6X9Y1A5B7CD',
            'read_m' => 12_345,
            'client_secret' => '[Filtered]',
            'headers' => ['Authorization' => '[Filtered]', 'Accept' => 'application/json'],
            'drive' => ['latitude' => null, 'longitude' => null, 'speed' => 0],
        ], $clean);
    }

    public function testVehicleDataGoesUnlessTheUserAllowedIt(): void
    {
        $context = ['vehicle_data' => ['vehicle_state' => ['odometer' => 1234.5], 'charge_state' => ['battery_level' => 80]], 'rule' => 'LowerThanStored'];

        self::assertSame(['vehicle_data' => '[Filtered]', 'rule' => 'LowerThanStored'], $this->scrubber->scrub($context, false));
        self::assertSame($context, $this->scrubber->scrub($context, true));
    }

    public function testLocationStaysOutEvenWhenTheUserAllowedVehicleData(): void
    {
        $clean = $this->scrubber->scrub(['vehicle_data' => ['drive_state' => ['latitude' => 1.0, 'longitude' => 2.0, 'shift_state' => null], 'vin' => self::VIN]], true);

        self::assertSame(['vehicle_data' => ['drive_state' => ['latitude' => null, 'longitude' => null, 'shift_state' => null], 'vin' => '[Filtered]']], $clean);
    }

    public function testAnEventAndItsSpansAreScrubbed(): void
    {
        $event = Event::createTransaction();
        $event->setMessage('Listing failed for ' . self::VIN . ' by jane.doe@example.test');
        $event->setTransaction('GET /vehicles/' . self::VIN);
        $event->setExtra(['token' => 'abc', 'attempts' => 2]);
        $event->setTags(['vin' => self::VIN]);
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'GET /vehicles/' . self::VIN, ['url' => 'https://x.test/?refresh_token=zzz'])]);

        $context = new SpanContext();
        $context->setOp('http.client');
        $context->setDescription('GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . self::VIN . '/vehicle_data');
        $span = new Span($context);
        $span->setData(['http.url' => 'https://x.test/?access_token=zzz', 'http.response.status_code' => 200]);
        $event->setSpans([$span]);

        $clean = $this->scrubber->scrubEvent($event, false);

        $json = json_encode([
            $clean->getMessage(), $clean->getTransaction(), $clean->getExtra(), $clean->getTags(),
            array_map(static fn (Breadcrumb $b): array => [$b->getMessage(), $b->getMetadata()], $clean->getBreadcrumbs()),
            array_map(static fn (Span $s): array => [$s->getDescription(), $s->getData()], $clean->getSpans()),
        ], \JSON_THROW_ON_ERROR);

        foreach ([self::VIN, 'jane.doe@example.test', 'abc', 'zzz'] as $leak) {
            self::assertStringNotContainsString($leak, $json);
        }
        self::assertSame(2, $clean->getExtra()['attempts']);
        self::assertSame(200, $clean->getSpans()[0]->getData()['http.response.status_code']);
    }

    public function testALogBodyAndAttributesAreScrubbed(): void
    {
        $log = new Log(microtime(true), 'trace', LogLevel::warn(), 'Refused for ' . self::VIN . ' Bearer abc123');
        $log->setAttribute('email', 'jane.doe@example.test');
        $log->setAttribute('why', 'jane.doe@example.test failed');
        $log->setAttribute('read_m', 123);

        $clean = $this->scrubber->scrubLog($log, false);

        self::assertSame('Refused for [Filtered] [Filtered]', $clean->getBody());
        self::assertSame('[Filtered]', $clean->attributes()->get('email')?->getValue());
        self::assertSame('[Filtered] failed', $clean->attributes()->get('why')?->getValue());
        self::assertSame(123, $clean->attributes()->get('read_m')?->getValue());
    }
}

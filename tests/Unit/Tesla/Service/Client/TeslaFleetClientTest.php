<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Service\Client;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Account\Entity\User;
use Polaris\Tesla\Domain\ConnectionMethod;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Client\TeslaFleetClient;
use Polaris\Tesla\Service\Enum\FleetApiOperation;
use Polaris\Tesla\Service\Enum\VehicleState;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TokenProvider;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Vehicle\Domain\Vin;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(TeslaFleetClient::class)]
#[CoversClass(FleetApiError::class)]
final class TeslaFleetClientTest extends TestCase
{
    public const string ACCESS_TOKEN = 'secret-access-token';
    private const string NOW = '2026-10-24 12:00:00 UTC';
    private const string BASE_URL = 'https://fleet-api.prd.eu.vn.cloud.tesla.com';

    /** @var list<array{string, string, array<array-key, mixed>}> the method, URL and options of every HTTP call */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
    }

    public function testTheVehiclesAreListedFromTheFirstPageWithTheBearerToken(): void
    {
        $vehicles = $this->client([new MockResponse(FleetApiResponses::vehicleList())])->listVehicles($this->app());

        self::assertCount(2, $vehicles);
        self::assertSame(FleetApiResponses::VIN, $vehicles[0]->vin->value());
        self::assertSame('Lucia', $vehicles[0]->displayName);
        self::assertSame(VehicleState::Offline, $vehicles[0]->state);
        self::assertSame('Raven', $vehicles[1]->displayName);
        self::assertSame(VehicleState::Online, $vehicles[1]->state);
        self::assertSame(['GET', self::BASE_URL . '/api/1/vehicles'], [$this->calls[0][0], $this->calls[0][1]]);
        $headers = $this->calls[0][2]['headers'];
        self::assertIsArray($headers);
        self::assertContains('Authorization: Bearer ' . self::ACCESS_TOKEN, $headers);
    }

    public function testTheStateOfAVehicleIsReadByVin(): void
    {
        $state = $this->client([new MockResponse(FleetApiResponses::vehicle('online'))])->vehicleState($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertSame(VehicleState::Online, $state);
        self::assertSame(self::BASE_URL . '/api/1/vehicles/' . FleetApiResponses::VIN, $this->calls[0][1]);
    }

    public function testTheOdometerIsConvertedFromMilesAndReadAtTeslasTimestamp(): void
    {
        $reading = $this->client([new MockResponse(FleetApiResponses::vehicleData(980.388329))])->vehicleData($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertSame((int) round(980.388329 * 1609.344), $reading->odometer->metres());
        self::assertEquals(CarbonImmutable::createFromTimestampMsUTC(FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS), $reading->readAt);
        self::assertSame(0, $reading->readAt->getOffset());
        self::assertSame(FleetApiResponses::VEHICLE_DATA_TIMESTAMP_MS, (int) $reading->readAt->format('Uv'));
    }

    public function testTheAnswerIsKeptWithoutItsLocation(): void
    {
        $reading = $this->client([new MockResponse(FleetApiResponses::vehicleData(980.388329))])->vehicleData($this->app(), new Vin(FleetApiResponses::VIN));

        $charge = $reading->payload['charge_state'] ?? null;
        $vehicle = $reading->payload['vehicle_state'] ?? null;
        self::assertIsArray($charge);
        self::assertIsArray($vehicle);
        self::assertSame(76, $charge['battery_level'] ?? null);
        self::assertSame(980.388329, $vehicle['odometer'] ?? null);
        self::assertSame(['shift_state' => null], $reading->payload['drive_state'] ?? null, 'No coordinates, no heading.');
        self::assertStringNotContainsString('12.3456', json_encode($reading->payload, \JSON_THROW_ON_ERROR));
    }

    public function testWithoutATimestampTheClockIsTheReadTime(): void
    {
        $reading = $this->client([new MockResponse(FleetApiResponses::vehicleData(12345.678, null))])->vehicleData($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertSame((int) round(12345.678 * 1609.344), $reading->odometer->metres());
        self::assertEquals(CarbonImmutable::parse(self::NOW), $reading->readAt);
    }

    public function testTheLiveCallSendsNoQuery(): void
    {
        $this->client([new MockResponse(FleetApiResponses::vehicleData(1000))])->vehicleData($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertSame(self::BASE_URL . '/api/1/vehicles/' . FleetApiResponses::VIN . '/vehicle_data', $this->calls[0][1]);
        self::assertStringNotContainsString('location_data', $this->calls[0][1]);
    }

    public function testNoCallEverGoesToWakeUp(): void
    {
        $client = $this->client([
            new MockResponse(FleetApiResponses::vehicleList()),
            new MockResponse(FleetApiResponses::vehicle('online')),
            new MockResponse(FleetApiResponses::vehicleData(1000)),
        ]);
        $vin = new Vin(FleetApiResponses::VIN);

        $client->listVehicles($this->app());
        $client->vehicleState($this->app(), $vin);
        $client->vehicleData($this->app(), $vin);

        self::assertCount(3, $this->calls);
        foreach ($this->calls as [$method, $url]) {
            self::assertSame('GET', $method);
            self::assertStringNotContainsString('wake_up', $url);
        }
    }

    public function testAnUnknownVehicleStateIsAnUnexpectedAnswer(): void
    {
        $client = $this->client([new MockResponse(FleetApiResponses::vehicle('hibernating'))]);

        try {
            $client->vehicleState($this->app(), new Vin(FleetApiResponses::VIN));
            self::fail('Expected a FleetApiError.');
        } catch (FleetApiError $error) {
            self::assertSame(FleetApiOperation::VehicleState, $error->operation);
            self::assertStringContainsString('unknown vehicle state "hibernating"', $error->getMessage());
        }
    }

    public function testAnAnswerWithoutAnOdometerIsAnUnexpectedAnswer(): void
    {
        $client = $this->client([new MockResponse('{"response":{"vehicle_state":{"locked":true}}}')]);

        $this->expectException(FleetApiError::class);
        $this->expectExceptionMessage('no vehicle_state.odometer');

        $client->vehicleData($this->app(), new Vin(FleetApiResponses::VIN));
    }

    public function testANegativeOdometerIsAnUnexpectedAnswer(): void
    {
        $client = $this->client([new MockResponse(FleetApiResponses::vehicleData(-5))]);

        $this->expectException(FleetApiError::class);
        $this->expectExceptionMessage('not a valid distance');

        $client->vehicleData($this->app(), new Vin(FleetApiResponses::VIN));
    }

    public function testAVehicleWithoutAValidVinIsAnUnexpectedAnswer(): void
    {
        $client = $this->client([new MockResponse('{"response":[{"vin":"nope","state":"online"}]}')]);

        $this->expectException(FleetApiError::class);
        $this->expectExceptionMessage('not a valid VIN');

        $client->listVehicles($this->app());
    }

    public function testAServerErrorIsRetriedAndTheSecondTryWins(): void
    {
        $client = $this->client([
            new MockResponse('{}', ['http_code' => 503]),
            new MockResponse(FleetApiResponses::vehicle('asleep')),
        ]);

        self::assertSame(VehicleState::Asleep, $client->vehicleState($this->app(), new Vin(FleetApiResponses::VIN)));
        self::assertCount(2, $this->calls);
    }

    public function testRetriesStopAfterTwoAndTheErrorIsReported(): void
    {
        $client = $this->client([
            new MockResponse('{}', ['http_code' => 503]),
            new MockResponse('{}', ['http_code' => 503]),
            new MockResponse('{}', ['http_code' => 503]),
            new MockResponse(FleetApiResponses::vehicle('online')),
        ]);

        try {
            $client->vehicleState($this->app(), new Vin(FleetApiResponses::VIN));
            self::fail('Expected a FleetApiError.');
        } catch (FleetApiError $error) {
            self::assertSame(503, $error->httpStatus);
        }

        self::assertCount(3, $this->calls);
    }

    public function testAVehicleErrorIsRetried(): void
    {
        $client = $this->client([
            new MockResponse('{}', ['http_code' => 540]),
            new MockResponse(FleetApiResponses::vehicle('online')),
        ]);

        $client->vehicleState($this->app(), new Vin(FleetApiResponses::VIN));

        self::assertCount(2, $this->calls);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusesSentOnce(): iterable
    {
        foreach ([401, 403, 404, 408, 412, 421, 429] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('statusesSentOnce')]
    public function testOtherErrorsAreSentOnceAndNeverRetried(int $status): void
    {
        $client = $this->client([
            new MockResponse(FleetApiResponses::vehicleNotOnline(), ['http_code' => $status]),
            new MockResponse(FleetApiResponses::vehicleData(1000)),
        ]);

        try {
            $client->vehicleData($this->app(), new Vin(FleetApiResponses::VIN));
            self::fail('Expected a FleetApiError.');
        } catch (FleetApiError $error) {
            self::assertSame($status, $error->httpStatus);
            self::assertSame(408 === $status, $error->isVehicleNotOnline());
        }

        self::assertCount(1, $this->calls);
    }

    public function testTheAccessTokenNeverAppearsInAnErrorMessage(): void
    {
        $client = $this->client([new MockResponse('{"error":"bad token ' . self::ACCESS_TOKEN . '"}', ['http_code' => 403])]);

        try {
            $client->vehicleState($this->app(), new Vin(FleetApiResponses::VIN));
            self::fail('Expected a FleetApiError.');
        } catch (FleetApiError $error) {
            self::assertStringNotContainsString(self::ACCESS_TOKEN, $error->getMessage());
            self::assertStringContainsString('***', $error->getMessage());
        }
    }

    public function testNotConnectedMakesNoHttpCall(): void
    {
        $client = $this->client([new MockResponse(FleetApiResponses::vehicleList())], connected: false);

        try {
            $client->listVehicles($this->app());
            self::fail('Expected TeslaNotConnected.');
        } catch (TeslaNotConnected) {
            self::assertSame([], $this->calls);
        }
    }

    public function testAMissingStoredConnectionIsNotConnected(): void
    {
        $client = $this->client([new MockResponse(FleetApiResponses::vehicleList())], stored: false);

        $this->expectException(TeslaNotConnected::class);

        $client->listVehicles($this->app());
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, bool $connected = true, bool $stored = true): TeslaFleetClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->calls[] = [$method, $url, $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });
        $tokens = new class($connected) implements TokenProvider {
            public function __construct(private bool $connected)
            {
            }

            public function accessToken(TeslaApp $app, bool $force = false): string
            {
                return $this->connected ? TeslaFleetClientTest::ACCESS_TOKEN : throw TeslaNotConnected::never();
            }
        };

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($stored ? $this->token() : null);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        return new TeslaFleetClient($http, $tokens, $entityManager, new MockClock(self::NOW), 0);
    }

    private function app(): TeslaApp
    {
        return new TeslaApp(new User('driver@example.test', 'not-a-real-hash', CarbonImmutable::parse(self::NOW)), 'public', 'v1:private', CarbonImmutable::parse(self::NOW));
    }

    private function token(): OAuthToken
    {
        $now = CarbonImmutable::parse(self::NOW);

        return new OAuthToken($this->app(), 'v1:a', 'v1:r', $now->addHours(2), 'openid', ConnectionMethod::OAuth, FleetRegion::EuropeMiddleEastAfrica->baseUrl(), $now);
    }
}

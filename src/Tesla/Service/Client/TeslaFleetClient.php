<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Client;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Polaris\Shared\Domain\Distance;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Domain\VehicleDataRedactor;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Dto\OdometerReading;
use Polaris\Tesla\Service\Dto\VehicleSummary;
use Polaris\Tesla\Service\Enum\FleetApiOperation;
use Polaris\Tesla\Service\Enum\VehicleState;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TokenProvider;
use Polaris\Tesla\Service\VehicleClient;
use Polaris\Vehicle\Domain\Vin;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The vehicle reads of the Fleet API: GET /api/1/vehicles, /vehicles/{vin} and /vehicles/{vin}/vehicle_data.
 *
 * The access token comes from the {@see TokenProvider} and the base URL from the stored connection, so the
 * caller passes the app only. It never wakes the car, asks for no `endpoints` and so never for `location_data`.
 *
 * Unlike the OAuth client, this one retries: a read changes nothing, so a repeated one is safe. It retries
 * 500, 502, 503, 504 and 540 at most twice, with an exponential delay (Retry-After is honoured by the
 * Symfony client). It never retries 408, which means the car is not online and is billed each time, nor
 * 401, 403, 404, 412, 421 or 429. The OAuth and partner clients keep the plain HTTP client: a refresh token is
 * single use and must never be sent twice.
 *
 * The shape of the answers is confirmed by real EU ones: the vehicle list, the vehicle state and a live
 * `vehicle_data`, whose `response.vehicle_state` carries `odometer` (a float) and `timestamp` (epoch
 * milliseconds). The odometer has no unit field: the API always gives miles, never kilometres.
 */
final readonly class TeslaFleetClient implements VehicleClient
{
    use SendsFleetApiRequests;

    private const array RETRY_STATUS_CODES = [
        Response::HTTP_INTERNAL_SERVER_ERROR,
        Response::HTTP_BAD_GATEWAY,
        Response::HTTP_SERVICE_UNAVAILABLE,
        Response::HTTP_GATEWAY_TIMEOUT,
        FleetApiError::HTTP_DEVICE_UNEXPECTED_RESPONSE,
    ];
    private const int MAX_RETRIES = 2;
    private const int MAX_RETRY_DELAY_MS = 4000;

    private HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $client,

        private TokenProvider $tokens,

        private EntityManagerInterface $entityManager,

        private ClockInterface $clock,

        #[Autowire(param: 'polaris.tesla.retry_delay_ms')]
        int $retryDelayMs = 500,
    ) {
        $this->client = new RetryableHttpClient(
            $client,
            new GenericRetryStrategy(self::RETRY_STATUS_CODES, $retryDelayMs, 2.0, self::MAX_RETRY_DELAY_MS),
            self::MAX_RETRIES,
        );
    }

    public function listVehicles(TeslaApp $app): array
    {
        $region = null;
        $data = $this->get(FleetApiOperation::ListVehicles, $app, '/api/1/vehicles', $region);
        $items = $data['response'] ?? null;

        /* @var FleetRegion $region */
        if (!\is_array($items)) {
            throw FleetApiError::unexpected(FleetApiOperation::ListVehicles, $region, 'the answer has no vehicle list');
        }

        $vehicles = [];

        foreach ($items as $item) {
            $vehicles[] = $this->summary($item, $region);
        }

        return $vehicles;
    }

    public function vehicleState(TeslaApp $app, Vin $vin): VehicleState
    {
        $region = null;
        $data = $this->get(FleetApiOperation::VehicleState, $app, $this->vehiclePath($vin), $region);
        $response = $data['response'] ?? null;

        /* @var FleetRegion $region */
        if (!\is_array($response)) {
            throw FleetApiError::unexpected(FleetApiOperation::VehicleState, $region, 'the answer has no vehicle');
        }

        return $this->state($response['state'] ?? null, FleetApiOperation::VehicleState, $region);
    }

    public function vehicleData(TeslaApp $app, Vin $vin): OdometerReading
    {
        $region = null;
        $data = $this->get(FleetApiOperation::VehicleData, $app, $this->vehiclePath($vin) . '/vehicle_data', $region);
        $response = $data['response'] ?? null;
        $state = \is_array($response) ? ($response['vehicle_state'] ?? null) : null;
        $miles = \is_array($state) ? ($state['odometer'] ?? null) : null;

        /* @var FleetRegion $region */
        if (!\is_int($miles) && !\is_float($miles)) {
            throw FleetApiError::unexpected(FleetApiOperation::VehicleData, $region, 'the answer has no vehicle_state.odometer');
        }

        try {
            $odometer = Distance::fromMiles($miles);
        } catch (\InvalidArgumentException $invalid) {
            throw FleetApiError::unexpected(FleetApiOperation::VehicleData, $region, 'the odometer is not a valid distance');
        }

        $timestamp = $state['timestamp'] ?? null;
        $readAt = \is_int($timestamp) && $timestamp > 0
            ? CarbonImmutable::createFromTimestampMsUTC($timestamp)
            : CarbonImmutable::instance($this->clock->now())->utc();

        return new OdometerReading($odometer, $readAt, VehicleDataRedactor::withoutLocation($response));
    }

    /**
     * @param-out  FleetRegion  $region
     *
     * @return array<mixed>
     *
     * @throws TeslaNotConnected
     * @throws FleetApiError
     */
    private function get(FleetApiOperation $operation, TeslaApp $app, string $path, ?FleetRegion &$region): array
    {
        $accessToken = $this->tokens->accessToken($app);
        $token = $this->entityManager->getRepository(OAuthToken::class)->findOneBy(['teslaApp' => $app]);

        if (!$token instanceof OAuthToken) {
            throw TeslaNotConnected::never();
        }

        $baseUrl = rtrim($token->getFleetApiBaseUrl(), '/');
        $region = FleetRegion::fromBaseUrl($baseUrl) ?? $this->fallbackRegion($app);

        return $this->send(
            $operation,
            $region,
            'GET',
            $baseUrl . $path,
            ['auth_bearer' => $accessToken],
            [$accessToken],
        );
    }

    /**
     * Names the region in errors when the stored base URL is not one of ours: the request still goes to it.
     */
    private function fallbackRegion(TeslaApp $app): FleetRegion
    {
        foreach ($app->getEnabledRegions() as $region) {
            if ($region->isSupported()) {
                return $region;
            }
        }

        return FleetRegion::EuropeMiddleEastAfrica;
    }

    private function vehiclePath(Vin $vin): string
    {
        return '/api/1/vehicles/' . rawurlencode($vin->value());
    }

    private function state(mixed $value, FleetApiOperation $operation, FleetRegion $region): VehicleState
    {
        $state = \is_string($value) ? VehicleState::tryFrom($value) : null;

        if (null === $state) {
            throw FleetApiError::unexpected($operation, $region, \sprintf('unknown vehicle state "%s"', \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return $state;
    }

    private function summary(mixed $item, FleetRegion $region): VehicleSummary
    {
        if (!\is_array($item)) {
            throw FleetApiError::unexpected(FleetApiOperation::ListVehicles, $region, 'a vehicle entry is not an object');
        }

        try {
            $vin = new Vin(\is_string($item['vin'] ?? null) ? $item['vin'] : '');
        } catch (\InvalidArgumentException $invalid) {
            throw FleetApiError::unexpected(FleetApiOperation::ListVehicles, $region, $invalid->getMessage());
        }

        $name = $item['display_name'] ?? null;

        return new VehicleSummary(
            $vin,
            \is_string($name) && '' !== $name ? $name : null,
            $this->state($item['state'] ?? null, FleetApiOperation::ListVehicles, $region),
        );
    }
}

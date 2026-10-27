<?php

declare(strict_types=1);

namespace Polaris\Tesla\Service\Client;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Enum\FleetApiOperation;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TokenProvider;
use Polaris\Tesla\Service\VehicleCommandClient;
use Polaris\Vehicle\Domain\Vin;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST /api/1/vehicles/{vin}/wake_up with the bearer token of the user. It uses the plain HTTP client, not
 * the retrying one of {@see TeslaFleetClient}: a wake is billed, so a repeated one would be paid twice.
 */
final readonly class TeslaWakeClient implements VehicleCommandClient
{
    use SendsFleetApiRequests;

    public function __construct(
        private HttpClientInterface $client,
        private TokenProvider $tokens,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function wakeUp(TeslaApp $app, Vin $vin): void
    {
        $accessToken = $this->tokens->accessToken($app);
        $token = $this->entityManager->getRepository(OAuthToken::class)->findOneBy(['teslaApp' => $app]);

        if (!$token instanceof OAuthToken) {
            throw TeslaNotConnected::never();
        }

        $baseUrl = rtrim($token->getFleetApiBaseUrl(), '/');

        $this->send(
            FleetApiOperation::WakeUp,
            FleetRegion::fromBaseUrl($baseUrl) ?? FleetRegion::EuropeMiddleEastAfrica,
            'POST',
            $baseUrl . '/api/1/vehicles/' . rawurlencode($vin->value()) . '/wake_up',
            // Tesla refuses a POST that is not JSON (406 not_a_JSON_request), even one with nothing to say.
            ['auth_bearer' => $accessToken, 'json' => new \stdClass()],
            [$accessToken],
        );
    }
}

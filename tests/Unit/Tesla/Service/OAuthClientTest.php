<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Service\FleetApiError;
use Polaris\Tesla\Service\FleetApiOperation;
use Polaris\Tesla\Service\OAuthClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(OAuthClient::class)]
#[CoversClass(FleetApiError::class)]
final class OAuthClientTest extends TestCase
{
    private const string SECRET = 'fake-client-secret';
    private const string CODE = 'fake-authorization-code';
    private const string REFRESH = 'fake-refresh-token';
    private const string REDIRECT = 'https://polaris.example.com/tesla/callback';
    private const string TOKENS = '{"access_token":"fake-access","refresh_token":"fake-new-refresh","expires_in":28800,"token_type":"Bearer","scope":"openid offline_access vehicle_device_data"}';

    public function testTheAuthorizationUrlCarriesTheDocumentedParameters(): void
    {
        $url = new OAuthClient(new MockHttpClient([]))->authorizationUrl('client-id', self::REDIRECT, 'fake-state');

        self::assertStringStartsWith('https://auth.tesla.com/oauth2/v3/authorize?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame(
            [
                'response_type' => 'code',
                'client_id' => 'client-id',
                'redirect_uri' => self::REDIRECT,
                'scope' => 'openid offline_access vehicle_device_data',
                'state' => 'fake-state',
            ],
            $query,
        );
    }

    public function testTheCodeExchangeSendsTheAudienceTheRedirectUriAndTheSecret(): void
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body']];

            return new MockResponse(self::TOKENS);
        });

        $tokens = new OAuthClient($client)->exchangeCode(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::SECRET, self::CODE, self::REDIRECT);

        self::assertSame('fake-access', $tokens->accessToken);
        self::assertSame('fake-new-refresh', $tokens->refreshToken);
        self::assertSame(28800, $tokens->expiresIn);
        self::assertSame('openid offline_access vehicle_device_data', $tokens->scopes);
        self::assertNotNull($seen);
        [$method, $url, $body] = $seen;
        self::assertIsString($body);
        self::assertSame('POST', $method);
        self::assertSame('https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token', $url);
        parse_str($body, $fields);
        self::assertSame(
            [
                'grant_type' => 'authorization_code',
                'client_id' => 'client-id',
                'client_secret' => self::SECRET,
                'code' => self::CODE,
                'audience' => 'https://fleet-api.prd.eu.vn.cloud.tesla.com',
                'redirect_uri' => self::REDIRECT,
            ],
            $fields,
        );
    }

    public function testTheRefreshSendsNoClientSecret(): void
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$url, $options['body']];

            return new MockResponse(self::TOKENS);
        });

        new OAuthClient($client)->refresh(FleetRegion::NorthAmericaAsiaPacific, 'client-id', self::REFRESH);

        self::assertNotNull($seen);
        [$url, $body] = $seen;
        self::assertIsString($body);
        self::assertSame('https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token', $url);
        parse_str($body, $fields);
        self::assertSame(['grant_type' => 'refresh_token', 'client_id' => 'client-id', 'refresh_token' => self::REFRESH], $fields);
    }

    public function testTheScopesDefaultToTheRequestedOnesWhenTeslaOmitsThem(): void
    {
        $client = new MockHttpClient(new MockResponse('{"access_token":"a","refresh_token":"r","expires_in":60}'));

        $tokens = new OAuthClient($client)->refresh(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::REFRESH);

        self::assertSame('openid offline_access vehicle_device_data', $tokens->scopes);
    }

    public function testARefusedRefreshTokenIsRecognisedWhateverTheTeslaWording(): void
    {
        foreach ([
            [401, '{"error":"login_required","error_description":"login required"}'],
            [400, '{"error":"invalid_grant"}'],
            [401, ''],
        ] as [$status, $body]) {
            $client = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));

            try {
                new OAuthClient($client)->refresh(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::REFRESH);
                self::fail('The refresh should have failed.');
            } catch (FleetApiError $error) {
                self::assertTrue($error->refreshTokenRejected(), $body);
                self::assertSame(FleetApiOperation::Refresh, $error->operation);
            }
        }
    }

    public function testAnOutageIsNotARefusedToken(): void
    {
        foreach ([
            new MockResponse('{"error":"server_error"}', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 429]),
        ] as $response) {
            try {
                new OAuthClient(new MockHttpClient($response))->refresh(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::REFRESH);
                self::fail('The refresh should have failed.');
            } catch (FleetApiError $error) {
                self::assertFalse($error->refreshTokenRejected());
            }
        }
    }

    public function testATransportFailureIsNotARefusedToken(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(info: ['error' => 'connection refused ' . self::REFRESH]));

        try {
            new OAuthClient($client)->refresh(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::REFRESH);
            self::fail('The refresh should have failed.');
        } catch (FleetApiError $error) {
            self::assertFalse($error->refreshTokenRejected());
            self::assertNull($error->httpStatus);
            self::assertStringNotContainsString(self::REFRESH, $error->getMessage());
        }
    }

    public function testNoSecretReachesAnErrorMessage(): void
    {
        $body = (string) json_encode(['error' => 'invalid_request', 'error_description' => 'bad ' . self::CODE . ' ' . self::SECRET]);
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => 400]));

        try {
            new OAuthClient($client)->exchangeCode(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::SECRET, self::CODE, self::REDIRECT);
            self::fail('The exchange should have failed.');
        } catch (FleetApiError $error) {
            self::assertStringNotContainsString(self::CODE, $error->getMessage());
            self::assertStringNotContainsString(self::SECRET, $error->getMessage());
            self::assertStringContainsString('invalid_request', $error->getMessage());
        }
    }

    public function testAnIncompleteTokenAnswerIsRefused(): void
    {
        foreach (['{}', '{"access_token":"a"}', '{"access_token":"a","refresh_token":"r"}', '{"access_token":"a","refresh_token":"r","expires_in":"soon"}'] as $body) {
            try {
                new OAuthClient(new MockHttpClient(new MockResponse($body)))->refresh(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::REFRESH);
                self::fail('The answer should have been refused: ' . $body);
            } catch (FleetApiError $error) {
                self::assertFalse($error->refreshTokenRejected());
            }
        }
    }

    public function testTheUserRegionIsReadWithTheBearerToken(): void
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['headers']];

            return new MockResponse('{"response":{"region":"eu","fleet_api_base_url":"https://fleet-api.prd.eu.vn.cloud.tesla.com"}}');
        });

        $region = new OAuthClient($client)->userRegion(FleetRegion::NorthAmericaAsiaPacific, 'fake-access');

        self::assertSame(FleetRegion::EuropeMiddleEastAfrica, $region->region);
        self::assertSame('https://fleet-api.prd.eu.vn.cloud.tesla.com', $region->baseUrl);
        self::assertNotNull($seen);
        [$method, $url, $headers] = $seen;
        self::assertIsArray($headers);
        self::assertSame('GET', $method);
        self::assertSame('https://fleet-api.prd.na.vn.cloud.tesla.com/api/1/users/region', $url);
        self::assertContains('Authorization: Bearer fake-access', $headers);
    }

    public function testAnUnknownRegionAnswerIsRefused(): void
    {
        $this->expectException(FleetApiError::class);

        new OAuthClient(new MockHttpClient(new MockResponse('{"response":{"region":"mars"}}')))
            ->userRegion(FleetRegion::EuropeMiddleEastAfrica, 'fake-access');
    }
}

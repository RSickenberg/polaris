<?php

declare(strict_types=1);

namespace Polaris\Tests\Unit\Tesla\Service\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Service\Client\FleetApiClient;
use Polaris\Tesla\Service\Enum\FleetApiOperation;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FleetApiClient::class)]
#[CoversClass(FleetApiError::class)]
final class FleetApiClientTest extends TestCase
{
    private const string SECRET = 'plain-client-secret';

    public function testThePartnerTokenIsAskedWithClientCredentialsAndTheRegionAudience(): void
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body'], $options['headers']];

            return new MockResponse('{"access_token":"partner-token","token_type":"Bearer"}');
        });

        $token = new FleetApiClient($client)->partnerToken(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::SECRET);

        self::assertSame('partner-token', $token);
        self::assertNotNull($seen);
        [$method, $url, $body, $headers] = $seen;
        self::assertIsString($body);
        self::assertIsArray($headers);
        self::assertSame('POST', $method);
        self::assertSame('https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token', $url);
        parse_str($body, $fields);
        self::assertSame(
            [
                'grant_type' => 'client_credentials',
                'client_id' => 'client-id',
                'client_secret' => self::SECRET,
                'scope' => 'openid vehicle_device_data',
                'audience' => 'https://fleet-api.prd.eu.vn.cloud.tesla.com',
            ],
            $fields,
        );
        self::assertContains('Content-Type: application/x-www-form-urlencoded', $headers);
    }

    public function testTheDomainIsRegisteredWithTheBearerToken(): void
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body'], $options['headers']];

            return new MockResponse('{"response":{}}');
        });

        new FleetApiClient($client)->registerPartner(FleetRegion::NorthAmericaAsiaPacific, 'partner-token', AppDomain::fromString('polaris.example.com'));

        self::assertNotNull($seen);
        [$method, $url, $body, $headers] = $seen;
        self::assertIsArray($headers);
        self::assertSame('POST', $method);
        self::assertSame('https://fleet-api.prd.na.vn.cloud.tesla.com/api/1/partner_accounts', $url);
        self::assertSame('{"domain":"polaris.example.com"}', $body);
        self::assertContains('Authorization: Bearer partner-token', $headers);
    }

    public function testTheRegisteredKeyIsReadBackForTheDomain(): void
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen = [$method, $url];

            return new MockResponse('{"response":{"public_key":"04abcd"}}');
        });

        $key = new FleetApiClient($client)->registeredPublicKey(FleetRegion::EuropeMiddleEastAfrica, 'partner-token', AppDomain::fromString('polaris.example.com'));

        self::assertSame('04abcd', $key);
        self::assertSame(
            ['GET', 'https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/partner_accounts/public_key?domain=polaris.example.com'],
            $seen,
        );
    }

    public function testChinaHasNoTokenHostAndNothingIsSent(): void
    {
        $client = new MockHttpClient(static fn (): never => self::fail('No request expected.'));

        $this->expectException(\LogicException::class);

        new FleetApiClient($client)->partnerToken(FleetRegion::China, 'client-id', self::SECRET);
    }

    #[DataProvider('errorResponses')]
    public function testTeslaErrorsBecomeClearMessages(int $status, string $body, string $expectedHint, string $expectedDetail): void
    {
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));

        try {
            new FleetApiClient($client)->registerPartner(FleetRegion::EuropeMiddleEastAfrica, 'partner-token', AppDomain::fromString('polaris.example.com'));
            self::fail('A FleetApiError was expected.');
        } catch (FleetApiError $error) {
            self::assertSame(FleetApiOperation::Register, $error->operation);
            self::assertSame(FleetRegion::EuropeMiddleEastAfrica, $error->region);
            self::assertSame($status, $error->httpStatus);
            self::assertStringContainsString('Registering the domain in region eu failed', $error->getMessage());
            self::assertStringContainsString($expectedHint, $error->getMessage());
            self::assertStringContainsString(\sprintf('HTTP %d', $status), $error->getMessage());
            self::assertStringContainsString($expectedDetail, $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function errorResponses(): iterable
    {
        yield '400 oauth error' => [400, '{"error":"invalid_request","error_description":"Missing domain"}', 'rejected the request', 'invalid_request, Missing domain'];
        yield '401' => [401, '{"error":"invalid_client"}', 'did not accept the credentials', 'invalid_client'];
        yield '403' => [403, '{"error":"forbidden","message":"Domain not allowed"}', 'allowed origin', 'Domain not allowed'];
        yield '404' => [404, '', 'does not know this endpoint', ''];
        yield '422' => [422, '{"error_message":"key not found"}', 'could not validate', 'key not found'];
        yield '429' => [429, '{"error":"rate_limited"}', 'rate-limiting', 'rate_limited'];
        yield '500 html body' => [500, '<html>Bad gateway</html>', 'failed to answer', '<html>Bad gateway</html>'];
        yield '302' => [302, '', 'unexpected status', ''];
    }

    public function testALongBodyIsTruncated(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', 5000), ['http_code' => 500]));

        try {
            new FleetApiClient($client)->registerPartner(FleetRegion::EuropeMiddleEastAfrica, 'partner-token', AppDomain::fromString('polaris.example.com'));
            self::fail('A FleetApiError was expected.');
        } catch (FleetApiError $error) {
            self::assertLessThan(400, \strlen($error->getMessage()));
            self::assertStringContainsString('...', $error->getMessage());
        }
    }

    public function testTheClientSecretNeverAppearsInAnErrorMessage(): void
    {
        $client = new MockHttpClient(new MockResponse(
            \sprintf('{"error":"invalid_client","error_description":"Bad secret %s"}', self::SECRET),
            ['http_code' => 401],
        ));

        try {
            new FleetApiClient($client)->partnerToken(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::SECRET);
            self::fail('A FleetApiError was expected.');
        } catch (FleetApiError $error) {
            self::assertStringNotContainsString(self::SECRET, $error->getMessage());
            self::assertStringContainsString('***', $error->getMessage());
            self::assertSame(FleetApiOperation::Token, $error->operation);
        }
    }

    public function testATransportFailureNamesTeslaAsUnreachable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host']));

        try {
            new FleetApiClient($client)->partnerToken(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::SECRET);
            self::fail('A FleetApiError was expected.');
        } catch (FleetApiError $error) {
            self::assertNull($error->httpStatus);
            self::assertStringContainsString('could not be reached', $error->getMessage());
            self::assertStringContainsString('Could not resolve host', $error->getMessage());
        }
    }

    public function testAnAnswerWithoutATokenIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('{"token_type":"Bearer"}'));

        $this->expectException(FleetApiError::class);
        $this->expectExceptionMessage('no access token');

        new FleetApiClient($client)->partnerToken(FleetRegion::EuropeMiddleEastAfrica, 'client-id', self::SECRET);
    }

    public function testAnAnswerWithoutAPublicKeyIsRejected(): void
    {
        $client = new MockHttpClient(new MockResponse('{"response":{}}'));

        $this->expectException(FleetApiError::class);
        $this->expectExceptionMessage('no public key');

        new FleetApiClient($client)->registeredPublicKey(FleetRegion::EuropeMiddleEastAfrica, 'partner-token', AppDomain::fromString('polaris.example.com'));
    }
}

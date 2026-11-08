<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Tesla\Service\Enum\NoReadingReason;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TeslaOdometerReader;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Polaris\Vehicle\Domain\Vin;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(TeslaOdometerReader::class)]
final class TeslaOdometerReaderTest extends KernelTestCase
{
    use SignsIn;
    use TeslaFixtures;

    public function testAnOnlineCarIsReadLiveThroughTheContainer(): void
    {
        self::bootKernel();
        $app = self::createConfiguredApp(self::createUser());
        self::createToken($app, CarbonImmutable::now()->addHours(2));
        $urls = [];
        $respond = static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $method . ' ' . $url;

            $answer = str_ends_with($url, '/vehicle_data')
                ? FleetApiResponses::vehicleData(100)
                : FleetApiResponses::vehicle('online');

            return new MockResponse($answer);
        };
        self::getContainer()->set('http_client', new MockHttpClient($respond));

        $result = self::getContainer()->get(TeslaOdometerReader::class)->read($app, new Vin(FleetApiResponses::VIN));

        self::assertSame(160_934, $result->reading?->odometer->metres());
        self::assertSame(
            [
                'GET ' . FleetApiResponses::EU_VEHICLES . '/' . FleetApiResponses::VIN,
                'GET ' . FleetApiResponses::EU_VEHICLES . '/' . FleetApiResponses::VIN . '/vehicle_data',
            ],
            $urls,
        );
    }

    public function testAnAsleepCarGivesNoReadingAndOnlyTheStateIsAsked(): void
    {
        self::bootKernel();
        $app = self::createConfiguredApp(self::createUser());
        self::createToken($app, CarbonImmutable::now()->addHours(2));
        $client = new MockHttpClient(new MockResponse(FleetApiResponses::vehicle('asleep')));
        self::getContainer()->set('http_client', $client);

        $result = self::getContainer()->get(TeslaOdometerReader::class)->read($app, new Vin(FleetApiResponses::VIN));

        self::assertSame(NoReadingReason::Asleep, $result->reason);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testWithoutAConnectionNothingIsSent(): void
    {
        self::bootKernel();
        $app = self::createConfiguredApp(self::createUser());
        $client = new MockHttpClient([]);
        self::getContainer()->set('http_client', $client);

        try {
            self::getContainer()->get(TeslaOdometerReader::class)->read($app, new Vin(FleetApiResponses::VIN));
            self::fail('Expected TeslaNotConnected.');
        } catch (TeslaNotConnected) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }
}

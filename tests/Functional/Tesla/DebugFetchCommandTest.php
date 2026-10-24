<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Tesla\Command\DebugFetchCommand;
use Polaris\Tests\Support\FleetApiResponses;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(DebugFetchCommand::class)]
final class DebugFetchCommandTest extends KernelTestCase
{
    use SignsIn;
    use TeslaFixtures;

    public function testTheListIsPrintedWithTheIdsAndTheVinRemoved(): void
    {
        $calls = [];
        $tester = $this->connectedTester($calls, FleetApiResponses::vehicleList());

        self::assertSame(Command::SUCCESS, $tester->execute(['what' => 'list']));

        $display = $tester->getDisplay();
        self::assertStringContainsString('HTTP 200 (region eu)', $display);
        self::assertStringContainsString('"state": "offline"', $display);
        self::assertStringContainsString('Lucia', $display);
        self::assertStringNotContainsString(FleetApiResponses::VIN, $display);
        self::assertStringNotContainsString('"id": 1', $display);
        self::assertSame(['GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles'], $calls);
    }

    public function testCarNamesAreRemovedOnRequest(): void
    {
        $calls = [];
        $tester = $this->connectedTester($calls, FleetApiResponses::vehicleList());

        $tester->execute(['what' => 'list', '--redact-names' => true]);

        self::assertStringNotContainsString('Lucia', $tester->getDisplay());
        self::assertStringNotContainsString('Raven', $tester->getDisplay());
    }

    public function testAnErrorAnswerIsPrintedWithItsStatus(): void
    {
        $calls = [];
        $tester = $this->connectedTester($calls, FleetApiResponses::vehicleNotOnline(), 408);

        self::assertSame(Command::SUCCESS, $tester->execute(['what' => 'data', 'vin' => FleetApiResponses::VIN]));

        self::assertStringContainsString('HTTP 408', $tester->getDisplay());
        self::assertStringContainsString('vehicle is offline or asleep', $tester->getDisplay());
        self::assertSame(['GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . FleetApiResponses::VIN . '/vehicle_data'], $calls);
    }

    public function testOnlyTheFourReadsExistAndNeverAWakeUp(): void
    {
        $calls = [];
        $tester = $this->connectedTester($calls, '{}');

        self::assertSame(Command::FAILURE, $tester->execute(['what' => 'wake_up', 'vin' => FleetApiResponses::VIN]));
        self::assertSame(Command::FAILURE, $tester->execute(['what' => 'state']));
        self::assertSame([], $calls);
    }

    public function testTheCachedReadUsesItsOwnPath(): void
    {
        $calls = [];
        $tester = $this->connectedTester($calls, '{"response":{}}');

        $tester->execute(['what' => 'cached', 'vin' => FleetApiResponses::VIN]);

        self::assertSame(['GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . FleetApiResponses::VIN . '/vehicle_data_cached'], $calls);
    }

    public function testFailsWithoutAConnection(): void
    {
        $kernel = self::bootKernel();
        self::createConfiguredApp(self::createUser());
        $client = new MockHttpClient([]);
        self::getContainer()->set('http_client', $client);

        $tester = new CommandTester(new Application($kernel)->find('polaris:tesla:debug:fetch'));

        self::assertSame(Command::FAILURE, $tester->execute(['what' => 'list']));
        self::assertSame(0, $client->getRequestsCount());
    }

    /**
     * @param list<string> $calls the method and URL of every HTTP call
     */
    private function connectedTester(array &$calls, string $body, int $status = 200): CommandTester
    {
        $kernel = self::bootKernel();
        self::createToken(self::createConfiguredApp(self::createUser()), CarbonImmutable::now()->addHours(2));
        self::getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url) use (&$calls, $body, $status): MockResponse {
            $calls[] = $method . ' ' . $url;

            return new MockResponse($body, ['http_code' => $status]);
        }));

        return new CommandTester(new Application($kernel)->find('polaris:tesla:debug:fetch'));
    }
}

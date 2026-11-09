<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Dashboard;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Dashboard\Controller\DebugController;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\DistanceUnit;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(DebugController::class)]
final class DebugControllerTest extends WebTestCase
{
    use SignsIn;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        putenv('SENTRY_RELEASE');
        unset($_ENV['SENTRY_RELEASE'], $_SERVER['SENTRY_RELEASE']);
        parent::tearDown();
    }

    public function testItShowsTheBuildAndTheRawRowsOfTheUser(): void
    {
        $user = self::signIn($this->client);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $vehicle = new Vehicle(new Vin('5YJ3E1EA7KF317000'), 'Debug car', $user);
        $entityManager->persist($vehicle);
        $entityManager->persist(OdometerReading::fromTesla(
            $vehicle,
            CarbonImmutable::parse('2026-03-01 08:00:00', 'UTC'),
            '12345.5',
            DistanceUnit::Mile,
        ));
        $entityManager->flush();

        $this->client->request('GET', '/debug');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h1', 'Debug data');
        self::assertSelectorTextContains('[data-runtime=version]', 'dev');
        self::assertSelectorTextContains('[data-debug-table=vehicle]', 'Debug car');
        self::assertSelectorTextContains('[data-debug-table=odometer_reading]', '2026-03-01 08:00:00 UTC');
        self::assertSelectorTextContains('[data-debug-table=odometer_reading]', '1 of 1 rows');
    }

    public function testItNeverShowsThePasswordHash(): void
    {
        self::signIn($this->client);

        $this->client->request('GET', '/debug');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('not-a-real-hash', (string) $this->client->getResponse()->getContent());
    }

    public function testTheSidebarLinksToItOnAPreviewBuild(): void
    {
        self::signIn($this->client);

        $this->client->request('GET', '/');

        self::assertSelectorExists('[data-slot=sidebar] a[href="/debug"]');
    }

    public function testAReleaseHasNoSuchPageAndNoLink(): void
    {
        putenv('SENTRY_RELEASE=0.4.0');
        $_ENV['SENTRY_RELEASE'] = $_SERVER['SENTRY_RELEASE'] = '0.4.0';
        self::signIn($this->client);

        $this->client->request('GET', '/debug');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('[data-slot=sidebar] a[href="/debug"]');
    }
}

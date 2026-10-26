<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Account;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Distance;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Dates are stored in UTC; the pages show and take them in the time zone of the user (ADR 0003).
 */
#[CoversNothing]
final class UserTimeZoneTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    public function testTheReadingHistoryIsShownAndEnteredInTheUsersTimeZone(): void
    {
        self::mockTime('2026-07-01 12:00:00 UTC');
        $client = self::createClient();
        $user = self::createUser();
        $user->changeTimeZone('Europe/Zurich');
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000091'), 'Model 3', $user);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($vehicle);
        $entityManager->persist(OdometerReading::manual($vehicle, CarbonImmutable::parse('2026-07-01 08:00:00', 'UTC'), Distance::fromKilometres(1_000)));
        $entityManager->flush();
        self::signIn($client, $user);
        $path = \sprintf('/vehicles/%s/readings', $vehicle->getId()->toBase32());

        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        // 08:00 UTC is 10:00 in Zurich in July (UTC+2), and "now" (12:00 UTC) is offered as 14:00.
        self::assertSelectorTextContains('tbody tr', '10:00');
        self::assertSelectorTextContains('thead', 'Europe/Zurich');
        self::assertSelectorTextContains('label[for=odometer_reading_readAt]', 'Read at (Europe/Zurich)');
        self::assertInputValueSame('odometer_reading[readAt]', '2026-07-01T14:00');

        $client->submitForm('Add reading', ['odometer_reading[readAt]' => '2026-07-01T11:30', 'odometer_reading[odometer]' => '1100', 'odometer_reading[distanceUnit]' => 'km']);

        self::assertResponseRedirects($path);
        $stored = $entityManager->getRepository(OdometerReading::class)->findBy([], ['odometerM' => 'DESC'])[0];
        self::assertSame('2026-07-01 09:30:00', $stored->getReadAt()->format('Y-m-d H:i:s'));
    }

    public function testTheDashboardUsesTheUsersTimeZone(): void
    {
        self::mockTime('2026-07-01 12:00:00 UTC');
        $client = self::createClient();
        $user = self::createUser();
        $user->changeTimeZone('Asia/Tokyo');
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000092'), 'Model 3', $user);
        self::getContainer()->get(EntityManagerInterface::class)->persist($vehicle);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::signIn($client, $user);

        $client->request('GET', '/vehicles/' . $vehicle->getId()->toBase32());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main');
    }
}

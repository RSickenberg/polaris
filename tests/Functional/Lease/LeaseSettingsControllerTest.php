<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Lease\Controller\LeaseSettingsController;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Form\LeaseContractType;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(LeaseSettingsController::class)]
#[CoversClass(LeaseContractType::class)]
final class LeaseSettingsControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const array VALID = [
        'lease_contract[startDate]' => '2026-01-01',
        'lease_contract[endDate]' => '2027-01-01',
        'lease_contract[endDateConvention]' => 'exclusive',
        'lease_contract[distanceUnit]' => 'km',
        'lease_contract[allowanceBasis]' => 'per_year',
        'lease_contract[allowance]' => '15000',
        'lease_contract[startOdometer]' => '12',
        'lease_contract[excessCostPerKm]' => '0.12',
        'lease_contract[currency]' => 'CHF',
        'lease_contract[tolerancePercent]' => '2.5',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::mockTime('2026-07-02 12:00:00 UTC');
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000041'), 'Model 3');
        $this->entityManager->persist($this->vehicle);
        $this->entityManager->flush();
    }

    public function testEmptySettingsShowTheFormAndNoProjection(): void
    {
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Lease terms, Model 3');
        self::assertSelectorExists('form input[name="lease_contract[allowance]"]');
        self::assertSelectorTextContains('body', 'Save the lease terms to see the projection.');
    }

    public function testUnknownVehicleIsNotFound(): void
    {
        $this->client->request('GET', '/vehicles/01JZZZZZZZZZZZZZZZZZZZZZZZ/lease');

        self::assertResponseStatusCodeSame(404);
    }

    public function testValidSubmissionStoresTheTermsInTheStorageUnits(): void
    {
        $this->submit([]);

        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=status]', 'The lease terms were saved.');
        self::assertSelectorTextContains('body', 'No reading after the lease start yet.');

        $contract = $this->contract();
        self::assertSame('2026-01-01', $contract->getTerm()->start()->toDateString());
        self::assertSame('2027-01-01', $contract->getTerm()->end()->toDateString());
        self::assertSame(15_000_000, $contract->getAllowance()->total()->metres()); // 12 months
        self::assertSame(12_000, $contract->getStartOdometer()->metres());
        self::assertSame(12, $contract->getExcessCostPerKm()->minorAmount());
        self::assertSame(Currency::CHF, $contract->getExcessCostPerKm()->currency());
        self::assertSame(250, $contract->getTolerance()->basisPoints());
    }

    public function testMilesAndATotalAllowanceAreConverted(): void
    {
        $this->submit(['lease_contract[distanceUnit]' => 'mi', 'lease_contract[allowanceBasis]' => 'total', 'lease_contract[allowance]' => '10000', 'lease_contract[startOdometer]' => '7']);

        self::assertResponseRedirects($this->url());
        $contract = $this->contract();
        self::assertSame(16_093_440, $contract->getAllowance()->total()->metres());
        self::assertSame(11_265, $contract->getStartOdometer()->metres());
    }

    public function testSettingsAreShownBackAndEditedInPlace(): void
    {
        $this->submit([]);
        $this->client->request('GET', $this->url());

        self::assertInputValueSame('lease_contract[startDate]', '2026-01-01');
        self::assertInputValueSame('lease_contract[allowance]', '15000');
        self::assertInputValueSame('lease_contract[excessCostPerKm]', '0.12');
        self::assertInputValueSame('lease_contract[tolerancePercent]', '2.5');

        $this->submit(['lease_contract[allowance]' => '20000', 'lease_contract[currency]' => 'EUR']);

        self::assertResponseRedirects($this->url());
        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(LeaseContract::class)->findAll());
        $contract = $this->contract();
        self::assertSame(20_000_000, $contract->getAllowance()->total()->metres());
        self::assertSame(Currency::EUR, $contract->getExcessCostPerKm()->currency());
    }

    public function testSavingRecomputesTheProjectionWithTheNewTerms(): void
    {
        // 10,000 km after 182 days of a one-year lease: about 20,000 km projected at the end.
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'), Distance::fromKilometres(10_000)));
        $this->entityManager->flush();
        $this->submit(['lease_contract[startOdometer]' => '0', 'lease_contract[tolerancePercent]' => '0']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#assessment dd[data-risk]', 'Over the allowance');
        $excessBefore = $this->client->getCrawler()->filter('#assessment dd')->eq(3)->text();

        $this->submit(['lease_contract[allowance]' => '30000', 'lease_contract[startOdometer]' => '0', 'lease_contract[tolerancePercent]' => '0']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#assessment dd[data-risk]', 'OK');
        self::assertNotSame($excessBefore, $this->client->getCrawler()->filter('#assessment dd')->eq(3)->text());
    }

    public function testTermsThatContradictTheReadingsShowAnErrorAndSaveNothing(): void
    {
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'), Distance::fromKilometres(10_000)));
        $this->entityManager->flush();

        $this->submit(['lease_contract[startOdometer]' => '20000']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'These terms do not fit the recorded readings');
        self::assertCount(0, $this->entityManager->getRepository(LeaseContract::class)->findAll());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidSubmissions(): iterable
    {
        yield 'end before start' => [['lease_contract[endDate]' => '2025-01-01'], 'The lease must last a whole number of months'];
        yield 'partial month' => [['lease_contract[endDate]' => '2027-01-20'], 'The lease must last a whole number of months'];
        yield 'zero allowance' => [['lease_contract[allowance]' => '0'], 'This value should be positive.'];
        yield 'negative start odometer' => [['lease_contract[startOdometer]' => '-1'], 'This value should be either positive or zero.'];
        yield 'negative cost' => [['lease_contract[excessCostPerKm]' => '-0.12'], 'Enter an amount such as 0.12'];
        yield 'tolerance above 50%' => [['lease_contract[tolerancePercent]' => '60'], 'This value should be between 0 and 50.'];
        yield 'negative tolerance' => [['lease_contract[tolerancePercent]' => '-1'], 'This value should be between 0 and 50.'];
        yield 'currency outside the supported list' => [['lease_contract[currency]' => 'XXX'], 'The selected choice is invalid.'];
    }

    /**
     * @param array<string, string> $change
     */
    #[DataProvider('invalidSubmissions')]
    public function testInvalidSubmissionsAreRefusedWithAMessageAndStoreNothing(array $change, string $message): void
    {
        // Posted as a browser could, so that values outside the form's choices reach the validation too.
        $this->client->request('GET', $this->url());
        $token = $this->client->getCrawler()->selectButton('Save lease terms')->form()->getValues()['lease_contract[_token]'];
        $payload = ['_token' => $token];
        foreach ($change + self::VALID as $name => $value) {
            $payload[substr($name, \strlen('lease_contract['), -1)] = $value;
        }
        $this->client->request('POST', $this->url(), ['lease_contract' => $payload]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, preg_replace('/\s+/u', ' ', $this->client->getCrawler()->filter('body')->text()) ?? '');
        self::assertCount(0, $this->entityManager->getRepository(LeaseContract::class)->findAll());
    }

    /**
     * @param array<string, string> $change
     */
    private function submit(array $change): void
    {
        $this->client->request('GET', $this->url());
        $this->client->submitForm('Save lease terms', $change + self::VALID);
    }

    private function contract(): LeaseContract
    {
        $this->entityManager->clear();
        $contract = $this->entityManager->getRepository(LeaseContract::class)->findOneBy([]);
        self::assertInstanceOf(LeaseContract::class, $contract);

        return $contract;
    }

    private function url(): string
    {
        return \sprintf('/vehicles/%s/lease', $this->vehicle->getId()->toBase32());
    }
}

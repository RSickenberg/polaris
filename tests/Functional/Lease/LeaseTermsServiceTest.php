<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Domain\RiskLevel;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Service\LeaseTermsRejected;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

#[CoversClass(LeaseTermsService::class)]
#[CoversClass(LeaseContractRepository::class)]
final class LeaseTermsServiceTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private LeaseTermsService $service;
    private EntityManagerInterface $entityManager;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-07-02 12:00:00 UTC');
        $this->service = self::getContainer()->get(LeaseTermsService::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000040'), 'Model 3');
        $this->entityManager->persist($this->vehicle);
        // 10,000 km after 182 days of a one-year lease: about 20,000 km projected at the end.
        $this->entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-02 12:00:00', 'UTC'), Distance::fromKilometres(10_000)));
        $this->entityManager->flush();
    }

    public function testWithoutAContractThereIsNothingToAssess(): void
    {
        self::assertNull($this->service->find($this->vehicle));
        self::assertNull($this->service->assess($this->vehicle));
    }

    public function testTheFirstSaveCreatesTheContractAndProjectsIt(): void
    {
        $assessment = $this->service->save($this->vehicle, self::data(15_000));

        self::assertInstanceOf(LeaseAssessment::class, $assessment);
        self::assertSame(RiskLevel::Over, $assessment->riskLevel);
        $contract = $this->service->find($this->vehicle);
        self::assertInstanceOf(LeaseContract::class, $contract);
        self::assertSame(15_000_000, $contract->getAllowance()->total()->metres());
        self::assertSame(Currency::CHF, $contract->getExcessCostPerKm()->currency());
    }

    public function testEditingOverwritesTheContractAndRecomputesTheProjection(): void
    {
        $before = $this->service->save($this->vehicle, self::data(15_000));
        $id = $this->service->find($this->vehicle)?->getId();
        $this->entityManager->clear();

        $vehicle = $this->entityManager->find(Vehicle::class, $this->vehicle->getId());
        self::assertInstanceOf(Vehicle::class, $vehicle);
        $after = $this->service->save($vehicle, self::data(30_000));

        self::assertInstanceOf(LeaseAssessment::class, $before);
        self::assertInstanceOf(LeaseAssessment::class, $after);
        // The same readings, other terms: the projection follows the new allowance.
        self::assertSame(RiskLevel::Over, $before->riskLevel);
        self::assertSame(RiskLevel::Ok, $after->riskLevel);
        self::assertNotEquals($before->projectedExcess, $after->projectedExcess);
        self::assertGreaterThan($before->projection->allowedToDate->metres(), $after->projection->allowedToDate->metres());
        self::assertSame($before->projection->projectedAtEnd->metres(), $after->projection->projectedAtEnd->metres());

        // One contract, edited in place.
        $contracts = $this->entityManager->getRepository(LeaseContract::class)->findAll();
        self::assertCount(1, $contracts);
        self::assertTrue($id?->equals($contracts[0]->getId()));
        self::assertSame(30_000_000, $contracts[0]->getAllowance()->total()->metres());

        // And the stored contract is projected the same way on the next read.
        $stored = $this->service->assess($this->vehicle);
        self::assertInstanceOf(LeaseAssessment::class, $stored);
        self::assertSame(RiskLevel::Ok, $stored->riskLevel);
    }

    public function testTermsThatContradictTheReadingsAreRefusedAndNothingIsSaved(): void
    {
        $this->service->save($this->vehicle, self::data(15_000));

        $data = self::data(15_000);
        $data->startOdometer = 20_000; // above the 10,000 km reading
        try {
            $this->service->save($this->vehicle, $data);
            self::fail('The terms must be refused.');
        } catch (LeaseTermsRejected $rejected) {
            self::assertSame(LeaseTermsRejected::INCONSISTENT_READINGS, $rejected->getMessage());
        }

        $this->entityManager->clear();
        $contract = $this->entityManager->getRepository(LeaseContract::class)->findOneBy([]);
        self::assertSame(0, $contract?->getStartOdometer()->metres());
    }

    public function testIncompleteDataIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->save($this->vehicle, new LeaseContractData());
    }

    private static function data(int $allowancePerYear): LeaseContractData
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-01');
        $data->endDate = new \DateTimeImmutable('2027-01-01');
        $data->allowance = $allowancePerYear;
        $data->startOdometer = 0;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 0;

        return $data;
    }
}

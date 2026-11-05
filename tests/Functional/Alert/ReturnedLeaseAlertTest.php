<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Alert;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Alert\Entity\AlertLog;
use Polaris\Alert\EventListener\QueueRiskLevelCheck;
use Polaris\Alert\Message\CheckRiskLevel;
use Polaris\Alert\Service\Enum\RiskCheckOutcome;
use Polaris\Alert\Service\RiskLevelWatcher;
use Polaris\Lease\Domain\Allowance;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Domain\LeaseTerm;
use Polaris\Lease\Domain\Tolerance;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Shared\Domain\Currency;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\Money;
use Polaris\Tests\Support\SignsIn;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A returned lease is final: its risk level is not checked, nothing is logged, and an undo brings the check back.
 */
#[CoversClass(RiskLevelWatcher::class)]
#[CoversClass(QueueRiskLevelCheck::class)]
final class ReturnedLeaseAlertTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SignsIn;

    private Vehicle $vehicle;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-07-02 12:00:00 UTC');
        $entityManager = $this->entityManager();
        $this->vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000095'), 'Model 3', self::createUser());
        $entityManager->persist($this->vehicle);
        $entityManager->persist(new LeaseContract(
            $this->vehicle,
            LeaseTerm::fromCalendarDates(CarbonImmutable::parse('2026-01-01', 'UTC'), CarbonImmutable::parse('2027-01-01', 'UTC'), EndDateConvention::Exclusive),
            Allowance::ofTotal(Distance::fromKilometres(15_000)),
            Distance::fromKilometres(0),
            Money::fromDecimal('0.12', Currency::CHF),
            Tolerance::fromPercent(0),
        ));
        $entityManager->persist(OdometerReading::manual($this->vehicle, CarbonImmutable::parse('2026-07-01 10:00:00', 'UTC'), Distance::fromKilometres(9_000)));
        $entityManager->flush();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $this->transport = $transport;
    }

    public function testAReturnedLeaseIsNotAssessedAndNothingIsLogged(): void
    {
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-07-01'));

        self::assertSame(RiskCheckOutcome::NotAssessable, self::getContainer()->get(RiskLevelWatcher::class)->check($this->vehicle));
        self::assertSame([], $this->entityManager()->getRepository(AlertLog::class)->findAll());
    }

    public function testMarkingTheReturnQueuesNoCheck(): void
    {
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-07-01'));

        self::assertCount(0, $this->sent());
    }

    public function testUndoingTheReturnQueuesACheckAndTheLeaseIsAssessedAgain(): void
    {
        $this->leases()->markReturned($this->vehicle, new \DateTimeImmutable('2026-07-01'));
        $this->leases()->undoReturn($this->vehicle);

        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(CheckRiskLevel::class, $sent[0]->getMessage());
        self::assertSame(RiskCheckOutcome::Recorded, self::getContainer()->get(RiskLevelWatcher::class)->check($this->vehicle));
    }

    /**
     * @return list<\Symfony\Component\Messenger\Envelope>
     */
    private function sent(): array
    {
        return array_values($this->transport->getSent());
    }

    private function leases(): LeaseTermsService
    {
        return self::getContainer()->get(LeaseTermsService::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}

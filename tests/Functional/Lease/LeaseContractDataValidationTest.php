<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Lease;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polaris\Lease\Domain\EndDateConvention;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Shared\Domain\Currency;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(LeaseContractData::class)]
final class LeaseContractDataValidationTest extends KernelTestCase
{
    public function testValidData(): void
    {
        self::assertSame([], self::violations(self::validData()));
    }

    public function testInclusiveEndDateIsValid(): void
    {
        $data = self::validData();
        $data->endDate = new \DateTimeImmutable('2029-01-14');
        $data->endDateConvention = EndDateConvention::Inclusive;

        self::assertSame([], self::violations($data));
    }

    /**
     * @return iterable<string, array{\Closure(LeaseContractData): void, string}>
     */
    public static function invalidData(): iterable
    {
        yield 'missing start date' => [static function (LeaseContractData $data): void { $data->startDate = null; }, 'startDate'];
        yield 'missing end date' => [static function (LeaseContractData $data): void { $data->endDate = null; }, 'endDate'];
        yield 'partial month' => [static function (LeaseContractData $data): void { $data->endDate = new \DateTimeImmutable('2029-01-20'); }, 'endDate'];
        yield 'inclusive end one day over' => [static function (LeaseContractData $data): void { $data->endDateConvention = EndDateConvention::Inclusive; }, 'endDate'];
        yield 'end before start' => [static function (LeaseContractData $data): void { $data->endDate = new \DateTimeImmutable('2025-01-15'); }, 'endDate'];
        yield 'zero allowance' => [static function (LeaseContractData $data): void { $data->allowancePerYear = 0; }, 'allowancePerYear'];
        yield 'negative allowance' => [static function (LeaseContractData $data): void { $data->allowancePerYear = -15_000; }, 'allowancePerYear'];
        yield 'missing allowance' => [static function (LeaseContractData $data): void { $data->allowancePerYear = null; }, 'allowancePerYear'];
        yield 'negative start odometer' => [static function (LeaseContractData $data): void { $data->startOdometer = -1; }, 'startOdometer'];
        yield 'sub-cent excess cost' => [static function (LeaseContractData $data): void { $data->excessCostPerKm = '0.085'; }, 'excessCostPerKm'];
        yield 'negative excess cost' => [static function (LeaseContractData $data): void { $data->excessCostPerKm = '-0.12'; }, 'excessCostPerKm'];
        yield 'blank excess cost' => [static function (LeaseContractData $data): void { $data->excessCostPerKm = ''; }, 'excessCostPerKm'];
        yield 'missing currency' => [static function (LeaseContractData $data): void { $data->currency = null; }, 'currency'];
        yield 'negative tolerance' => [static function (LeaseContractData $data): void { $data->tolerancePercent = -1; }, 'tolerancePercent'];
        yield 'tolerance above 50%' => [static function (LeaseContractData $data): void { $data->tolerancePercent = 50.5; }, 'tolerancePercent'];
        yield 'missing tolerance' => [static function (LeaseContractData $data): void { $data->tolerancePercent = null; }, 'tolerancePercent'];
    }

    /**
     * @param \Closure(LeaseContractData): void $change
     */
    #[DataProvider('invalidData')]
    public function testInvalidData(\Closure $change, string $path): void
    {
        $data = self::validData();
        $change($data);

        self::assertSame([$path], self::violations($data));
    }

    private static function validData(): LeaseContractData
    {
        $data = new LeaseContractData();
        $data->startDate = new \DateTimeImmutable('2026-01-15');
        $data->endDate = new \DateTimeImmutable('2029-01-15');
        $data->allowancePerYear = 15_000;
        $data->startOdometer = 12;
        $data->excessCostPerKm = '0.12';
        $data->currency = Currency::CHF;
        $data->tolerancePercent = 10;

        return $data;
    }

    /**
     * @return list<string> the property paths of the violations
     */
    private static function violations(LeaseContractData $data): array
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $paths = [];

        foreach ($validator->validate($data) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return array_values(array_unique($paths));
    }
}

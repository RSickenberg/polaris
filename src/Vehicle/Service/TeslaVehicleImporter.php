<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\VehicleClient;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Dto\ImportCandidate;
use Polaris\Vehicle\Service\Dto\ImportResult;

/**
 * Lists the vehicles of the Tesla account of a user and creates the ones Polaris does not have yet. The
 * listing is read from Tesla every time, also when importing: what the caller asks for is only a choice
 * among the vehicles Tesla returned, never data to trust. It reads the list (first page) and nothing else:
 * it does not touch the cars.
 */
final readonly class TeslaVehicleImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeslaAppSettings $teslaApps,
        private VehicleClient $tesla,
    ) {
    }

    /**
     * @return list<ImportCandidate>
     *
     * @throws TeslaNotConnected when there is no Tesla app or connection
     * @throws FleetApiError
     */
    public function candidates(User $owner): array
    {
        $app = $this->teslaApps->find($owner) ?? throw TeslaNotConnected::never();
        $known = $this->knownVins();

        return array_map(
            static fn ($summary): ImportCandidate => new ImportCandidate($summary, isset($known[$summary->vin->value()])),
            $this->tesla->listVehicles($app),
        );
    }

    /**
     * @param list<Vin>|null $only the VINs to import, all the new ones when null. A VIN Tesla does not list is ignored
     *
     * @throws TeslaNotConnected
     * @throws FleetApiError
     * @throws ORMException
     */
    public function import(User $owner, ?array $only = null): ImportResult
    {
        $wanted = null === $only ? null : array_map(static fn (Vin $vin): string => $vin->value(), $only);
        $added = [];
        $alreadyAdded = 0;

        foreach ($this->candidates($owner) as $candidate) {
            if (null !== $wanted && !\in_array($candidate->summary->vin->value(), $wanted, true)) {
                continue;
            }

            if ($candidate->added) {
                ++$alreadyAdded;

                continue;
            }

            $vehicle = new Vehicle($candidate->summary->vin, $candidate->name(), $owner);
            $this->entityManager->persist($vehicle);
            $added[] = $vehicle;
        }

        $this->entityManager->flush();

        return new ImportResult($added, $alreadyAdded);
    }

    /**
     * @return array<string, true> the VINs Polaris has, whoever owns them (a VIN is unique)
     */
    private function knownVins(): array
    {
        $vins = [];

        foreach ($this->entityManager->getRepository(Vehicle::class)->findAll() as $vehicle) {
            $vins[$vehicle->getVin()->value()] = true;
        }

        return $vins;
    }
}

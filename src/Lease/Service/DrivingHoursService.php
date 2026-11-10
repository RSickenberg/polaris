<?php

declare(strict_types=1);

namespace Polaris\Lease\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Repository\LeaseContractRepository;
use Polaris\Lease\Service\Enum\DrivingHoursRejection;
use Polaris\Lease\Service\Exception\DrivingHoursRejected;
use Polaris\Vehicle\Entity\Vehicle;

/**
 * The hours the owner usually does not drive (from the usual stop time to the usual start time), and the only way to
 * change them. Without them the odometer is read as linear in time between two readings. Nothing is queued: no
 * projection snapshot depends on them, only the actual-versus-predicted panels read them, at display time.
 */
final readonly class DrivingHoursService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LeaseContractRepository $contracts,
    ) {
    }

    /**
     * @return array{from: ?string, until: ?string, todayWholeDay: bool} local "HH:MM" times, null when not set, and
     *                                                                   whether "Today" is compared with the whole day
     */
    public function find(Vehicle $vehicle): array
    {
        $contract = $this->contracts->findForVehicle($vehicle);

        return [
            'from' => $contract?->getQuietFrom(),
            'until' => $contract?->getQuietUntil(),
            'todayWholeDay' => $contract?->isTodayWholeDay() ?? false,
        ];
    }

    /**
     * Saves the usual stop and start times (both null clears them) and how "Today" is compared.
     *
     * @throws DrivingHoursRejected when there is no contract, only one time is given, or the times are not valid
     * @throws ORMException
     */
    public function save(Vehicle $vehicle, ?string $from, ?string $until, bool $todayWholeDay = false): void
    {
        $contract = $this->contracts->findForVehicle($vehicle);

        if (null === $contract) {
            throw new DrivingHoursRejected(DrivingHoursRejection::NoContract);
        }

        $from = '' === $from ? null : $from;
        $until = '' === $until ? null : $until;

        if (null !== $from xor null !== $until) {
            throw new DrivingHoursRejected(DrivingHoursRejection::Incomplete);
        }

        if (null !== $from && $from === $until) {
            throw new DrivingHoursRejected(DrivingHoursRejection::SameTime);
        }

        try {
            $contract->changeQuietHours($from, $until);
        } catch (\InvalidArgumentException) {
            throw new DrivingHoursRejected(DrivingHoursRejection::Invalid);
        }

        $contract->changeTodayWholeDay($todayWholeDay);
        $this->entityManager->flush();
    }
}

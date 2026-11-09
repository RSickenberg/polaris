<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Polaris\Account\Entity\User;
use Polaris\Alert\Entity\AlertLog;
use Polaris\Dashboard\Service\Dto\DebugCell;
use Polaris\Dashboard\Service\Dto\DebugTable;
use Polaris\Fetching\Entity\FetchSchedule;
use Polaris\Fetching\Entity\FetchSlotRun;
use Polaris\Fetching\Entity\RefreshAttempt;
use Polaris\Lease\Entity\LeaseContract;
use Polaris\Lease\Entity\ProjectionSnapshot;
use Polaris\Reading\Entity\OdometerReading;
use Polaris\Reading\Entity\VehicleDataSnapshot;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\Uid\Ulid;

/**
 * The raw rows behind the pages, for the debug page of a preview build: the signed-in user, their
 * vehicles and what hangs on them, the newest rows first. Read through the Doctrine mapping, so
 * a new column shows up by itself. Left out on purpose: the password hash, the Tesla app, the
 * keys and the tokens (they are secrets, and none of them helps to read a figure).
 */
final readonly class DebugDataCollector
{
    /** How many rows of each table the page shows. */
    private const int LIMIT = 15;

    /** Columns that never leave the database. */
    private const array HIDDEN = [User::class => ['password']];

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<DebugTable>
     *
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function collect(User $user): array
    {
        $vehicles = $this->entityManager->getRepository(Vehicle::class)->findBy(['owner' => $user]);
        $leases = [] === $vehicles
            ? []
            : $this->entityManager->getRepository(LeaseContract::class)->findBy(['vehicle' => $vehicles]);

        $tables = [
            $this->table(User::class, 'id', 'e.id IN (:scope)', [$user]),
            $this->table(Vehicle::class, 'name', 'e.owner IN (:scope)', [$user]),
        ];

        // Without a vehicle nothing else can belong to the user.
        if ([] === $vehicles) {
            return $tables;
        }

        array_push(
            $tables,
            $this->table(LeaseContract::class, 'startDate', 'e.vehicle IN (:scope)', $vehicles),
            $this->table(OdometerReading::class, 'readAt', 'e.vehicle IN (:scope)', $vehicles),
            $this->table(VehicleDataSnapshot::class, 'capturedAt', 'e.vehicle IN (:scope)', $vehicles),
            $this->table(ProjectionSnapshot::class, 'localDay', 'e.leaseContract IN (:scope)', $leases),
            $this->table(FetchSchedule::class, 'id', 'e.vehicle IN (:scope)', $vehicles),
            $this->table(FetchSlotRun::class, 'slotAt', 'e.vehicle IN (:scope)', $vehicles),
            $this->table(RefreshAttempt::class, 'requestedAt', 'e.vehicle IN (:scope)', $vehicles),
            $this->table(AlertLog::class, 'sentAt', 'e.vehicle IN (:scope)', $vehicles),
        );

        return $tables;
    }

    /**
     * @param class-string $class
     * @param list<object> $scope the entities the condition keeps rows of
     *
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    private function table(string $class, string $orderBy, string $condition, array $scope): DebugTable
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $columns = array_values(array_diff(
            [...$metadata->getFieldNames(), ...$metadata->getAssociationNames()],
            self::HIDDEN[$class] ?? [],
        ));

        // An empty scope matches nothing (a user without a lease has no snapshot).
        if ([] === $scope) {
            return new DebugTable($metadata->getTableName(), $columns, [], 0);
        }

        // The ids are bound as text: Doctrine does not map a list of entities to the ULID column type.
        $ids = array_map($this->idOf(...), $scope);

        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e)')
            ->from($class, 'e')
            ->where($condition)
            ->setParameter('scope', $ids, ArrayParameterType::STRING)
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<object> $entities */
        $entities = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($class, 'e')
            ->where($condition)
            ->setParameter('scope', $ids, ArrayParameterType::STRING)
            ->orderBy('e.' . $orderBy, \SortDirection::Descending)
            ->setMaxResults(self::LIMIT)
            ->getQuery()
            ->getResult();

        $rows = [];
        foreach ($entities as $entity) {
            $row = [];
            foreach ($columns as $column) {
                $row[$column] = $this->cell($metadata->getFieldValue($entity, $column));
            }
            $rows[] = $row;
        }

        return new DebugTable($metadata->getTableName(), $columns, $rows, $total);
    }

    private function idOf(object $entity): string
    {
        $id = $this->entityManager->getUnitOfWork()->getSingleIdentifierValue($entity);
        \assert($id instanceof Ulid);

        return $id->toRfc4122();
    }

    private function cell(mixed $value): DebugCell
    {
        if (null === $value) {
            return new DebugCell('null', null: true);
        }

        // A related entity is shown as its id.
        if (\is_object($value) && !$this->entityManager->getMetadataFactory()->isTransient($value::class)) {
            return $this->cell($this->entityManager->getUnitOfWork()->getSingleIdentifierValue($value));
        }

        $json = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR;

        return match (true) {
            $value instanceof Ulid => new DebugCell($value->toBase32()),
            $value instanceof \DateTimeInterface => new DebugCell(
                \DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s \U\T\C'),
            ),
            $value instanceof \BackedEnum => new DebugCell((string) $value->value),
            \is_bool($value) => new DebugCell($value ? 'true' : 'false'),
            \is_array($value) => new DebugCell(json_encode($value, $json) ?: '[]', multiline: true),
            \is_scalar($value) => new DebugCell((string) $value),
            default => new DebugCell($value instanceof \Stringable ? (string) $value : get_debug_type($value)),
        };
    }
}

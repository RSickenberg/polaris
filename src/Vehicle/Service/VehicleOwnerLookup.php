<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Uid\Ulid;

/**
 * Finds who owns a vehicle with one query that needs no entity manager, for the code that must know it in a worker even
 * after a failed flush.
 */
final readonly class VehicleOwnerLookup
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string $vehicleId the ULID (RFC 4122 form) of the vehicle
     *
     * @return string|null the ULID (RFC 4122 form) of the owner, null when the vehicle does not exist
     *
     * @throws DbalException
     */
    public function ownerIdOf(string $vehicleId): ?string
    {
        try {
            $id = Ulid::fromString($vehicleId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $owner = $this->connection->fetchOne(
            'SELECT owner_id FROM vehicle WHERE id = :id',
            ['id' => $id],
            ['id' => 'ulid'],
        );

        return \is_string($owner) ? Ulid::fromString($owner)->toRfc4122() : null;
    }
}

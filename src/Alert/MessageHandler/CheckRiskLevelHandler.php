<?php

declare(strict_types=1);

namespace Polaris\Alert\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Polaris\Alert\Message\CheckRiskLevel;
use Polaris\Alert\Service\Enum\RiskCheckOutcome;
use Polaris\Alert\Service\RiskLevelWatcher;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Ulid;

#[AsMessageHandler]
final readonly class CheckRiskLevelHandler
{
    /** Another check of the vehicle was running: it is queued once more, a little later. */
    private const int RETRY_DELAY_MS = 30_000;
    private const int MAX_RETRIES = 1;

    public function __construct(
        private RiskLevelWatcher $watcher,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @throws ORMException
     * @throws NonUniqueResultException
     * @throws ExceptionInterface
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockReleasingException
     */
    public function __invoke(CheckRiskLevel $message): void
    {
        $vehicle = $this->entityManager->find(Vehicle::class, Ulid::fromString($message->vehicleId));

        if (!$vehicle instanceof Vehicle) {
            return;
        }

        if (RiskCheckOutcome::Busy === $this->watcher->check($vehicle) && $message->attempt < self::MAX_RETRIES) {
            $this->bus->dispatch(new CheckRiskLevel($message->vehicleId, $message->attempt + 1), [new DelayStamp(self::RETRY_DELAY_MS)]);
        }
    }
}

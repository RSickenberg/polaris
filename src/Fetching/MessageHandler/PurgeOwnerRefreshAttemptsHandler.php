<?php

declare(strict_types=1);

namespace Polaris\Fetching\MessageHandler;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Message\OwnerMidnightPassed;
use Polaris\Fetching\Service\RefreshAttemptPurger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Ulid;

/**
 * The Fetching share of the owner's midnight: the purge of the owner's old manual refreshes.
 */
#[AsMessageHandler]
final readonly class PurgeOwnerRefreshAttemptsHandler
{
    public function __construct(private RefreshAttemptPurger $purger)
    {
    }

    /**
     * @throws ORMException
     */
    public function __invoke(OwnerMidnightPassed $message): void
    {
        $this->purger->purgeOf(Ulid::fromString($message->userId));
    }
}

<?php

declare(strict_types=1);

namespace Polaris\Fetching\MessageHandler;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Message\PurgeRefreshAttempts;
use Polaris\Fetching\Service\RefreshAttemptPurger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeRefreshAttemptsHandler
{
    public function __construct(private RefreshAttemptPurger $purger)
    {
    }

    /**
     * @throws ORMException
     */
    public function __invoke(PurgeRefreshAttempts $message): void
    {
        $this->purger->purge();
    }
}

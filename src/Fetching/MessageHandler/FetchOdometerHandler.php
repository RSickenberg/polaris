<?php

declare(strict_types=1);

namespace Polaris\Fetching\MessageHandler;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Service\OdometerFetcher;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FetchOdometerHandler
{
    public function __construct(private OdometerFetcher $fetcher)
    {
    }

    /**
     * @throws ORMException
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockReleasingException
     */
    public function __invoke(FetchOdometerMessage $message): void
    {
        $this->fetcher->fetch($message);
    }
}

<?php

declare(strict_types=1);

namespace Polaris\Fetching\MessageHandler;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Message\DispatchDueFetches;
use Polaris\Fetching\Service\DueFetchDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DispatchDueFetchesHandler
{
    public function __construct(private DueFetchDispatcher $dispatcher)
    {
    }

    /**
     * @throws ORMException
     */
    public function __invoke(DispatchDueFetches $message): void
    {
        $this->dispatcher->dispatch();
    }
}

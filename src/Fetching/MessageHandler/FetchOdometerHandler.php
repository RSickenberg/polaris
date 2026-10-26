<?php

declare(strict_types=1);

namespace Polaris\Fetching\MessageHandler;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Fetching\Message\FetchOdometerMessage;
use Polaris\Fetching\Service\OdometerFetcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FetchOdometerHandler
{
    public function __construct(private OdometerFetcher $fetcher)
    {
    }

    /**
     * @throws ORMException
     */
    public function __invoke(FetchOdometerMessage $message): void
    {
        $this->fetcher->fetch($message);
    }
}

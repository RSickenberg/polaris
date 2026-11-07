<?php

declare(strict_types=1);

namespace Polaris\Account\MessageHandler;

use Polaris\Account\Message\DispatchOwnerMidnights;
use Polaris\Account\Service\OwnerMidnightDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;

#[AsMessageHandler]
final readonly class DispatchOwnerMidnightsHandler
{
    public function __construct(private OwnerMidnightDispatcher $dispatcher)
    {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(DispatchOwnerMidnights $message): void
    {
        $this->dispatcher->dispatch();
    }
}

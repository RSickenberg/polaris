<?php

declare(strict_types=1);

namespace Polaris\Account\EventListener;

use Polaris\Account\Entity\User;
use Polaris\Shared\Reporting\ReportingContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Tells the error reporting who the request acts for, once the firewall has run. An error before the controller, or on a
 * page nobody signed in to, belongs to no user.
 */
final readonly class BindReportingUser
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private ReportingContext $context,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function onController(ControllerEvent $event): void
    {
        // A stateless route (the public key) must not touch the session, which reading the lazy token would do.
        if ($event->getRequest()->attributes->getBoolean('_stateless')) {
            $this->context->leave();

            return;
        }

        $user = $this->tokens->getToken()?->getUser();

        if ($user instanceof User) {
            $this->context->enterUser($user->getId()->toRfc4122());

            return;
        }

        $this->context->leave();
    }
}

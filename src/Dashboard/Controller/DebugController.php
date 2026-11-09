<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Controller;

use Polaris\Account\Entity\User;
use Polaris\Dashboard\Service\DebugDataCollector;
use Polaris\Dashboard\Service\PreviewBuild;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The raw data of the signed-in user, for dev and nightly builds. A release answers 404, as
 * if the page did not exist; the route is behind the same login as every other page.
 */
final class DebugController extends AbstractController
{
    public function __construct(
        private readonly PreviewBuild $build,
        private readonly DebugDataCollector $collector,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/debug', name: 'dashboard_debug', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): Response
    {
        if (!$this->build->isPreview()) {
            throw new NotFoundHttpException();
        }

        return $this->render('dashboard/debug.html.twig', [
            'runtime' => [
                'version' => $this->build->version(),
                'environment' => $this->getParameter('kernel.environment'),
                'symfony' => Kernel::VERSION,
                'php' => \PHP_VERSION,
                'now' => $this->clock->now()->format('Y-m-d H:i:s \U\T\C'),
            ],
            'tables' => $this->collector->collect($user),
        ]);
    }
}

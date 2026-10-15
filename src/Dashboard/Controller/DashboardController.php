<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Controller;

use Polaris\Account\Entity\User;
use Polaris\Dashboard\Service\DashboardService;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\VehiclePermission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {
    }

    // Not found rather than forbidden: another user's vehicle must not even be known to exist.
    #[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]
    #[Route('/vehicles/{id}', name: 'dashboard_show', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] Vehicle $vehicle, #[CurrentUser] User $user): Response
    {
        return $this->render('dashboard/show.html.twig', [
            'vehicle' => $vehicle,
            'dashboard' => $this->dashboard->viewFor($vehicle),
            'timeZone' => $user->getTimeZone(),
        ]);
    }
}

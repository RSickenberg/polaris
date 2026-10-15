<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Controller;

use Polaris\Dashboard\Service\DashboardService;
use Polaris\Shared\Domain\TimeZone;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {
    }

    #[Route('/vehicles/{id}', name: 'dashboard_show', methods: ['GET'])]
    public function show(#[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        return $this->render('dashboard/show.html.twig', [
            'vehicle' => $vehicle,
            'dashboard' => $this->dashboard->viewFor($vehicle),
            // Always UTC until the user's time zone exists (#21): then it is read from the user here.
            'timeZone' => TimeZone::UTC,
        ]);
    }
}

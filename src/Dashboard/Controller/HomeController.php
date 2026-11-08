<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Where the login lands: the vehicles of the user by name, each linking to its dashboard,
 * or a note when there is none. Vehicles whose lease is returned come last, and are marked.
 */
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LeaseReturnService $leaseReturns,
    ) {
    }

    #[Route('/', name: 'dashboard_home', methods: ['GET'])]
    public function home(#[CurrentUser] User $user): Response
    {
        $vehicles = $this->entityManager->getRepository(Vehicle::class)->findBy(['owner' => $user], ['name' => 'ASC']);

        $returned = [];
        foreach ($vehicles as $vehicle) {
            $returned[$vehicle->getId()->toBase32()] = $this->leaseReturns->isReturned($vehicle);
        }

        // The sort is stable: the vehicles keep their order by name inside each group.
        usort(
            $vehicles,
            static fn (Vehicle $a, Vehicle $b): int => $returned[$a->getId()->toBase32()] <=> $returned[$b->getId()->toBase32()],
        );

        return $this->render('dashboard/home.html.twig', [
            'vehicles' => $vehicles,
            'returned' => $returned,
        ]);
    }
}

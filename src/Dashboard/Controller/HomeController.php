<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Dashboard\Service\Enum\DashboardState;
use Polaris\Dashboard\Service\VehicleCardService;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Where the login lands: the vehicles of the user by name, each as a card of where its lease stands linking to its
 * dashboard, or a note when there is none. Vehicles whose lease is returned come last, and are marked.
 */
final class HomeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VehicleCardService $cards,
    ) {
    }

    /**
     * The root is public, so an edition can serve its own page there (a route on `/` with a higher priority). Here it
     * sends the visitor on: an anonymous one reaches the login page through the catch-all.
     */
    #[Route('/', name: 'dashboard_root', methods: ['GET'])]
    public function root(): Response
    {
        return $this->redirectToRoute('dashboard_home');
    }

    #[Route('/dashboard', name: 'dashboard_home', methods: ['GET'])]
    public function home(#[CurrentUser] User $user): Response
    {
        $vehicles = $this->entityManager->getRepository(Vehicle::class)->findBy(['owner' => $user], ['name' => 'ASC']);

        $cards = [];
        $returned = [];
        foreach ($vehicles as $vehicle) {
            $card = $this->cards->cardFor($vehicle);
            $cards[$vehicle->getId()->toBase32()] = $card;
            $returned[$vehicle->getId()->toBase32()] = DashboardState::Returned === $card->state;
        }

        // The sort is stable: the vehicles keep their order by name inside each group.
        $isReturned = static fn (Vehicle $vehicle): bool => $returned[$vehicle->getId()->toBase32()];
        usort($vehicles, static fn (Vehicle $a, Vehicle $b): int => $isReturned($a) <=> $isReturned($b));

        return $this->render('dashboard/home.html.twig', [
            'vehicles' => $vehicles,
            'cards' => $cards,
            'timeZone' => $user->getTimeZone(),
        ]);
    }
}

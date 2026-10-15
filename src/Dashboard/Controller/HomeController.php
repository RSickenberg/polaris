<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Entity\User;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Where the login lands: the vehicles of the user by name, each linking to its dashboard,
 * or a note when there is none.
 */
final class HomeController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/', name: 'dashboard_home', methods: ['GET'])]
    public function home(#[CurrentUser] User $user): Response
    {
        return $this->render('dashboard/home.html.twig', [
            'vehicles' => $this->entityManager->getRepository(Vehicle::class)->findBy(['owner' => $user], ['name' => 'ASC']),
        ]);
    }
}

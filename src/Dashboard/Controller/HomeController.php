<?php

declare(strict_types=1);

namespace Polaris\Dashboard\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where the login lands: the dashboard of the first vehicle by name, or a note when there is none.
 */
final class HomeController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('/', name: 'dashboard_home', methods: ['GET'])]
    public function home(): Response
    {
        $vehicle = $this->entityManager->getRepository(Vehicle::class)->findOneBy([], ['name' => 'ASC']);

        if (null === $vehicle) {
            return $this->render('dashboard/home_empty.html.twig');
        }

        return $this->redirectToRoute('dashboard_show', ['id' => $vehicle->getId()->toBase32()]);
    }
}

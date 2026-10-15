<?php

declare(strict_types=1);

namespace Polaris\Tesla\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Polaris\Extension\PublicKeyHosting;
use Polaris\Tesla\Entity\TeslaApp;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The URL Tesla fetches to verify the app. Public and stateless (no session, no cookie), it answers
 * the public key only. Self-hosted, there is one Tesla app, so it is served whatever the Host.
 */
final class PublicKeyController extends AbstractController
{
    #[Route('/.well-known/appspecific/com.tesla.3p.public-key.pem', name: 'tesla_public_key', methods: ['GET'], stateless: true)]
    public function __invoke(PublicKeyHosting $hosting, EntityManagerInterface $entityManager): Response
    {
        if (!$hosting->servesPublicKey()) {
            throw $this->createNotFoundException();
        }

        $app = $entityManager->getRepository(TeslaApp::class)->findOneBy([], ['keyGeneratedAt' => 'ASC']);

        if (!$app instanceof TeslaApp) {
            throw $this->createNotFoundException();
        }

        return new Response($app->getPublicKey(), Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}

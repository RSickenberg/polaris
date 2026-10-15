<?php

declare(strict_types=1);

namespace Polaris\Account\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The login page. The form_login authenticator of the firewall handles the POST on the same
 * path, and redirects back here with the error when the attempt fails.
 */
final class LoginController extends AbstractController
{
    #[Route('/login', name: 'account_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('dashboard_home');
        }

        return $this->render('account/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'lastEmail' => $authenticationUtils->getLastUsername(),
        ]);
    }

    /**
     * Intercepted by the logout key of the firewall; this method is never run.
     */
    #[Route('/logout', name: 'account_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall intercepts this route.');
    }
}

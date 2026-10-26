<?php

declare(strict_types=1);

namespace Polaris\Account\Controller;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Form\ForgotPasswordType;
use Polaris\Account\Form\Model\ForgotPasswordData;
use Polaris\Account\Form\Model\ResetPasswordData;
use Polaris\Account\Form\ResetPasswordType;
use Polaris\Account\Service\PasswordResetService;
use Random\RandomException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forgotten password, for a visitor who cannot sign in: ask for a link by email, then choose a new
 * password behind it. The answer never tells whether the email has an account.
 */
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $reset,

        #[Autowire(service: 'limiter.password_reset')]
        private readonly RateLimiterFactoryInterface $limiter,

        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forgot-password', name: 'account_forgot_password', methods: ['GET', 'POST'])]
    /**
     * @throws ORMException
     * @throws RandomException
     */
    public function forgot(Request $request): Response
    {
        $form = $this->createForm(ForgotPasswordType::class, new ForgotPasswordData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Per client address, whatever the email: a limit per email would tell which ones exist.
            if (!$this->limiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError($this->translator->trans('error.too_many_requests', domain: 'account')));

                return $this->render('account/forgot_password.html.twig', ['form' => $form], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            /** @var ForgotPasswordData $data */
            $data = $form->getData();
            $this->reset->request($data->email);

            return $this->redirectToRoute('account_forgot_password_sent');
        }

        return $this->render('account/forgot_password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/forgot-password/sent', name: 'account_forgot_password_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('account/forgot_password_sent.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'account_reset_password', methods: ['GET', 'POST'])]
    /**
     * @throws ORMException
     */
    public function reset(Request $request, string $token): Response
    {
        $pending = $this->reset->find($token);
        if (null === $pending) {
            return $this->noReferrer($this->render('account/reset_password_invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND)));
        }

        $form = $this->createForm(ResetPasswordType::class, new ResetPasswordData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ResetPasswordData $data */
            $data = $form->getData();
            $this->reset->reset($pending, $data->newPassword);
            $this->addFlash('success', $this->translator->trans('flash.password_reset', domain: 'account'));

            return $this->noReferrer($this->redirectToRoute('account_login'));
        }

        return $this->noReferrer($this->render('account/reset_password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }

    /**
     * The secret is in the address: the browser must not send it on, as a referrer, to anything.
     */
    private function noReferrer(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}

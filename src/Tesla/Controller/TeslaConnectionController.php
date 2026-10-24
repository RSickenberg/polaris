<?php

declare(strict_types=1);

namespace Polaris\Tesla\Controller;

use Polaris\Account\Entity\User;
use Polaris\Tesla\Domain\CallbackParameters;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Form\ActionType;
use Polaris\Tesla\Form\ConsentType;
use Polaris\Tesla\Form\Model\ConsentFormData;
use Polaris\Tesla\Form\Model\PasteCallbackFormData;
use Polaris\Tesla\Form\Model\PasteRefreshTokenFormData;
use Polaris\Tesla\Form\PasteCallbackType;
use Polaris\Tesla\Form\PasteRefreshTokenType;
use Polaris\Tesla\Service\Dto\ConnectionResult;
use Polaris\Tesla\Service\Exception\ConnectionRejected;
use Polaris\Tesla\Service\RedirectUriProvider;
use Polaris\Tesla\Service\TeslaConnectionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Connects the Tesla account of the signed-in user (ADR 0004). The consent comes first: every way to
 * connect (authorization redirect, pasted callback URL, pasted refresh token) is refused without it.
 * The app is always the user's own (looked up by owner), so there is no id to guess.
 */
final class TeslaConnectionController extends AbstractController
{
    private const string STATE_KEY = 'tesla_oauth_state';

    public function __construct(
        private readonly TeslaConnectionService $connection,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/tesla/consent', name: 'tesla_consent', methods: ['GET', 'POST'])]
    public function consent(Request $request, #[CurrentUser] User $user): Response
    {
        $app = $this->app($user);

        if ($app->hasConsent()) {
            return $this->redirectToRoute('tesla_connect');
        }

        $form = $this->createForm(ConsentType::class, new ConsentFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->connection->giveConsent($app);

            return $this->redirectToRoute('tesla_connect');
        }

        return $this->render('tesla/consent.html.twig', [
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/settings/tesla/connect', name: 'tesla_connect', methods: ['GET'])]
    public function connect(#[CurrentUser] User $user): Response
    {
        $app = $this->app($user);

        if (!$app->hasConsent()) {
            return $this->redirectToRoute('tesla_consent');
        }

        return $this->renderConnect($app, $this->createPasteCallbackForm(), $this->createPasteRefreshTokenForm());
    }

    #[Route('/settings/tesla/connect/authorize', name: 'tesla_authorize', methods: ['POST'])]
    public function authorize(Request $request, #[CurrentUser] User $user): Response
    {
        $app = $this->app($user);
        $form = $this->createAuthorizeForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->redirectToRoute('tesla_connect');
        }

        try {
            $authorization = $this->connection->startAuthorization($app);
        } catch (ConnectionRejected $rejected) {
            return $this->reject($rejected);
        }

        $request->getSession()->set(self::STATE_KEY, $authorization->state);

        return new RedirectResponse($authorization->url);
    }

    #[Route(RedirectUriProvider::CALLBACK_PATH, name: 'tesla_callback', methods: ['GET'])]
    public function callback(Request $request, #[CurrentUser] User $user): Response
    {
        $app = $this->app($user);
        // One try per authorization request, whatever the outcome.
        $expectedState = $request->getSession()->remove(self::STATE_KEY);

        $callback = new CallbackParameters(
            $this->queryValue($request, 'code'),
            $this->queryValue($request, 'state'),
            $this->queryValue($request, 'error'),
        );

        try {
            $result = $this->connection->completeFromCallback($app, $callback, \is_string($expectedState) ? $expectedState : null);
        } catch (ConnectionRejected $rejected) {
            return $this->reject($rejected);
        }

        return $this->connected($result);
    }

    #[Route('/settings/tesla/connect/callback-url', name: 'tesla_paste_callback', methods: ['POST'])]
    public function pasteCallback(Request $request, #[CurrentUser] User $user): Response
    {
        $app = $this->app($user);
        $data = new PasteCallbackFormData();
        $form = $this->createPasteCallbackForm($data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderConnect($app, $form, $this->createPasteRefreshTokenForm());
        }

        $session = $request->getSession();
        $state = $session->get(self::STATE_KEY);

        try {
            $result = $this->connection->completeFromPastedUrl($app, (string) $data->url, \is_string($state) ? $state : null);
        } catch (ConnectionRejected $rejected) {
            if (ConnectionRejected::NO_CONSENT === $rejected->key) {
                return $this->reject($rejected);
            }

            $form->get('url')->addError(new FormError($this->translator->trans($rejected->key, domain: 'tesla')));

            return $this->renderConnect($app, $form, $this->createPasteRefreshTokenForm());
        }

        $session->remove(self::STATE_KEY);

        return $this->connected($result);
    }

    #[Route('/settings/tesla/connect/refresh-token', name: 'tesla_paste_token', methods: ['POST'])]
    public function pasteRefreshToken(Request $request, #[CurrentUser] User $user): Response
    {
        $app = $this->app($user);
        $data = new PasteRefreshTokenFormData();
        $form = $this->createPasteRefreshTokenForm($data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderConnect($app, $this->createPasteCallbackForm(), $form);
        }

        try {
            $result = $this->connection->connectWithRefreshToken($app, (string) $data->refreshToken);
        } catch (ConnectionRejected $rejected) {
            if (ConnectionRejected::NO_CONSENT === $rejected->key) {
                return $this->reject($rejected);
            }

            $form->get('refreshToken')->addError(new FormError($this->translator->trans($rejected->key, domain: 'tesla')));

            return $this->renderConnect($app, $this->createPasteCallbackForm(), $form);
        }

        return $this->connected($result);
    }

    #[Route('/settings/tesla/disconnect', name: 'tesla_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, #[CurrentUser] User $user): Response
    {
        $app = $this->app($user);
        $form = $this->createDisconnectForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->connection->disconnect($app);
            $this->addFlash('success', $this->translator->trans('flash.disconnected', domain: 'tesla'));
        }

        return $this->redirectToRoute('tesla_settings');
    }

    private function app(User $user): TeslaApp
    {
        return $this->connection->findApp($user) ?? throw $this->createNotFoundException();
    }

    /**
     * @return FormInterface<null>
     */
    private function createDisconnectForm(): FormInterface
    {
        return $this->formFactory->createNamed(TeslaSettingsController::DISCONNECT_FORM, ActionType::class, null, [
            'submit_label' => 'form.disconnect.submit',
            'action' => $this->generateUrl('tesla_disconnect'),
        ]);
    }

    /**
     * @return FormInterface<null>
     */
    private function createAuthorizeForm(): FormInterface
    {
        return $this->formFactory->createNamed('authorize', ActionType::class, null, [
            'submit_label' => 'form.authorize.submit',
            'action' => $this->generateUrl('tesla_authorize'),
        ]);
    }

    /**
     * @return FormInterface<PasteCallbackFormData>
     */
    private function createPasteCallbackForm(?PasteCallbackFormData $data = null): FormInterface
    {
        return $this->createForm(PasteCallbackType::class, $data ?? new PasteCallbackFormData(), [
            'action' => $this->generateUrl('tesla_paste_callback'),
        ]);
    }

    /**
     * @return FormInterface<PasteRefreshTokenFormData>
     */
    private function createPasteRefreshTokenForm(?PasteRefreshTokenFormData $data = null): FormInterface
    {
        return $this->createForm(PasteRefreshTokenType::class, $data ?? new PasteRefreshTokenFormData(), [
            'action' => $this->generateUrl('tesla_paste_token'),
        ]);
    }

    /**
     * @param FormInterface<PasteCallbackFormData>     $pasteCallback
     * @param FormInterface<PasteRefreshTokenFormData> $pasteToken
     */
    private function renderConnect(TeslaApp $app, FormInterface $pasteCallback, FormInterface $pasteToken): Response
    {
        $failed = $pasteCallback->isSubmitted() || $pasteToken->isSubmitted();

        return $this->render('tesla/connect.html.twig', [
            'teslaApp' => $app,
            'redirectUri' => $this->connection->redirectUri(),
            'authorizeForm' => $this->createAuthorizeForm(),
            'pasteCallbackForm' => $pasteCallback,
            'pasteTokenForm' => $pasteToken,
        ], new Response(status: $failed ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function connected(ConnectionResult $result): Response
    {
        $this->addFlash('success', $this->translator->trans('flash.connected', domain: 'tesla'));

        if ($result->regionDiffers) {
            $this->addFlash('error', $this->translator->trans('flash.region_differs', domain: 'tesla'));
        }

        return $this->redirectToRoute('tesla_settings');
    }

    private function reject(ConnectionRejected $rejected): Response
    {
        $this->addFlash('error', $this->translator->trans($rejected->key, domain: 'tesla'));

        return $this->redirectToRoute(ConnectionRejected::NO_CONSENT === $rejected->key ? 'tesla_consent' : 'tesla_connect');
    }

    private function queryValue(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}

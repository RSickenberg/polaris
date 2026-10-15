<?php

declare(strict_types=1);

namespace Polaris\Tesla\Controller;

use Polaris\Account\Entity\User;
use Polaris\Tesla\Form\Model\TeslaAppSettingsFormData;
use Polaris\Tesla\Form\TeslaAppSettingsType;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaSettingsRejected;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Tesla app credentials of the signed-in user. The app is always the user's own (looked up by
 * owner), so there is no id to guess. Registering with Tesla stays on the console
 * (polaris:tesla:key:check and polaris:tesla:register).
 */
final class TeslaSettingsController extends AbstractController
{
    public function __construct(
        private readonly TeslaAppSettings $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/tesla', name: 'tesla_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $app = $this->settings->find($user);

        if (null === $app) {
            return $this->render('tesla/settings.html.twig', [
                'teslaApp' => null,
                'form' => null,
                'timeZone' => $user->getTimeZone(),
            ]);
        }

        $data = TeslaAppSettingsFormData::fromApp($app);
        $form = $this->createForm(TeslaAppSettingsType::class, $data, ['secret_stored' => $data->hasStoredSecret()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->settings->save($user, $data->toSettingsData());
                $this->addFlash('success', $this->translator->trans('flash.saved', domain: 'tesla'));

                return $this->redirectToRoute('tesla_settings');
            } catch (TeslaSettingsRejected) {
                // The form validation covers every case the service refuses, so this is a safety net.
                $form->addError(new FormError($this->translator->trans('error.rejected', domain: 'tesla')));
            }
        }

        return $this->render('tesla/settings.html.twig', [
            'teslaApp' => $app,
            'form' => $form,
            'timeZone' => $user->getTimeZone(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}

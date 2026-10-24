<?php

declare(strict_types=1);

namespace Polaris\Account\Controller;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Account\Form\Model\PasswordChangeData;
use Polaris\Account\Form\Model\ProfileData;
use Polaris\Account\Form\PasswordChangeType;
use Polaris\Account\Form\ProfileType;
use Polaris\Account\Service\Exception\UserSettingsRejected;
use Polaris\Account\Service\UserSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The settings of the signed-in user: profile (email, language, time zone) and password.
 * Each part is its own form, so saving one never touches the other.
 */
final class SettingsController extends AbstractController
{
    private const string FIREWALL = 'main';
    private const string AUTHENTICATOR = 'security.authenticator.form_login.main';

    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly UserSettings $settings,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings', name: 'account_settings', methods: ['GET', 'POST'])]
    /**
     * @throws ORMException
     */
    public function settings(Request $request, #[CurrentUser] User $user): Response
    {
        $profile = $this->forms->createNamed('profile', ProfileType::class, ProfileData::fromUser($user));
        $password = $this->forms->createNamed('password', PasswordChangeType::class, new PasswordChangeData());
        $profile->handleRequest($request);
        $password->handleRequest($request);

        if ($profile->isSubmitted() && $profile->isValid()) {
            /** @var ProfileData $data */
            $data = $profile->getData();
            try {
                $this->settings->updateProfile($user, $data->email, $data->locale, $data->timeZone);

                return $this->savedAndSignedInAgain($user, 'flash.profile_saved');
            } catch (UserSettingsRejected $rejected) {
                $profile->get('email')->addError(new FormError($this->translator->trans($rejected->getMessage(), domain: 'validators')));
            }
        }

        if ($password->isSubmitted() && $password->isValid()) {
            /** @var PasswordChangeData $data */
            $data = $password->getData();
            $this->settings->changePassword($user, $data->newPassword);

            return $this->savedAndSignedInAgain($user, 'flash.password_saved');
        }

        return $this->render('account/settings.html.twig', [
            'profileForm' => $profile,
            'passwordForm' => $password,
        ], new Response(status: $profile->isSubmitted() || $password->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * A changed email or password makes the session stale: signing in again, without asking, keeps
     * the user here (and puts their new language in the session).
     */
    private function savedAndSignedInAgain(User $user, string $flash): Response
    {
        $this->security->login($user, self::AUTHENTICATOR, self::FIREWALL);
        // In the language the user just chose, which is the one of the next page.
        $this->addFlash('success', $this->translator->trans($flash, domain: 'account', locale: $user->getLocale()));

        return $this->redirectToRoute('account_settings');
    }
}

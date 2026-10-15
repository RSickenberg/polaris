<?php

declare(strict_types=1);

namespace Polaris\Account\Service;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Polaris\Account\Domain\ResetToken;
use Polaris\Account\Entity\PasswordResetRequest;
use Polaris\Account\Repository\PasswordResetRequestRepository;
use Polaris\Account\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forgotten password: emails a single-use link, valid for an hour, and accepts the new password
 * behind it. Asking never tells whether the email has an account, and no link is sent more than
 * once every few minutes, so the form cannot be used to flood an inbox.
 */
final readonly class PasswordResetService
{
    /**
     * Minutes during which a new request for the same account sends nothing more.
     */
    private const int RESEND_AFTER_MINUTES = 2;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private PasswordResetRequestRepository $requests,
        private UserSettings $settings,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $from,
        // The public address of the app: the link is built from it, never from the Host header of the request.
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $publicUrl,
    ) {
    }

    /**
     * Sends the link when the email belongs to a user. Returns nothing either way.
     */
    public function request(string $email): void
    {
        $now = CarbonImmutable::instance($this->clock->now());
        $this->requests->removeExpired($now);

        $user = $this->users->loadUserByIdentifier($email);
        if (null === $user) {
            return;
        }

        $latest = $this->requests->findLatestFor($user);
        if (null !== $latest && $latest->getRequestedAt() > $now->subMinutes(self::RESEND_AFTER_MINUTES)) {
            return;
        }

        $token = ResetToken::generate();
        $this->requests->removeFor($user);
        $this->entityManager->persist(new PasswordResetRequest($user, $token, $now));
        $this->entityManager->flush();

        $url = rtrim($this->publicUrl, '/') . $this->urlGenerator->generate('account_reset_password', ['token' => $token->toString()]);
        $message = new TemplatedEmail()
            ->from(Address::create($this->from))
            ->to($user->getEmail())
            ->subject($this->translator->trans('email.reset.subject', domain: 'account', locale: $user->getLocale()))
            ->htmlTemplate('account/email/reset_password.html.twig')
            ->textTemplate('account/email/reset_password.txt.twig')
            ->context(['url' => $url, 'locale' => $user->getLocale()]);

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $exception) {
            // Not shown to the visitor: an error here would tell that the account exists.
            $this->logger->error('The password reset email could not be sent.', ['exception' => $exception]);
        }
    }

    /**
     * The pending request behind a link, or null when the link is unknown, altered or expired.
     */
    public function find(#[\SensitiveParameter] string $text): ?PasswordResetRequest
    {
        $token = ResetToken::parse($text);
        if (null === $token) {
            return null;
        }

        $request = $this->requests->findBySelector($token->selector());
        if (null === $request || !$request->isFor($token) || $request->isExpired(CarbonImmutable::instance($this->clock->now()))) {
            return null;
        }

        return $request;
    }

    /**
     * Sets the new password. The link, and every other one of the user, stops working.
     *
     * @throws \InvalidArgumentException when the password is too short or too long
     */
    public function reset(PasswordResetRequest $request, #[\SensitiveParameter] string $newPassword): void
    {
        $this->settings->changePassword($request->getUser(), $newPassword);
    }
}

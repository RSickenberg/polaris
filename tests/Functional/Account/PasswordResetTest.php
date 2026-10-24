<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Account;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Account\Entity\PasswordResetRequest;
use Polaris\Account\Entity\User;
use Polaris\Account\Service\Dto\NewUser;
use Polaris\Account\Service\UserCreator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Forgotten password: the link is emailed, single use and short lived, and nothing tells
 * whether an email has an account. The limiter state is emptied around every test.
 */
#[CoversNothing]
final class PasswordResetTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string PASSWORD = 'correct horse battery';
    private const string NEW_PASSWORD = 'another staple horse';

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        self::mockTime('2026-10-20 12:00:00 UTC');
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->user = self::getContainer()->get(UserCreator::class)->create(new NewUser('jane.doe@example.test', self::PASSWORD));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        parent::tearDown();
    }

    public function testTheLoginPageLinksToTheRequestPage(): void
    {
        $this->client->request('GET', '/login');

        self::assertSelectorExists('a[href="/forgot-password"]');
    }

    public function testAnExistingEmailGetsOneLink(): void
    {
        $this->ask('jane.doe@example.test');

        self::assertResponseRedirects('/forgot-password/sent');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'jane.doe@example.test');
        self::assertEmailAddressContains($email, 'From', 'polaris@localhost');
        self::assertSame('Reset your Polaris password', $email->getSubject());
        self::assertMatchesRegularExpression('#^' . preg_quote($this->publicUrl(), '#') . '/reset-password/[A-Za-z0-9_-]{63}$#', $this->link($email));
        self::assertStringContainsString($this->link($email), (string) $email->getTextBody());
    }

    public function testAnUnknownEmailGetsTheSameAnswerAndNoEmail(): void
    {
        $this->ask('nobody@example.test');

        self::assertResponseRedirects('/forgot-password/sent');
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Check your inbox');
    }

    public function testTheLinkIsBuiltFromTheConfiguredAddressNotTheHostHeader(): void
    {
        $this->client->request('GET', '/forgot-password', server: ['HTTP_HOST' => 'evil.example']);
        $this->client->submitForm('Send the link', ['forgot_password[email]' => 'jane.doe@example.test'], serverParameters: ['HTTP_HOST' => 'evil.example']);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringStartsWith($this->publicUrl() . '/reset-password/', $this->link($email));
        self::assertStringNotContainsString('evil.example', (string) $email->getHtmlBody());
    }

    public function testAnInvalidEmailIsRefused(): void
    {
        $this->ask('not an email');

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testTheEmailIsInTheLanguageOfTheUser(): void
    {
        $this->user->changeLocale('fr');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->ask('jane.doe@example.test');

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Réinitialisez votre mot de passe Polaris', $email->getSubject());
        self::assertStringContainsString('Choisir un nouveau mot de passe', (string) $email->getHtmlBody());
    }

    public function testAskingAgainRightAwaySendsNothingMoreButLaterReplacesTheLink(): void
    {
        $this->ask('jane.doe@example.test');
        $first = $this->link(self::getMailerMessage() ?? self::fail());

        // The mailer's log is per request: this one sent nothing.
        $this->ask('jane.doe@example.test');
        self::assertEmailCount(0);

        self::mockTime('2026-10-20 12:03:00 UTC');
        $this->ask('jane.doe@example.test');
        self::assertEmailCount(1);
        $second = $this->link(self::getMailerMessage() ?? self::fail());

        self::assertNotSame($first, $second);
        $this->client->request('GET', $this->path($first));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $this->path($second));
        self::assertResponseIsSuccessful();
    }

    public function testTheLinkChoosesANewPasswordOnceAndSignsEveryoneOut(): void
    {
        $this->ask('jane.doe@example.test');
        $link = $this->link(self::getMailerMessage() ?? self::fail());

        $this->client->request('GET', $this->path($link));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertSelectorExists('input[name="reset_password[newPassword][second]"][type=password]');

        $this->client->submitForm('Change the password', [
            'reset_password[newPassword][first]' => self::NEW_PASSWORD,
            'reset_password[newPassword][second]' => self::NEW_PASSWORD,
        ]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-slot=alert]', 'Your password was changed.');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->stored(), self::NEW_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($this->stored(), self::PASSWORD));

        // Single use.
        $this->client->request('GET', $this->path($link));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheNewPasswordWorksOnTheLoginPage(): void
    {
        $this->ask('jane.doe@example.test');
        $this->client->request('GET', $this->path($this->link(self::getMailerMessage() ?? self::fail())));
        $this->client->submitForm('Change the password', [
            'reset_password[newPassword][first]' => self::NEW_PASSWORD,
            'reset_password[newPassword][second]' => self::NEW_PASSWORD,
        ]);

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'jane.doe@example.test', '_password' => self::NEW_PASSWORD], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/');
    }

    public function testAnExpiredLinkDoesNotWork(): void
    {
        $this->ask('jane.doe@example.test');
        $link = $this->link(self::getMailerMessage() ?? self::fail());

        self::mockTime('2026-10-20 13:00:01 UTC');
        $this->client->request('GET', $this->path($link));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'This link does not work');
        self::assertSelectorExists('a[href="/forgot-password"]');
    }

    public function testAnAlteredOrInventedLinkDoesNotWork(): void
    {
        $this->ask('jane.doe@example.test');
        $link = $this->link(self::getMailerMessage() ?? self::fail());

        $altered = substr($link, 0, -1) . ('a' === substr($link, -1) ? 'b' : 'a');
        $this->client->request('GET', $this->path($altered));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/reset-password/not-a-token');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', $this->path($altered));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTwoDifferentPasswordsKeepTheLinkAndThePassword(): void
    {
        $this->ask('jane.doe@example.test');
        $link = $this->link(self::getMailerMessage() ?? self::fail());

        $this->client->request('GET', $this->path($link));
        $this->client->submitForm('Change the password', [
            'reset_password[newPassword][first]' => self::NEW_PASSWORD,
            'reset_password[newPassword][second]' => self::NEW_PASSWORD . 'x',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot=field-error]', 'The two passwords do not match.');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->stored(), self::PASSWORD));
        $this->client->request('GET', $this->path($link));
        self::assertResponseIsSuccessful();
    }

    public function testATooShortPasswordIsRefused(): void
    {
        $this->ask('jane.doe@example.test');
        $this->client->request('GET', $this->path($this->link(self::getMailerMessage() ?? self::fail())));

        $this->client->submitForm('Change the password', [
            'reset_password[newPassword][first]' => 'short',
            'reset_password[newPassword][second]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->stored(), self::PASSWORD));
    }

    public function testChangingThePasswordInTheSettingsCancelsPendingLinks(): void
    {
        $this->ask('jane.doe@example.test');
        $link = $this->link(self::getMailerMessage() ?? self::fail());

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Change the password', [
            'password[currentPassword]' => self::PASSWORD,
            'password[newPassword][first]' => self::NEW_PASSWORD,
            'password[newPassword][second]' => self::NEW_PASSWORD,
        ]);

        $this->client->request('GET', $this->path($link));
        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(PasswordResetRequest::class)->findAll());
    }

    public function testTheSixthRequestFromTheSameAddressIsRefused(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->ask('nobody' . $attempt . '@example.test');
            self::assertResponseRedirects('/forgot-password/sent');
        }

        $this->ask('nobody6@example.test');

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('[data-slot=field-error], [role=alert]', 'Too many requests.');
    }

    public function testNothingIsStoredInClear(): void
    {
        $this->ask('jane.doe@example.test');
        $link = $this->link(self::getMailerMessage() ?? self::fail());
        $token = substr($link, -63);

        $stored = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative('SELECT selector, hashed_verifier FROM password_reset_request');

        self::assertCount(1, $stored);
        self::assertSame(substr($token, 0, 20), $stored[0]['selector']);
        self::assertStringNotContainsString(substr($token, 20), (string) json_encode($stored[0]));
    }

    private function ask(string $email): void
    {
        $this->client->request('GET', '/forgot-password');
        $this->client->submitForm('Send the link', ['forgot_password[email]' => $email]);
    }

    private function link(Email|\Symfony\Component\Mime\RawMessage $email): string
    {
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(1, preg_match('#href="([^"]+/reset-password/[^"]+)"#', (string) $email->getHtmlBody(), $matches));

        return $matches[1];
    }

    /**
     * The path of a link: the test client sends it to the host it was built from, which is not
     * always the one of the request it replays (https://localhost:443 is not https://localhost).
     */
    private function path(string $link): string
    {
        return (string) parse_url($link, \PHP_URL_PATH);
    }

    private function publicUrl(): string
    {
        $configured = $_SERVER['DEFAULT_URI'] ?? 'http://localhost';
        \assert(\is_string($configured));

        return rtrim($configured, '/');
    }

    private function stored(): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $this->user->getId()) ?? self::fail();
    }
}

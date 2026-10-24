<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Account;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Account\Entity\User;
use Polaris\Account\Service\Dto\NewUser;
use Polaris\Account\Service\UserCreator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The settings page: profile and password are separate forms, and the user stays signed in
 * after a change of email or password.
 */
#[CoversNothing]
final class SettingsTest extends WebTestCase
{
    private const string PASSWORD = 'correct horse battery';
    private const string NEW_PASSWORD = 'another staple horse';

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = self::getContainer()->get(UserCreator::class)->create(new NewUser('jane.doe@example.test', self::PASSWORD));
        $this->client->loginUser($this->user);
    }

    public function testThePageShowsBothFormsWithTheCurrentValues(): void
    {
        $this->client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Settings');
        self::assertInputValueSame('profile[email]', 'jane.doe@example.test');
        self::assertSelectorExists('select[name="profile[locale]"] option[value=fr]');
        self::assertSelectorExists('select[name="profile[timeZone]"] option[value="Europe/Zurich"]');
        self::assertSelectorExists('input[name="password[newPassword][first]"][type=password]');
        self::assertSelectorExists('input[name="password[newPassword][second]"][type=password]');
    }

    public function testTheProfileIsSavedAndTheUserStaysSignedIn(): void
    {
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Save the profile', [
            'profile[email]' => ' New.Name@Example.test ',
            'profile[locale]' => 'fr',
            'profile[timeZone]' => 'Europe/Zurich',
        ]);

        self::assertResponseRedirects('/settings');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang=fr]');
        self::assertSelectorTextContains('[data-slot=alert]', 'Votre profil a été enregistré.');
        $stored = $this->stored();
        self::assertSame('new.name@example.test', $stored->getEmail());
        self::assertSame('fr', $stored->getLocale());
        self::assertSame('Europe/Zurich', $stored->getTimeZone());
        self::assertSelectorTextContains('[data-slot=sidebar-footer]', 'new.name@example.test');
    }

    public function testAnEmailUsedByAnotherUserIsRefused(): void
    {
        $other = new User('taken@example.test', 'not-a-real-hash', $this->user->getCreatedAt());
        self::getContainer()->get(EntityManagerInterface::class)->persist($other);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/settings');
        $this->client->submitForm('Save the profile', ['profile[email]' => 'Taken@example.test']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot=field-error]', 'This email is already used.');
        self::assertSame('jane.doe@example.test', $this->stored()->getEmail());
    }

    public function testAnInvalidEmailIsRefused(): void
    {
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Save the profile', ['profile[email]' => 'not an email']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('jane.doe@example.test', $this->stored()->getEmail());
    }

    public function testThePasswordIsChangedAndTheUserStaysSignedIn(): void
    {
        $this->changePassword(self::PASSWORD, self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/settings');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot=alert]', 'Your password was changed.');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->stored(), self::NEW_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($this->stored(), self::PASSWORD));
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testAWrongCurrentPasswordIsRefused(): void
    {
        $this->changePassword('not the password', self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->stored(), self::PASSWORD));
    }

    public function testTwoDifferentNewPasswordsAreRefused(): void
    {
        $this->changePassword(self::PASSWORD, self::NEW_PASSWORD, self::NEW_PASSWORD . 'x');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot=field-error]', 'The two passwords do not match.');
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->stored(), self::PASSWORD));
    }

    public function testATooShortNewPasswordIsRefused(): void
    {
        $this->changePassword(self::PASSWORD, 'short', 'short');

        self::assertResponseStatusCodeSame(422);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->stored(), self::PASSWORD));
    }

    public function testTheSidebarShowsTheInitialsAndAMenuWithSettingsAndSignOut(): void
    {
        $this->client->request('GET', '/settings');

        self::assertSelectorTextContains('[data-slot=sidebar-footer] [data-slot=avatar-fallback]', 'JD');
        self::assertSelectorExists('[data-slot=sidebar-footer] [data-dropdown-menu-target=trigger]');
        self::assertSelectorTextContains('[data-slot=sidebar-footer] [data-slot=dropdown-menu-content] a[href="/settings"]', 'Settings');
        self::assertSelectorTextContains('[data-slot=sidebar-footer] [data-slot=dropdown-menu-content] a[href^="/logout"]', 'Sign out');
    }

    private function changePassword(string $current, string $new, string $repeat): void
    {
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Change the password', [
            'password[currentPassword]' => $current,
            'password[newPassword][first]' => $new,
            'password[newPassword][second]' => $repeat,
        ]);
    }

    private function stored(): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $this->user->getId()) ?? self::fail();
    }
}

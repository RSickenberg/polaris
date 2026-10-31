<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Account;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Account\Entity\User;
use Polaris\Account\Service\Dto\NewUser;
use Polaris\Account\Service\UserCreator;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The form login of the firewall: success, failure, CSRF, throttling and logout. The limiter keeps
 * its state in the cache, which the database rollback does not reset: the pool is emptied around
 * every test, so no attempt leaks from one test or one run to the next.
 */
#[CoversNothing]
final class LoginTest extends WebTestCase
{
    private const string PASSWORD = 'correct horse battery';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The throttling counts the attempts across requests: keep the same kernel for the whole test.
        $this->client->disableReboot();
        $this->clearRateLimiter();
    }

    protected function tearDown(): void
    {
        $this->clearRateLimiter();
        parent::tearDown();
    }

    public function testTheLoginPageHasNoSidebarAndKeepsTheSharedHead(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name=_username][type=email]');
        self::assertSelectorExists('form input[name=_password][type=password]');
        self::assertSelectorExists('form input[name=_csrf_token]');
        self::assertSelectorNotExists('[data-slot=sidebar]');
        self::assertSelectorNotExists('a[href$="/readings"]');
        self::assertSelectorExists('link[rel=stylesheet][href*="app"]');
        self::assertSelectorExists('script[type=importmap]');
        self::assertSelectorExists('[data-controller~="theme"], [data-controller*="theme"]');
    }

    public function testValidCredentialsReachTheLandingPage(): void
    {
        $this->createAccount('login-ok@example.test');

        $this->login('login-ok@example.test', self::PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-slot=sidebar-footer]', 'login-ok@example.test');
    }

    public function testTheEmailIsMatchedWithoutCaseOrSpaces(): void
    {
        $this->createAccount('case@example.test');

        $this->login('  CASE@Example.test ', self::PASSWORD);

        self::assertResponseRedirects('/');
    }

    public function testAWrongPasswordShowsATranslatedErrorAndKeepsTheEmail(): void
    {
        $this->createAccount('wrong@example.test');

        $this->login('wrong@example.test', 'not the password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Invalid credentials.');
        self::assertInputValueSame('_username', 'wrong@example.test');
        self::assertSelectorNotExists('[data-slot=sidebar]');
    }

    public function testAnUnknownEmailGetsTheSameError(): void
    {
        $this->createAccount('known@example.test');

        $this->login('unknown@example.test', self::PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role=alert]', 'Invalid credentials.');
    }

    public function testTheErrorIsTranslatedInFrench(): void
    {
        $this->createAccount('fr-error@example.test');

        $this->login('fr-error@example.test', 'not the password', ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        $this->client->followRedirect();

        self::assertSelectorExists('html[lang=fr]');
        self::assertSelectorTextContains('[role=alert]', 'Identifiants invalides.');
        self::assertSelectorTextContains('button[type=submit]', 'Se connecter');
    }

    public function testALoginWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->createAccount('csrf@example.test');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'csrf@example.test', '_password' => self::PASSWORD], serverParameters: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Invalid CSRF token.');
    }

    public function testTheSixthFailedAttemptIsThrottledEvenWithTheRightPassword(): void
    {
        $this->createAccount('throttle@example.test');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('throttle@example.test', 'not the password');
            self::assertResponseRedirects('/login');
            $this->client->followRedirect();
            self::assertSelectorTextContains('[role=alert]', 'Invalid credentials.');
        }

        $this->login('throttle@example.test', self::PASSWORD);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        // The limiter rounds the wait up, so a test straddling a second boundary reads 16.
        self::assertMatchesRegularExpression('/Too many failed login attempts, please try again in 1[56] minutes\./', $this->client->getCrawler()->filter('[role=alert]')->text());
    }

    public function testThrottlingIsPerEmail(): void
    {
        $this->createAccount('other@example.test');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('someone-else@example.test', 'not the password');
        }

        $this->login('other@example.test', self::PASSWORD);
        self::assertResponseRedirects('/');
    }

    public function testAnonymousVisitorsGoToTheLoginThenToTheirPage(): void
    {
        $owner = $this->createAccount('target@example.test');
        $vehicle = new Vehicle(new Vin('5YJ3E7EB2NF000070'), 'Model 3', $owner);
        self::getContainer()->get(EntityManagerInterface::class)->persist($vehicle);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $path = \sprintf('/vehicles/%s/readings', $vehicle->getId()->toBase32());

        $this->client->request('GET', $path);
        self::assertResponseRedirects('http://localhost/login');

        $this->client->followRedirect();
        $this->client->submitForm('Sign in', ['_username' => 'target@example.test', '_password' => self::PASSWORD], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('http://localhost' . $path);
    }

    public function testASignedInUserDoesNotSeeTheLoginPage(): void
    {
        $this->createAccount('again@example.test');
        $this->login('again@example.test', self::PASSWORD);

        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->createAccount('logout@example.test');
        $this->login('logout@example.test', self::PASSWORD);
        $this->client->followRedirect();
        $logout = $this->client->getCrawler()->filter('a[href^="/logout"]');
        self::assertCount(1, $logout);
        self::assertStringContainsString('_csrf_token=', (string) $logout->attr('href'));

        $this->client->request('GET', (string) $logout->attr('href'), server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('http://localhost/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testLogoutWithoutTheCsrfTokenKeepsTheSession(): void
    {
        $this->createAccount('keep@example.test');
        $this->login('keep@example.test', self::PASSWORD);

        $this->client->request('GET', '/logout', server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/']);

        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testTheUsersLocaleWinsAfterTheLoginAndTheSwitcherSavesIt(): void
    {
        $user = $this->createAccount('locale@example.test');
        $user->changeLocale('fr');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->login('locale@example.test', self::PASSWORD, ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        $this->client->followRedirect();
        self::assertSelectorExists('html[lang=fr]');

        // Switching to English is kept for the user, not only in the cookie.
        $this->client->request('GET', '/locale/en', ['redirect' => '/']);
        $this->client->followRedirect();
        self::assertSelectorExists('html[lang=en]');
        $stored = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'locale@example.test']);
        self::assertSame('en', $stored?->getLocale());
    }

    /**
     * @param array<string, string> $server
     */
    private function login(string $email, string $password, array $server = []): void
    {
        $this->client->request('GET', '/login', server: $server);
        $this->client->submitForm($this->submitLabel($server), ['_username' => $email, '_password' => $password], serverParameters: $server + ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * @param array<string, string> $server
     */
    private function submitLabel(array $server): string
    {
        return 'fr' === ($server['HTTP_ACCEPT_LANGUAGE'] ?? null) ? 'Se connecter' : 'Sign in';
    }

    private function clearRateLimiter(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function createAccount(string $email): User
    {
        return self::getContainer()->get(UserCreator::class)->create(new NewUser($email, self::PASSWORD));
    }
}

<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Controller\TeslaConnectionController;
use Polaris\Tesla\Controller\TeslaSettingsController;
use Polaris\Tesla\Domain\ConnectionStatus;
use Polaris\Tesla\Entity\OAuthToken;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Form\ConsentType;
use Polaris\Tesla\Service\RedirectUriProvider;
use Polaris\Tests\Support\SignsIn;
use Polaris\Tests\Support\TeslaFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(TeslaConnectionController::class)]
#[CoversClass(TeslaSettingsController::class)]
#[CoversClass(ConsentType::class)]
final class TeslaConnectionControllerTest extends WebTestCase
{
    use SignsIn;
    use TeslaFixtures;

    private const string EU_REGION = '{"response":{"region":"eu","fleet_api_base_url":"https://fleet-api.prd.eu.vn.cloud.tesla.com"}}';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    /** @var list<MockResponse> */
    private array $responses = [];

    /** @var list<string> the URL of every HTTP call to Tesla */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->set('http_client', new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $this->calls[] = $url;

            return array_shift($this->responses) ?? new MockResponse('unexpected call', ['http_code' => 500]);
        }));
    }

    public function testTheConsentComesBeforeAnyConnection(): void
    {
        $user = self::signIn($this->client);
        self::createConfiguredApp($user, false);

        $this->client->request('GET', '/settings/tesla/connect');

        self::assertResponseRedirects('/settings/tesla/consent');
    }

    public function testTheConsentBoxIsNotTickedAndTheTextSaysWhatIsReadStoredAndHowToDisconnect(): void
    {
        self::createConfiguredApp(self::signIn($this->client), false);

        $this->client->request('GET', '/settings/tesla/consent');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type=checkbox][name="consent[agree]"]');
        self::assertSelectorNotExists('input[type=checkbox][name="consent[agree]"][checked]');
        self::assertSelectorTextContains('#consent', 'odometer');
        self::assertSelectorTextContains('#consent', 'encrypted');
        self::assertSelectorTextContains('#consent', 'Disconnect');
    }

    public function testTheConsentIsRefusedWhenTheBoxIsNotTicked(): void
    {
        self::createConfiguredApp(self::signIn($this->client), false);

        $this->submitConsent(false);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-slot=field-error]', 'Tick the box to give your consent.');
        $this->entityManager->clear();
        self::assertFalse($this->app()->hasConsent());
    }

    public function testTheConsentIsStoredWhenTheBoxIsTicked(): void
    {
        self::createConfiguredApp(self::signIn($this->client), false);

        $this->submitConsent(true);

        self::assertResponseRedirects('/settings/tesla/connect');
        $this->entityManager->clear();
        $consent = $this->app()->getConsentGivenAt();
        self::assertInstanceOf(CarbonImmutable::class, $consent);
        self::assertSame('UTC', $consent->getTimezone()->getName());
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testTheConsentPageIsTranslatedToFrench(): void
    {
        self::createConfiguredApp(self::signIn($this->client), false);
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');

        $this->client->request('GET', '/settings/tesla/consent');

        self::assertSelectorTextContains('h1', 'Connecter Tesla');
        self::assertSelectorTextContains('#consent', 'compteur kilométrique');
    }

    public function testTheConnectPageOffersTheThreeWaysAndShowsTheRedirectUri(): void
    {
        self::createConfiguredApp(self::signIn($this->client));

        $this->client->request('GET', '/settings/tesla/connect');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#authorize form[action="/settings/tesla/connect/authorize"]');
        self::assertSelectorExists('#paste-callback input[name="paste_callback[url]"]');
        self::assertSelectorExists('#paste-token input[type=password][name="paste_refresh_token[refreshToken]"]');
        self::assertSelectorTextContains('#redirect-uri', $this->redirectUri());
    }

    public function testAuthorizingWithdrawnConsentSendsTheUserBackToTheConsent(): void
    {
        $app = self::createConfiguredApp(self::signIn($this->client));
        $crawler = $this->client->request('GET', '/settings/tesla/connect');
        $form = $crawler->filter('#authorize form')->form();
        $app->withdrawConsent();
        $this->entityManager->flush();

        $this->client->submit($form);

        self::assertResponseRedirects('/settings/tesla/consent');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-slot=alert]', 'Give your consent before connecting Tesla.');
    }

    public function testAuthorizingSendsTheBrowserToTeslaWithTheScopesAndAState(): void
    {
        self::createConfiguredApp(self::signIn($this->client));

        $this->authorize();

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://auth.tesla.com/oauth2/v3/authorize?', $location);
        $query = self::queryOf($location);
        self::assertSame('code', $query['response_type']);
        self::assertSame('fake-client-id', $query['client_id']);
        self::assertSame('openid offline_access vehicle_device_data vehicle_cmds', $query['scope']);
        self::assertSame($this->redirectUri(), $query['redirect_uri']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['state']);
    }

    public function testReconnectingAsksTeslaOnlyForTheMissingScopesAndKeepsAState(): void
    {
        self::createConfiguredApp(self::signIn($this->client));

        $this->client->request('GET', '/settings/tesla/connect/reauthorize');

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://auth.tesla.com/oauth2/v3/authorize?', $location);
        $query = self::queryOf($location);
        self::assertSame('true', $query['prompt_missing_scopes']);
        self::assertSame('openid offline_access vehicle_device_data vehicle_cmds', $query['scope']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['state']);

        $this->responses = [$this->tokens('cb-access', 'cb-refresh'), new MockResponse(self::EU_REGION)];
        $this->client->request('GET', '/tesla/callback?code=fake-code&state=' . $query['state']);
        self::assertResponseRedirects('/settings/tesla');
    }

    public function testReconnectingWithoutConsentGoesToTheConsent(): void
    {
        self::createConfiguredApp(self::signIn($this->client), false);

        $this->client->request('GET', '/settings/tesla/connect/reauthorize');

        self::assertResponseRedirects('/settings/tesla/consent');
    }

    public function testTheCallbackConnectsWhenTheStateMatches(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $state = $this->authorize();
        $this->responses = [$this->tokens('cb-access', 'cb-refresh'), new MockResponse(self::EU_REGION)];

        $this->client->request('GET', '/tesla/callback?code=fake-code&state=' . $state);

        self::assertResponseRedirects('/settings/tesla');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-slot=alert]', 'Tesla is connected.');
        self::assertSelectorExists('[data-connection-status=connected]');
        $this->entityManager->clear();
        $token = $this->storedToken();
        self::assertSame('cb-access', self::getContainer()->get(SecretEncrypter::class)->decrypt($token->getEncryptedAccessToken()));
        self::assertSame('https://fleet-api.prd.eu.vn.cloud.tesla.com', $token->getFleetApiBaseUrl());
    }

    public function testTheCallbackRejectsAWrongState(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $this->authorize();

        $this->client->request('GET', '/tesla/callback?code=fake-code&state=not-the-state');

        self::assertResponseRedirects('/settings/tesla/connect');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-slot=alert]', 'start again');
        self::assertSame([], $this->calls);
        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testTheCallbackRejectsAMissingState(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $this->authorize();

        $this->client->request('GET', '/tesla/callback?code=fake-code');

        self::assertResponseRedirects('/settings/tesla/connect');
        self::assertSame([], $this->calls);
        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testTheCallbackRejectsAnyStateWhenNoAuthorizationWasStarted(): void
    {
        self::createConfiguredApp(self::signIn($this->client));

        $this->client->request('GET', '/tesla/callback?code=fake-code&state=anything');

        self::assertResponseRedirects('/settings/tesla/connect');
        self::assertSame([], $this->calls);
    }

    public function testTheStateCanBeUsedOnlyOnce(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $state = $this->authorize();
        $this->responses = [$this->tokens('cb-access', 'cb-refresh'), new MockResponse(self::EU_REGION)];
        $this->client->request('GET', '/tesla/callback?code=fake-code&state=' . $state);
        $calls = \count($this->calls);

        $this->client->request('GET', '/tesla/callback?code=fake-code&state=' . $state);

        self::assertResponseRedirects('/settings/tesla/connect');
        self::assertCount($calls, $this->calls);
    }

    public function testTheCallbackReportsADeniedAccess(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $state = $this->authorize();

        $this->client->request('GET', '/tesla/callback?error=access_denied&state=' . $state);

        self::assertResponseRedirects('/settings/tesla/connect');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-slot=alert]', 'Tesla did not grant the access.');
    }

    public function testAPastedCallbackUrlConnectsThroughTheSameStateCheck(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $state = $this->authorize();
        $this->responses = [$this->tokens('paste-access', 'paste-refresh'), new MockResponse(self::EU_REGION)];

        $this->submitPaste('paste_callback', ['url' => 'https://polaris.local/tesla/callback?code=fake-code&state=' . $state]);

        self::assertResponseRedirects('/settings/tesla');
        $this->entityManager->clear();
        self::assertSame('pasted_callback', $this->storedToken()->getMethod()->value);
    }

    public function testAPastedCallbackUrlWithAWrongStateIsRefused(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $this->authorize();

        $this->submitPaste('paste_callback', ['url' => 'https://polaris.local/tesla/callback?code=fake-code&state=wrong']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#paste-callback [data-slot=field-error]', 'start again');
        self::assertSame([], $this->calls);
        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testAPastedCallbackUrlWithoutAnAuthorizationInTheSessionIsRefused(): void
    {
        self::createConfiguredApp(self::signIn($this->client));

        $this->submitPaste('paste_callback', ['url' => 'https://polaris.local/tesla/callback?code=fake-code&state=anything']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->calls);
    }

    public function testAPastedTextThatIsNotAUrlIsRefused(): void
    {
        self::createConfiguredApp(self::signIn($this->client));

        $this->submitPaste('paste_callback', ['url' => 'hello']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#paste-callback [data-slot=field-error]', 'not a callback URL');
    }

    public function testAPastedRefreshTokenConnects(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $this->responses = [$this->tokens('rt-access', 'rt-refresh'), new MockResponse(self::EU_REGION)];

        $this->submitPaste('paste_refresh_token', ['refreshToken' => 'pasted-refresh']);

        self::assertResponseRedirects('/settings/tesla');
        $this->entityManager->clear();
        self::assertSame('pasted_refresh_token', $this->storedToken()->getMethod()->value);
    }

    public function testARefusedPastedRefreshTokenShowsAClearErrorAndStoresNothing(): void
    {
        self::createConfiguredApp(self::signIn($this->client));
        $this->responses = [new MockResponse('{"error":"login_required"}', ['http_code' => 401])];

        $this->submitPaste('paste_refresh_token', ['refreshToken' => 'pasted-refresh']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#paste-token [data-slot=field-error]', 'Tesla refused it');
        self::assertStringNotContainsString('pasted-refresh', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testTheSettingsPageShowsAnExpiredConnectionAndOffersToConnectAgain(): void
    {
        $app = self::createConfiguredApp(self::signIn($this->client));
        $token = self::createToken($app, CarbonImmutable::parse('2026-10-24 12:00:00 UTC'));
        $token->markExpired();
        $this->entityManager->flush();

        $this->client->request('GET', '/settings/tesla');

        self::assertSelectorExists('[data-connection-status=expired]');
        self::assertSelectorTextContains('#connection', 'The connection has expired');
        self::assertSelectorExists('#connection a[href="/settings/tesla/connect"]');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('stored-access', $html);
        self::assertStringNotContainsString('stored-refresh', $html);
    }

    public function testDisconnectingDeletesTheTokensAndWithdrawsTheConsent(): void
    {
        $app = self::createConfiguredApp(self::signIn($this->client));
        self::createToken($app, CarbonImmutable::parse('2026-10-25 12:00:00 UTC'));
        $crawler = $this->client->request('GET', '/settings/tesla');

        $this->client->submit($crawler->filter('#connection form')->form());

        self::assertResponseRedirects('/settings/tesla');
        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(OAuthToken::class)->findAll());
        self::assertFalse($this->app()->hasConsent());
    }

    public function testAnotherUserCannotReachTheConnectionOfTheOwner(): void
    {
        $owner = self::createUser();
        $app = self::createConfiguredApp($owner);
        self::createToken($app, CarbonImmutable::parse('2026-10-25 12:00:00 UTC'));

        self::signIn($this->client, self::createUser('other@example.test'));

        foreach (['/settings/tesla/connect', '/settings/tesla/consent'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('GET', '/tesla/callback?code=c&state=s');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/settings/tesla');
        self::assertSelectorNotExists('#connection');
        self::assertCount(1, $this->entityManager->getRepository(OAuthToken::class)->findAll());
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        foreach (['/settings/tesla/connect', '/settings/tesla/consent', '/tesla/callback?code=c&state=s'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('http://localhost/login');
        }
    }

    /**
     * The default redirect URI follows DEFAULT_URI, which differs between Docker and CI.
     */
    private function redirectUri(): string
    {
        return self::getContainer()->get(RedirectUriProvider::class)->get();
    }

    private function app(): TeslaApp
    {
        $app = $this->entityManager->getRepository(TeslaApp::class)->findOneBy([]);
        self::assertInstanceOf(TeslaApp::class, $app);

        return $app;
    }

    private function storedToken(): OAuthToken
    {
        $token = $this->entityManager->getRepository(OAuthToken::class)->findOneBy([]);
        self::assertInstanceOf(OAuthToken::class, $token);
        self::assertSame(ConnectionStatus::Connected, $token->getStatus());

        return $token;
    }

    private function tokens(string $access, string $refresh): MockResponse
    {
        return new MockResponse((string) json_encode([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => 28800,
            'token_type' => 'Bearer',
        ]));
    }

    private function submitConsent(bool $agree): void
    {
        $crawler = $this->client->request('GET', '/settings/tesla/consent');
        $form = $crawler->selectButton('Continue')->form();
        $values = $form->getPhpValues();
        $fields = \is_array($values['consent'] ?? null) ? $values['consent'] : [];
        unset($fields['agree']);
        if ($agree) {
            $fields['agree'] = '1';
        }
        $values['consent'] = $fields;
        $this->client->request('POST', '/settings/tesla/consent', $values);
    }

    /**
     * Starts the authorization like a click on the button and returns the state sent to Tesla.
     */
    private function authorize(): string
    {
        $crawler = $this->client->request('GET', '/settings/tesla/connect');
        $this->client->submit($crawler->filter('#authorize form')->form());
        self::assertResponseRedirects();

        return self::queryOf((string) $this->client->getResponse()->headers->get('Location'))['state'];
    }

    /**
     * @return array<string, string>
     */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        $strings = [];
        foreach ($query as $name => $value) {
            if (\is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }

    /**
     * @param array<string, string> $values
     */
    private function submitPaste(string $formName, array $values): void
    {
        $crawler = $this->client->request('GET', '/settings/tesla/connect');
        $button = 'paste_callback' === $formName ? 'Connect with this URL' : 'Connect with this token';
        $posted = $crawler->selectButton($button)->form()->getPhpValues();
        $posted[$formName] = array_merge((array) $posted[$formName], $values);
        $this->client->request('POST', 'paste_callback' === $formName ? '/settings/tesla/connect/callback-url' : '/settings/tesla/connect/refresh-token', $posted);
    }
}

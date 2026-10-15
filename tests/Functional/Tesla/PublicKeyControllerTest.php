<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Tesla;

use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Shared\Service\SecretEncrypter;
use Polaris\Tesla\Controller\PublicKeyController;
use Polaris\Tesla\Service\TeslaKeyService;
use Polaris\Tests\Support\SignsIn;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(PublicKeyController::class)]
final class PublicKeyControllerTest extends WebTestCase
{
    use SignsIn;

    private const string URL = '/.well-known/appspecific/com.tesla.3p.public-key.pem';

    public function testIs404WithoutAKey(): void
    {
        $client = self::createClient();

        $client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(404);
    }

    public function testServesThePublicKeyAnonymouslyAndNothingElse(): void
    {
        $client = self::createClient();
        $app = self::getContainer()->get(TeslaKeyService::class)->generate(self::createUser());
        $privateKey = self::getContainer()->get(SecretEncrypter::class)->decrypt($app->getEncryptedPrivateKey());

        $client->request('GET', self::URL, [], [], ['HTTP_HOST' => 'any.example.test']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertSame($app->getPublicKey(), $body);
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $body);
        self::assertStringNotContainsString('PRIVATE', $body);
        self::assertStringNotContainsString($app->getEncryptedPrivateKey(), $body);
        self::assertStringNotContainsString(trim($privateKey), $body);
        self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertCount(0, $client->getResponse()->headers->getCookies());
        self::assertResponseNotHasHeader('Set-Cookie');
    }
}

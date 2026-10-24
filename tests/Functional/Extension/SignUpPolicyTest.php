<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Extension;

use PHPUnit\Framework\Attributes\CoversClass;
use Polaris\Account\Repository\UserRepository;
use Polaris\Account\Service\Dto\NewUser;
use Polaris\Account\Service\Exception\UserCreationRejected;
use Polaris\Account\Service\UserCreator;
use Polaris\Extension\SignUpPolicy;
use Polaris\Extension\SingleUserSignUpPolicy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(SingleUserSignUpPolicy::class)]
#[CoversClass(UserCreator::class)]
final class SignUpPolicyTest extends KernelTestCase
{
    private const string PASSWORD = 'correct horse battery';

    public function testSelfHostedDefaultAllowsASingleUser(): void
    {
        self::bootKernel();
        $creator = self::getContainer()->get(UserCreator::class);
        $creator->create(new NewUser('first@example.test', self::PASSWORD));

        $this->expectException(UserCreationRejected::class);

        $creator->create(new NewUser('second@example.test', self::PASSWORD));
    }

    public function testAReplacedPolicyDecidesWhoMaySignUp(): void
    {
        self::bootKernel();
        self::getContainer()->set(SignUpPolicy::class, new class implements SignUpPolicy {
            public function assertCanSignUp(NewUser $newUser): void
            {
            }
        });
        $creator = self::getContainer()->get(UserCreator::class);

        $creator->create(new NewUser('first@example.test', self::PASSWORD));
        $creator->create(new NewUser('second@example.test', self::PASSWORD));

        self::assertSame(2, self::getContainer()->get(UserRepository::class)->count());
    }
}

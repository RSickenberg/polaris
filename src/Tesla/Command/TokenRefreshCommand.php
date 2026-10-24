<?php

declare(strict_types=1);

namespace Polaris\Tesla\Command;

use Polaris\Account\Entity\User;
use Polaris\Tesla\Service\FleetApiError;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaConnectionService;
use Polaris\Tesla\Service\TeslaNotConnected;
use Polaris\Tesla\Service\TeslaOwnerFinder;
use Polaris\Tesla\Service\TokenProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refreshes the Tesla access token through the same locked provider the fetching code uses, to check
 * the connection by hand. It never prints a token, only the new expiry. Without --force nothing is sent
 * to Tesla unless the token is within POLARIS_TESLA_REFRESH_MARGIN seconds of expiry.
 */
#[AsCommand(name: 'polaris:tesla:token:refresh', description: 'Refresh the Tesla access token (only when due, unless --force)')]
final readonly class TokenRefreshCommand
{
    public function __construct(
        private TokenProvider $tokens,
        private TeslaConnectionService $connection,
        private TeslaAppSettings $settings,
        private TeslaOwnerFinder $owners,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Refresh even when the token is not due. A refresh token is single use, so do not repeat it needlessly')]
        bool $force = false,
        #[Option(description: 'The email of the owner. Optional when there is a single user')]
        ?string $email = null,
    ): int {
        $owner = $this->owners->find($email);

        if (!$owner instanceof User) {
            $io->error('No user found. Create one with polaris:user:create, or pass --email.');

            return Command::FAILURE;
        }

        $app = $this->settings->find($owner);

        if (null === $app) {
            $io->error('There is no Tesla key pair yet. Generate it first with polaris:tesla:keygen.');

            return Command::FAILURE;
        }

        $before = $this->connection->findToken($app)?->getRefreshedAt();

        try {
            $this->tokens->accessToken($app, $force);
        } catch (TeslaNotConnected $notConnected) {
            $io->error($notConnected->getMessage());

            return Command::FAILURE;
        } catch (FleetApiError $failure) {
            $io->error($failure->getMessage() . ' The stored tokens are unchanged.');

            return Command::FAILURE;
        }

        $token = $this->connection->findToken($app);
        $refreshed = null !== $token && $before?->getTimestamp() !== $token->getRefreshedAt()->getTimestamp();
        $io->success($refreshed ? 'Refreshed the Tesla tokens.' : 'The access token is still valid, nothing was sent to Tesla. Use --force to refresh anyway.');
        $io->definitionList(
            ['Expires at (UTC)' => $token?->getExpiresAt()->format('Y-m-d H:i:s')],
            ['Status' => $token?->getStatus()->value],
        );

        return Command::SUCCESS;
    }
}

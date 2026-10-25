<?php

declare(strict_types=1);

namespace Polaris\Tesla\Command;

use Polaris\Account\Entity\User;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Tesla\Service\TeslaAppSettings;
use Polaris\Tesla\Service\TeslaConnectionService;
use Polaris\Tesla\Service\TeslaOwnerFinder;
use Polaris\Tesla\Service\TokenProvider;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Prints the raw answer of one read-only Fleet API call, whole by default. With --redact the VIN, ids, car names,
 * tokens and location are removed, so that it can be pasted into an issue or used as a test fixture. Only GET, only the vehicle reads
 * (list, state, vehicle_data, vehicle_data_cached), no location_data, never wake_up. `data` is a billed
 * live call and answers 408 when the car is not online.
 */
#[AsCommand(name: 'polaris:tesla:debug:fetch', description: 'Print the answer of a read-only Fleet API vehicle call (--redact to remove the VIN, ids and names)')]
final readonly class DebugFetchCommand
{
    private const array ENDPOINTS = ['list' => '', 'state' => '', 'data' => '/vehicle_data', 'cached' => '/vehicle_data_cached'];
    private const array REDACTED_KEYS = ['id', 'id_s', 'vehicle_id', 'user_id', 'vin', 'display_name', 'vehicle_name', 'tokens', 'access_type', 'latitude', 'longitude', 'heading', 'drive_state', 'granular_access', 'vehicle_config'];

    public function __construct(
        private TokenProvider $tokens,
        private TeslaConnectionService $connection,
        private TeslaAppSettings $settings,
        private TeslaOwnerFinder $owners,
        private HttpClientInterface $client,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument(description: 'list | state | data | cached')]
        string $what,

        #[Argument(description: 'The VIN (not needed for "list")')]
        ?string $vin = null,

        #[Option(description: 'The email of the owner. Optional when there is a single user')]
        ?string $email = null,

        #[Option(description: 'Remove the VIN, ids, car names, tokens and location from the answer')]
        bool $redact = false,
    ): int {
        if (!isset(self::ENDPOINTS[$what]) || ('list' !== $what && null === $vin)) {
            $io->error('Use: list | state <vin> | data <vin> | cached <vin>.');

            return Command::FAILURE;
        }

        $owner = $this->owners->find($email);
        $app = $owner instanceof User ? $this->settings->find($owner) : null;
        $token = null === $app ? null : $this->connection->findToken($app);

        if (null === $app || null === $token) {
            $io->error('No user, key pair or Tesla connection found.');

            return Command::FAILURE;
        }

        try {
            $access = $this->tokens->accessToken($app);
        } catch (TeslaNotConnected|FleetApiError $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $region = FleetRegion::fromBaseUrl($token->getFleetApiBaseUrl());
        $path = '/api/1/vehicles' . ('list' === $what ? '' : '/' . rawurlencode((string) $vin)) . self::ENDPOINTS[$what];
        $response = $this->client->request('GET', $token->getFleetApiBaseUrl() . $path, [
            'auth_bearer' => $access,
            'timeout' => 15,
            'max_redirects' => 0,
        ]);

        $body = $response->getContent(false);
        $data = json_decode($body, true);
        $io->writeln(\sprintf('HTTP %d (region %s)', $response->getStatusCode(), $region->value ?? '?'));
        $text = json_encode($redact && \is_array($data) ? $this->redact($data) : ($data ?? $body), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $io->writeln($redact ? str_replace((string) $vin, 'VIN', $text) : $text);

        return Command::SUCCESS;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\in_array($key, self::REDACTED_KEYS, true)) {
                $data[$key] = '<removed>';
            } elseif (\is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}

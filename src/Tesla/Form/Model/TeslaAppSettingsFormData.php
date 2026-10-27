<?php

declare(strict_types=1);

namespace Polaris\Tesla\Form\Model;

use Polaris\Tesla\Domain\AppDomain;
use Polaris\Tesla\Domain\FleetRegion;
use Polaris\Tesla\Domain\InvalidAppDomain;
use Polaris\Tesla\Entity\TeslaApp;
use Polaris\Tesla\Service\Dto\TeslaAppSettingsData;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The Tesla app settings as the user enters them. The client secret is write-only: it is never
 * filled from the stored value, and an empty one keeps the stored secret.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class TeslaAppSettingsFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $clientId = null;

    #[Assert\Length(max: 255)]
    public ?string $clientSecret = null;

    #[Assert\NotBlank]
    public ?string $domain = null;

    /** @var list<FleetRegion> */
    #[Assert\Count(min: 1, minMessage: 'tesla.regions.required')]
    public array $regions = [];

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: TeslaApp::MAX_DAILY_WAKE_CAP, notInRangeMessage: 'tesla.wake_cap.range')]
    public ?int $dailyWakeCap = TeslaApp::DEFAULT_DAILY_WAKE_CAP;

    private bool $secretStored = false;

    public static function fromApp(TeslaApp $app): self
    {
        $data = new self();
        $data->clientId = $app->getClientId();
        $data->domain = $app->getDomain()?->toString();
        $data->regions = $app->getEnabledRegions();
        $data->dailyWakeCap = $app->getDailyWakeCap();
        $data->secretStored = null !== $app->getEncryptedClientSecret();

        return $data;
    }

    public function hasStoredSecret(): bool
    {
        return $this->secretStored;
    }

    public function toSettingsData(): TeslaAppSettingsData
    {
        return new TeslaAppSettingsData(
            (string) $this->clientId,
            $this->clientSecret,
            (string) $this->domain,
            $this->regions,
            $this->dailyWakeCap ?? TeslaApp::DEFAULT_DAILY_WAKE_CAP
        );
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->secretStored && '' === trim((string) $this->clientSecret)) {
            $context->buildViolation('tesla.client_secret.required')->atPath('clientSecret')->addViolation();
        }

        if (null !== $this->domain && '' !== trim($this->domain)) {
            try {
                AppDomain::fromString($this->domain);
            } catch (InvalidAppDomain) {
                $context->buildViolation('tesla.domain.invalid')->atPath('domain')->addViolation();
            }
        }

        foreach ($this->regions as $region) {
            if (!$region->isSupported()) {
                $context->buildViolation('tesla.region.not_supported')->atPath('regions')->addViolation();

                return;
            }
        }
    }
}

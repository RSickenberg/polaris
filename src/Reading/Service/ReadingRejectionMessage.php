<?php

declare(strict_types=1);

namespace Polaris\Reading\Service;

use Polaris\Reading\Service\Exception\ReadingRejected;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The message of a refused reading, in the user's language, with the time of the neighbouring reading in the user's
 * time zone (like every other date of the app; ICU would format it in UTC).
 */
final readonly class ReadingRejectionMessage
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param string $timeZone the IANA time zone of the owner
     */
    public function of(ReadingRejected $rejected, string $timeZone): string
    {
        $parameters = $rejected->parameters;

        if (isset($parameters['at']) && \is_int($parameters['at'])) {
            $formatter = new \IntlDateFormatter(
                $this->translator->getLocale(),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT,
                $timeZone,
            );
            $parameters['at'] = (string) $formatter->format($parameters['at']);
        }

        return $this->translator->trans($rejected->reason->value, $parameters, 'reading');
    }
}

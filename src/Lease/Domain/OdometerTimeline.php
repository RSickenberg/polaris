<?php

declare(strict_types=1);

namespace Polaris\Lease\Domain;

use Carbon\CarbonImmutable;
use Polaris\Shared\Domain\Distance;
use Polaris\Shared\Domain\OdometerSample;
use Polaris\Shared\Domain\TimeZone;

/**
 * The odometer of a lease over time: the lease start at the start odometer, then the samples, the odometer being
 * linear between two of them. The projection and the comparison with past predictions read the odometer the same way
 * from here.
 *
 * Samples may come in any order. Samples at or before the lease start are ignored. A sample after the instant given as
 * "now", an odometer lower than an earlier one (or than the start odometer), and two different odometers at the same
 * second are rejected.
 */
final readonly class OdometerTimeline
{
    /**
     * @param non-empty-array<int, int> $odometers metres by Unix timestamp, sorted, starting with the lease start
     */
    private function __construct(private array $odometers)
    {
    }

    /**
     * @param list<OdometerSample> $samples
     *
     * @throws \InvalidArgumentException when a sample is in the future, contradicts another, or goes back in odometer
     */
    public static function fromSamples(
        CarbonImmutable $start,
        Distance $startOdometer,
        array $samples,
        CarbonImmutable $now
    ): self {
        $odometers = [];

        foreach ($samples as $sample) {
            $readAt = $sample->readAt()->getTimestamp();
            $odometer = $sample->odometer()->metres();

            if ($readAt > $now->getTimestamp()) {
                throw new \InvalidArgumentException(
                    \sprintf('The odometer sample of %s is in the future.', $sample->readAt()->toIso8601String())
                );
            }

            if ($readAt <= $start->getTimestamp()) {
                continue;
            }

            if (isset($odometers[$readAt]) && $odometers[$readAt] !== $odometer) {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'Two odometer samples of %s disagree: %d m and %d m.',
                        $sample->readAt()->toIso8601String(),
                        $odometers[$readAt],
                        $odometer
                    )
                );
            }

            $odometers[$readAt] = $odometer;
        }

        ksort($odometers);
        $timeline = [$start->getTimestamp() => $startOdometer->metres()] + $odometers;
        $previous = $startOdometer->metres();

        foreach ($timeline as $readAt => $odometer) {
            if ($odometer < $previous) {
                throw new \InvalidArgumentException(
                    \sprintf(
                        'The odometer of %s (%d m) is lower than an earlier one (%d m).',
                        CarbonImmutable::createFromTimestamp($readAt, TimeZone::UTC)->toIso8601String(),
                        $odometer,
                        $previous
                    )
                );
            }

            $previous = $odometer;
        }

        return new self($timeline);
    }

    /**
     * Whether any sample falls after the lease start.
     */
    public function hasSamples(): bool
    {
        return \count($this->odometers) > 1;
    }

    /**
     * The instant of the latest sample (the lease start when there is none), as a Unix timestamp.
     */
    public function latestInstant(): int
    {
        return array_key_last($this->odometers);
    }

    /**
     * Whether the odometer is known at $instant: from the lease start to the latest sample.
     */
    public function covers(int $instant): bool
    {
        return $instant >= array_key_first($this->odometers) && $instant <= $this->latestInstant();
    }

    /**
     * The odometer in metres at $instant, interpolated linearly between the samples around it.
     *
     * @throws \InvalidArgumentException when the instant is before the lease start or after the latest sample
     */
    public function odometerAt(int $instant): float
    {
        if (!$this->covers($instant)) {
            throw new \InvalidArgumentException(\sprintf('The odometer is not known at %d.', $instant));
        }

        $beforeAt = array_key_first($this->odometers);

        foreach ($this->odometers as $readAt => $odometer) {
            if ($readAt === $instant) {
                return $odometer;
            }

            if ($readAt > $instant) {
                return $this->odometers[$beforeAt] + ($odometer - $this->odometers[$beforeAt]) * (($instant - $beforeAt)
                        / ($readAt - $beforeAt));
            }

            $beforeAt = $readAt;
        }

        throw new \LogicException('An instant covered by the timeline lies between two of its points.');
    }
}

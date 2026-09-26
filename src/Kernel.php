<?php

declare(strict_types=1);

namespace Polaris;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Polaris stores, computes and exchanges every date in UTC.
     *
     * The runtime time zone is forced at boot so that web requests, console commands
     * and Messenger workers behave the same even if php.ini says otherwise.
     */
    public const string TIMEZONE = 'UTC';

    public function boot(): void
    {
        date_default_timezone_set(self::TIMEZONE);

        parent::boot();
    }
}

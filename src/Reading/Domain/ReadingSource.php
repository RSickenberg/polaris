<?php

declare(strict_types=1);

namespace Polaris\Reading\Domain;

/**
 * Where an odometer reading comes from.
 */
enum ReadingSource: string
{
    case Manual = 'manual';
    case Api = 'api';
}

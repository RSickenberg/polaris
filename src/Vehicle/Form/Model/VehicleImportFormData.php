<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The vehicles of the Tesla account the user ticked, by VIN.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class VehicleImportFormData
{
    /**
     * @var list<string>
     */
    #[Assert\Count(min: 1, minMessage: 'vehicle.import.none_selected')]
    public array $vins = [];
}

<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Form\Model;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The export file the user chose to import into a vehicle.
 *
 * Not readonly: the Form component writes the submitted values into it.
 */
final class VehicleDataImportFormData
{
    #[Assert\NotNull(message: 'vehicle.data.file_required')]
    #[Assert\File(maxSize: '10M')]
    public ?UploadedFile $file = null;
}

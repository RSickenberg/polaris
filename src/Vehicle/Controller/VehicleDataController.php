<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Controller;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Shared\Service\Enum\ToastVariant;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Form\Model\VehicleDataImportFormData;
use Polaris\Vehicle\Form\VehicleDataImportType;
use Polaris\Vehicle\Service\Dto\VehicleDataImportResult;
use Polaris\Vehicle\Service\Enum\VehiclePermission;
use Polaris\Vehicle\Service\Exception\VehicleDataRejected;
use Polaris\Vehicle\Service\VehicleDataExporter;
use Polaris\Vehicle\Service\VehicleDataImporter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Saves the data of a vehicle to a file and brings a file back: the lease, the odometer readings and the daily snapshots.
 * See {@see VehicleDataExporter} and {@see VehicleDataImporter}.
 */
final class VehicleDataController extends AbstractController
{
    public function __construct(
        private readonly VehicleDataExporter $exporter,
        private readonly VehicleDataImporter $importer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws ORMException
     */
    // Not found rather than forbidden: another user's vehicle must not even be known to exist.
    #[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]
    #[Route('/vehicles/{id}/data', name: 'vehicle_data', methods: ['GET', 'POST'])]
    public function data(Request $request, #[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        $form = $this->createForm(VehicleDataImportType::class, new VehicleDataImportFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var VehicleDataImportFormData $data */
            $data = $form->getData();

            try {
                $result = $this->importer->import($vehicle, $data->file?->getContent() ?? '');
                $this->addFlash(ToastVariant::Success->value, $this->summary($result));

                return $this->redirectToRoute('vehicle_data', ['id' => $vehicle->getId()->toBase32()]);
            } catch (VehicleDataRejected $rejected) {
                $parameters = ['%detail%' => $rejected->getMessage()];
                $message = $this->translator->trans($rejected->reason, $parameters, 'vehicle');
                $form->get('file')->addError(new FormError($message));
            }
        }

        return $this->render('vehicle/data.html.twig', [
            'vehicle' => $vehicle,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @throws \JsonException
     */
    #[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]
    #[Route('/vehicles/{id}/data/export', name: 'vehicle_data_export', methods: ['GET'])]
    public function export(#[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        return new Response($this->exporter->export($vehicle), Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->exporter->fileName($vehicle),
            ),
        ]);
    }

    private function summary(VehicleDataImportResult $result): string
    {
        return $this->translator->trans('flash.data_imported', [
            'lease' => $result->lease->value,
            'readingsAdded' => $result->readings->added,
            'readingsAlreadyThere' => $result->readings->alreadyThere,
            'readingsRejected' => $result->readings->rejected,
            'snapshotsAdded' => $result->snapshots->added,
            'snapshotsAlreadyThere' => $result->snapshots->alreadyThere,
            'snapshotsSkipped' => $result->snapshots->skipped,
        ], 'vehicle');
    }
}

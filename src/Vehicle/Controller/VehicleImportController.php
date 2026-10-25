<?php

declare(strict_types=1);

namespace Polaris\Vehicle\Controller;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Tesla\Service\Exception\FleetApiError;
use Polaris\Tesla\Service\Exception\TeslaNotConnected;
use Polaris\Vehicle\Domain\Vin;
use Polaris\Vehicle\Form\Model\VehicleImportFormData;
use Polaris\Vehicle\Form\VehicleImportType;
use Polaris\Vehicle\Service\Dto\ImportCandidate;
use Polaris\Vehicle\Service\TeslaVehicleImporter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lists the vehicles of the user's Tesla account and imports the ones they tick. Only the list is read
 * from Tesla: no car is contacted. Vehicles are always created for the signed-in user.
 */
final class VehicleImportController extends AbstractController
{
    public const string STATUS_READY = 'ready';
    public const string STATUS_NOTHING_NEW = 'nothing_new';
    public const string STATUS_NOT_CONNECTED = 'not_connected';
    public const string STATUS_ERROR = 'error';

    public function __construct(
        private readonly TeslaVehicleImporter $importer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ORMException
     */
    // Before the routes with a vehicle id, which would read "import" as an id.
    #[Route('/vehicles/import', name: 'vehicle_import', methods: ['GET', 'POST'], priority: 10)]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        try {
            $candidates = $this->importer->candidates($user);
        } catch (TeslaNotConnected) {
            return $this->page(self::STATUS_NOT_CONNECTED);
        } catch (FleetApiError $error) {
            $this->logger->warning('Listing the Tesla vehicles failed.', ['error' => $error->getMessage()]);

            return $this->page(self::STATUS_ERROR);
        }

        $new = array_values(array_filter($candidates, static fn (ImportCandidate $candidate): bool => !$candidate->added));

        if ([] === $new) {
            return $this->page(self::STATUS_NOTHING_NEW);
        }

        $form = $this->createForm(VehicleImportType::class, new VehicleImportFormData(), ['candidates' => $new]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var VehicleImportFormData $data */
            $data = $form->getData();

            try {
                $result = $this->importer->import($user, array_map(static fn (string $vin): Vin => new Vin($vin), $data->vins));
            } catch (TeslaNotConnected) {
                return $this->page(self::STATUS_NOT_CONNECTED);
            } catch (FleetApiError $error) {
                $this->logger->warning('Importing the Tesla vehicles failed.', ['error' => $error->getMessage()]);

                return $this->page(self::STATUS_ERROR);
            }

            $this->addFlash('success', $this->translator->trans('flash.imported', ['count' => \count($result->added)], 'vehicle'));

            return $this->redirectToRoute('dashboard_home');
        }

        return $this->page(
            self::STATUS_READY,
            ['form' => $form, 'candidates' => $new],
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $status, array $parameters = [], int $code = Response::HTTP_OK): Response
    {
        return $this->render('vehicle/import.html.twig', ['status' => $status] + $parameters, new Response(status: $code));
    }
}

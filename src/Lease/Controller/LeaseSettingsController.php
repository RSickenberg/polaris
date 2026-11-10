<?php

declare(strict_types=1);

namespace Polaris\Lease\Controller;

use Doctrine\ORM\Exception\ORMException;
use Polaris\Lease\Domain\LeaseAssessment;
use Polaris\Lease\Form\DrivingHoursType;
use Polaris\Lease\Form\LeaseContractType;
use Polaris\Lease\Form\LeaseReturnType;
use Polaris\Lease\Form\Model\DrivingHoursData;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Form\Model\LeaseReturnData;
use Polaris\Lease\Service\DrivingHoursService;
use Polaris\Lease\Service\Enum\LeaseTermsRejection;
use Polaris\Lease\Service\Exception\DrivingHoursRejected;
use Polaris\Lease\Service\Exception\LeaseReturnRejected;
use Polaris\Lease\Service\Exception\LeaseTermsRejected;
use Polaris\Lease\Service\LeaseReturnService;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Shared\Service\Enum\ToastVariant;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Enum\VehiclePermission;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LeaseSettingsController extends AbstractController
{
    private const string UNDO_RETURN_CSRF_ID = 'lease_return_undo';

    public function __construct(
        private readonly LeaseTermsService $leaseTerms,
        private readonly LeaseReturnService $leaseReturns,
        private readonly DrivingHoursService $drivingHours,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Not found rather than forbidden: another user's vehicle must not even be known to exist.
    /**
     * @throws ORMException
     */
    #[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]
    #[Route('/vehicles/{id}/lease', name: 'lease_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, #[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        $contract = $this->leaseTerms->find($vehicle);
        $form = $this->createForm(
            LeaseContractType::class,
            null !== $contract ?
                LeaseContractData::fromContract($contract) :
                new LeaseContractData()
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->leaseTerms->save($vehicle, $form->getData());
                $this->addFlash(
                    ToastVariant::Success->value,
                    $this->translator->trans('flash.lease_saved', domain: 'lease'),
                );

                return $this->redirectToRoute('lease_settings', ['id' => $vehicle->getId()->toBase32()]);
            } catch (LeaseTermsRejected $rejected) {
                $form
                    ->get(LeaseTermsRejection::StartAfterReturn === $rejected->reason ? 'startDate' : 'startOdometer')
                    ->addError(new FormError($this->translator->trans($rejected->reason->value, domain: 'lease')));
            }
        }

        return $this->renderSettings($vehicle, $form, $this->returnForm($vehicle), $form->isSubmitted());
    }

    /**
     * Marks the car as returned, or moves the date of a return already made.
     *
     * @throws ORMException
     */
    #[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]
    #[Route('/vehicles/{id}/lease/return', name: 'lease_return', methods: ['POST'])]
    public function markReturned(Request $request, #[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        $data = new LeaseReturnData();
        $returnForm = $this->createForm(
            LeaseReturnType::class,
            $data,
            ['action' => $this->generateUrl('lease_return', ['id' => $vehicle->getId()->toBase32()])],
        );
        $returnForm->handleRequest($request);

        if ($returnForm->isSubmitted() && $returnForm->isValid()) {
            \assert(null !== $data->returnedOn);

            try {
                $this->leaseReturns->markReturned($vehicle, $data->returnedOn);
                $this->addFlash(
                    ToastVariant::Success->value,
                    $this->translator->trans('flash.lease_returned', domain: 'lease'),
                );

                return $this->redirectToRoute('lease_settings', ['id' => $vehicle->getId()->toBase32()]);
            } catch (LeaseReturnRejected $rejected) {
                $returnForm->get('returnedOn')->addError(
                    new FormError($this->translator->trans($rejected->reason->value, domain: 'lease'))
                );
            }
        }

        $contract = $this->leaseTerms->find($vehicle);
        $form = $this->createForm(
            LeaseContractType::class,
            null !== $contract ? LeaseContractData::fromContract($contract) : new LeaseContractData()
        );

        return $this->renderSettings($vehicle, $form, $returnForm, true);
    }

    /**
     * @throws ORMException
     */
    #[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]
    #[Route('/vehicles/{id}/lease/return/undo', name: 'lease_return_undo', methods: ['POST'])]
    public function undoReturn(Request $request, #[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        if (!$this->isCsrfTokenValid(self::UNDO_RETURN_CSRF_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->leaseReturns->undoReturn($vehicle);
        $this->addFlash(
            ToastVariant::Success->value,
            $this->translator->trans('flash.lease_return_undone', domain: 'lease'),
        );

        return $this->redirectToRoute('lease_settings', ['id' => $vehicle->getId()->toBase32()]);
    }

    /**
     * Saves the hours the owner usually does not drive, or clears them.
     *
     * @throws ORMException
     */
    #[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]
    #[Route('/vehicles/{id}/lease/driving-hours', name: 'lease_driving_hours', methods: ['POST'])]
    public function saveDrivingHours(Request $request, #[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        $data = new DrivingHoursData();
        $hoursForm = $this->createForm(
            DrivingHoursType::class,
            $data,
            ['action' => $this->generateUrl('lease_driving_hours', ['id' => $vehicle->getId()->toBase32()])],
        );
        $hoursForm->handleRequest($request);

        if ($hoursForm->isSubmitted() && $hoursForm->isValid()) {
            try {
                $this->drivingHours->save($vehicle, $data->from, $data->until, $data->todayWholeDay);
                $this->addFlash(
                    ToastVariant::Success->value,
                    $this->translator->trans('flash.hours_saved', domain: 'lease'),
                );

                return $this->redirectToRoute('lease_settings', ['id' => $vehicle->getId()->toBase32()]);
            } catch (DrivingHoursRejected $rejected) {
                $hoursForm->addError(new FormError($this->translator->trans($rejected->reason->value, domain: 'lease')));
            }
        }

        $contract = $this->leaseTerms->find($vehicle);
        $form = $this->createForm(
            LeaseContractType::class,
            null !== $contract ? LeaseContractData::fromContract($contract) : new LeaseContractData()
        );

        return $this->renderSettings($vehicle, $form, $this->returnForm($vehicle), true, $hoursForm);
    }

    /**
     * @return FormInterface<DrivingHoursData>
     */
    private function hoursForm(Vehicle $vehicle): FormInterface
    {
        $saved = $this->drivingHours->find($vehicle);
        $data = new DrivingHoursData();
        $data->from = $saved['from'];
        $data->until = $saved['until'];
        $data->todayWholeDay = $saved['todayWholeDay'];

        return $this->createForm(
            DrivingHoursType::class,
            $data,
            ['action' => $this->generateUrl('lease_driving_hours', ['id' => $vehicle->getId()->toBase32()])],
        );
    }

    /**
     * @return FormInterface<LeaseReturnData>
     */
    private function returnForm(Vehicle $vehicle): FormInterface
    {
        $data = new LeaseReturnData();
        $data->returnedOn = $this->leaseReturns->returnedOn($vehicle)?->toDateTimeImmutable();

        return $this->createForm(
            LeaseReturnType::class,
            $data,
            ['action' => $this->generateUrl('lease_return', ['id' => $vehicle->getId()->toBase32()])],
        );
    }

    /**
     * @param FormInterface<LeaseContractData>     $form
     * @param FormInterface<LeaseReturnData>       $returnForm
     * @param FormInterface<DrivingHoursData>|null $hoursForm  the form to show, the saved hours when null
     */
    private function renderSettings(
        Vehicle $vehicle,
        FormInterface $form,
        FormInterface $returnForm,
        bool $submitted,
        ?FormInterface $hoursForm = null,
    ): Response {
        $assessment = $this->leaseTerms->assess($vehicle);

        return $this->render(
            'lease/settings.html.twig',
            [
                'vehicle' => $vehicle,
                'form' => $form,
                'returnForm' => $returnForm,
                'hoursForm' => $hoursForm ?? $this->hoursForm($vehicle),
                'returnedOn' => $this->leaseReturns->returnedOn($vehicle),
                'hasContract' => null !== $this->leaseTerms->find($vehicle),
                // Null without a contract, or before the first reading of the lease.
                'assessment' => $assessment instanceof LeaseAssessment ? $assessment : null,
            ],
            new Response(status: $submitted ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK),
        );
    }
}

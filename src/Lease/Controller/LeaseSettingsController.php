<?php

declare(strict_types=1);

namespace Polaris\Lease\Controller;

use Polaris\Lease\Form\LeaseContractType;
use Polaris\Lease\Form\Model\LeaseContractData;
use Polaris\Lease\Service\LeaseTermsRejected;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Vehicle\Entity\Vehicle;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LeaseSettingsController extends AbstractController
{
    public function __construct(
        private readonly LeaseTermsService $leaseTerms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/vehicles/{id}/lease', name: 'lease_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, #[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        $contract = $this->leaseTerms->find($vehicle);
        $form = $this->createForm(LeaseContractType::class, null !== $contract ? LeaseContractData::fromContract($contract) : new LeaseContractData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->leaseTerms->save($vehicle, $form->getData());
                $this->addFlash('success', $this->translator->trans('flash.lease_saved', domain: 'lease'));

                return $this->redirectToRoute('lease_settings', ['id' => $vehicle->getId()->toBase32()]);
            } catch (LeaseTermsRejected $rejected) {
                $form->get('startOdometer')->addError(new FormError($this->translator->trans($rejected->getMessage(), domain: 'lease')));
            }
        }

        return $this->render('lease/settings.html.twig', [
            'vehicle' => $vehicle,
            'form' => $form,
            'assessment' => $this->leaseTerms->assess($vehicle),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}

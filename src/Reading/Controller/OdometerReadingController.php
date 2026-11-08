<?php

declare(strict_types=1);

namespace Polaris\Reading\Controller;

use Carbon\CarbonImmutable;
use Doctrine\ORM\Exception\ORMException;
use Polaris\Account\Entity\User;
use Polaris\Lease\Service\LeaseTermsService;
use Polaris\Reading\Domain\ReadingRejection;
use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Reading\Form\OdometerReadingType;
use Polaris\Reading\Service\Exception\ReadingRejected;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Shared\Service\Enum\ToastVariant;
use Polaris\Vehicle\Entity\Vehicle;
use Polaris\Vehicle\Service\Enum\VehiclePermission;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OdometerReadingController extends AbstractController
{
    public function __construct(
        private readonly OdometerReadingService $readings,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly LeaseTermsService $leaseTerms,
    ) {
    }

    // Not found rather than forbidden: another user's vehicle must not even be known to exist.
    #[IsGranted(VehiclePermission::Manage->value, 'vehicle', statusCode: Response::HTTP_NOT_FOUND)]
    #[Route('/vehicles/{id}/readings', name: 'reading_history', methods: ['GET', 'POST'])]
    /**
     * @throws ORMException
     */
    public function history(Request $request, #[MapEntity(id: 'id')] Vehicle $vehicle, #[CurrentUser] User $user): Response
    {
        $data = new OdometerReadingData();
        $data->readAt = CarbonImmutable::instance($this->clock->now())->startOfMinute();

        $form = $this->createForm(OdometerReadingType::class, $data, ['time_zone' => $user->getTimeZone()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->readings->recordManual($vehicle, $data);

                // Kept, as manual entry always works, but the lease is over: the projection ignores it.
                $returnedAt = $this->leaseTerms->returnedAt($vehicle);
                $returnedOn = $this->leaseTerms->returnedOn($vehicle);

                if (null !== $returnedAt && null !== $returnedOn && $data->readAt->getTimestamp() > $returnedAt->getTimestamp()) {
                    $this->addFlash(ToastVariant::Warning->value, $this->translator->trans('flash.reading_added_after_return', ['on' => $returnedOn], 'reading'));
                } else {
                    $this->addFlash(ToastVariant::Success->value, $this->translator->trans('flash.reading_added', domain: 'reading'));
                }

                return $this->redirectToRoute('reading_history', ['id' => $vehicle->getId()->toBase32()]);
            } catch (ReadingRejected $rejected) {
                $field = \in_array($rejected->reason, [ReadingRejection::DuplicateInstant, ReadingRejection::InFuture], true) ? 'readAt' : 'odometer';
                $form->get($field)->addError(new FormError($this->translator->trans($rejected->reason->value, $rejected->parameters, 'reading')));
            }
        }

        return $this->render('reading/history.html.twig', [
            'vehicle' => $vehicle,
            'readings' => $this->readings->history($vehicle),
            'form' => $form,
            'timeZone' => $user->getTimeZone(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}

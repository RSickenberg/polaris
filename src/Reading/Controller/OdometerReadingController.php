<?php

declare(strict_types=1);

namespace Polaris\Reading\Controller;

use Carbon\CarbonImmutable;
use Polaris\Reading\Domain\ReadingRejection;
use Polaris\Reading\Form\Model\OdometerReadingData;
use Polaris\Reading\Form\OdometerReadingType;
use Polaris\Reading\Service\OdometerReadingService;
use Polaris\Reading\Service\ReadingRejected;
use Polaris\Shared\Domain\TimeZone;
use Polaris\Vehicle\Entity\Vehicle;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OdometerReadingController extends AbstractController
{
    public function __construct(
        private readonly OdometerReadingService $readings,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/vehicles/{id}/readings', name: 'reading_history', methods: ['GET', 'POST'])]
    public function history(Request $request, #[MapEntity(id: 'id')] Vehicle $vehicle): Response
    {
        $data = new OdometerReadingData();
        $data->readAt = CarbonImmutable::instance($this->clock->now())->startOfMinute();

        $form = $this->createForm(OdometerReadingType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->readings->recordManual($vehicle, $data);
                $this->addFlash('success', 'The reading was added.');

                return $this->redirectToRoute('reading_history', ['id' => $vehicle->getId()->toBase32()]);
            } catch (ReadingRejected $rejected) {
                $field = \in_array($rejected->reason, [ReadingRejection::DuplicateInstant, ReadingRejection::InFuture], true) ? 'readAt' : 'odometer';
                $form->get($field)->addError(new FormError($this->translator->trans($rejected->reason->value, $rejected->parameters)));
            }
        }

        return $this->render('reading/history.html.twig', [
            'vehicle' => $vehicle,
            'readings' => $this->readings->history($vehicle),
            'form' => $form,
            // Always UTC until the user's time zone exists (#21): then it is read from the user here.
            'timeZone' => TimeZone::UTC,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\ResourceBooking;
use App\Entity\Teacher;
use App\Repository\IssueRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Las incidencias asociadas a una reserva que puede ver el usuario actual (el docente de la reserva, los responsables del
 * tipo de la incidencia y la dirección). Carga de una vez las del centro para que el calendario y los listados, que
 * preguntan por muchas reservas, no hagan una consulta por cada una.
 */
final class BookingIssues
{
    /** @var array<string, array<string, list<Issue>>> incidencias con reserva, por centro y por reserva */
    private array $byCentre = [];

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly IssueAccess $access,
        private readonly FeatureToggle $features,
        private readonly Security $security,
    ) {}

    /** @return list<Issue> de la más antigua a la más reciente */
    public function visibleFor(ResourceBooking $booking): array
    {
        $viewer = $this->security->getUser();
        $centre = $booking->getResource()->getCategory()->getEducationalCentre();
        if (!$viewer instanceof Teacher || !$this->features->isEnabledForCentre(Feature::Issues, $centre)) {
            return [];
        }

        $key = $centre->getId()->toRfc4122();
        if (!isset($this->byCentre[$key])) {
            $this->byCentre[$key] = [];
            foreach ($this->issues->findWithBooking($centre) as $issue) {
                $bookingId = $issue->getBooking()?->getId()->toRfc4122();
                if ($bookingId !== null) {
                    $this->byCentre[$key][$bookingId][] = $issue;
                }
            }
        }

        return array_values(array_filter(
            $this->byCentre[$key][$booking->getId()->toRfc4122()] ?? [],
            fn (Issue $issue): bool => $this->access->canView($viewer, $issue),
        ));
    }

    /** Alguna de ellas sigue abierta. */
    public function hasOpen(ResourceBooking $booking): bool
    {
        foreach ($this->visibleFor($booking) as $issue) {
            if ($issue->isOpen()) {
                return true;
            }
        }

        return false;
    }
}

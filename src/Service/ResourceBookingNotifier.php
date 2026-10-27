<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ResourceBooking;
use App\Entity\Teacher;

/**
 * Avisos por correo de las reservas con validación: a quien valida cuando llega una solicitud (los responsables de la
 * categoría o, si no tiene, la dirección del centro) y al docente cuando se la aprueban o rechazan. Las reservas de
 * una misma serie se agrupan en un solo aviso. La campana y el panel de inicio se calculan aparte, a partir del estado
 * de las reservas.
 */
final class ResourceBookingNotifier
{
    public function __construct(
        private readonly IncidentEmailNotifier $emails,
    ) {}

    /**
     * @param list<ResourceBooking> $bookings reservas recién pendientes de validar
     */
    public function requested(array $bookings, Teacher $requester): void
    {
        foreach ($this->grouped($bookings) as $group) {
            $category = $group[0]->getResource()->getCategory();

            /** @var array<string, Teacher> $recipients */
            $recipients = [];
            foreach ($category->getManagers() as $manager) {
                $recipients[$manager->getId()->toRfc4122()] = $manager;
            }
            if ($recipients === []) {
                foreach ($category->getEducationalCentre()->getAdmins() as $admin) {
                    $recipients[$admin->getId()->toRfc4122()] = $admin;
                }
            }
            unset($recipients[$requester->getId()->toRfc4122()]);

            foreach ($recipients as $recipient) {
                $this->emails->bookingsRequested($recipient, $group, $requester);
            }
        }
    }

    /**
     * @param list<ResourceBooking> $resolved reservas aprobadas o rechazadas en esta operación
     */
    public function resolved(array $resolved, Teacher $resolver): void
    {
        foreach ($this->grouped($resolved) as $group) {
            $teacher = $group[0]->getTeacher();
            if ($teacher->getId()->equals($resolver->getId())) {
                continue;
            }

            $this->emails->bookingsResolved($teacher, $group, $resolver, $group[0]->isApproved());
        }
    }

    /**
     * Agrupa por serie (o por reserva suelta) y por estado.
     *
     * @param list<ResourceBooking> $bookings
     * @return list<non-empty-list<ResourceBooking>>
     */
    private function grouped(array $bookings): array
    {
        $groups = [];
        foreach ($bookings as $booking) {
            $key = ($booking->getSeriesId()?->toRfc4122() ?? $booking->getId()->toRfc4122()) . '|' . $booking->getStatus()->value;
            $groups[$key][] = $booking;
        }

        return array_values($groups);
    }
}

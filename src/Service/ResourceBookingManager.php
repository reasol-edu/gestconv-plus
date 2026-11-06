<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\Teacher;
use App\Repository\ResourceBookingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Altas, cambios, bajas y validación de reservas, con el estado que corresponde a cada operación. */
final class ResourceBookingManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResourceBookingPlanner $planner,
        private readonly ResourceBookingRepository $bookings,
        private readonly ResourceBookingNotifier $notifier,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * Crea una reserva por fecha. Varias fechas forman una serie periódica.
     *
     * @param list<\DateTimeImmutable> $dates fechas ya comprobadas (libres)
     * @return list<ResourceBooking>
     */
    public function create(AcademicYear $year, BookingInput $input, Teacher $actor, array $dates): array
    {
        $resource = $input->resource;
        $teacher  = $input->teacher;
        if ($resource === null || $teacher === null || $dates === []) {
            return [];
        }

        $status   = $this->planner->initialStatus($resource, $actor);
        $seriesId = count($dates) > 1 ? Uuid::v7() : null;

        $created = [];
        foreach ($dates as $date) {
            $booking = (new ResourceBooking())
                ->setAcademicYear($year)
                ->setResource($resource)
                ->setTeacher($teacher)
                ->setDate($date)
                ->setTimeSlots($input->slots)
                ->setGroups($input->groups)
                ->setDescription($input->description)
                ->setStatus($status)
                ->setSeriesId($seriesId);
            $this->em->persist($booking);
            $created[] = $booking;
        }
        $this->em->flush();

        foreach ($created as $booking) {
            $this->activityLog->log('resource_booking.created', $this->logData($booking));
        }

        if ($status === ResourceBookingStatus::Pending) {
            $this->notifier->requested($created, $actor);
        }

        return $created;
    }

    /**
     * Aplica los cambios del formulario a una reserva. Si `$changeDate` es falso se respeta su fecha (edición de
     * toda una serie). Devuelve si el cambio la devuelve a pendiente de validación.
     */
    public function update(ResourceBooking $booking, BookingInput $input, Teacher $actor, bool $changeDate = true): bool
    {
        $resource = $input->resource;
        $teacher  = $input->teacher;
        if ($resource === null || $teacher === null) {
            return false;
        }

        $newDate = $changeDate && $input->date !== null ? $input->date : $booking->getDate();
        $moved   = !$resource->getId()->equals($booking->getResource()->getId())
            || $newDate->format('Y-m-d') !== $booking->getDate()->format('Y-m-d')
            || $this->slotIds($booking->getTimeSlots()->toArray()) !== $this->slotIds($input->slots);

        $booking
            ->setResource($resource)
            ->setTeacher($teacher)
            ->setDate($newDate)
            ->setTimeSlots($input->slots)
            ->setGroups($input->groups)
            ->setDescription($input->description);

        $backToPending = false;
        $initial       = $this->planner->initialStatus($resource, $actor);
        if ($booking->isRejected()) {
            // Corregir una reserva rechazada la presenta de nuevo.
            $booking->setStatus($initial)->setRejectionReason(null)->setResolutionSeen(true);
            $backToPending = $initial === ResourceBookingStatus::Pending;
        } elseif ($moved && $booking->isApproved() && $initial === ResourceBookingStatus::Pending) {
            $booking->setStatus(ResourceBookingStatus::Pending)->setResolutionSeen(true);
            $backToPending = true;
        }

        $this->em->flush();
        $this->activityLog->log('resource_booking.updated', $this->logData($booking));

        if ($backToPending) {
            $this->notifier->requested([$booking], $actor);
        }

        return $backToPending;
    }

    /**
     * @param list<ResourceBooking> $bookings
     */
    public function delete(array $bookings): void
    {
        foreach ($bookings as $booking) {
            $data = $this->logData($booking);
            $this->em->remove($booking);
            $this->activityLog->log('resource_booking.deleted', $data);
        }
        $this->em->flush();
    }

    /**
     * @param list<ResourceBooking> $bookings
     */
    public function approve(array $bookings, Teacher $actor): void
    {
        $resolved = [];
        foreach ($bookings as $booking) {
            if (!$booking->isPending()) {
                continue;
            }
            $booking->setStatus(ResourceBookingStatus::Approved)->setRejectionReason(null)
                ->setResolutionSeen($booking->getTeacher()->getId()->equals($actor->getId()));
            $resolved[] = $booking;
        }
        $this->em->flush();

        foreach ($resolved as $booking) {
            $this->activityLog->log('resource_booking.approved', $this->logData($booking));
        }
        $this->notifier->resolved($resolved, $actor);
    }

    /**
     * @param list<ResourceBooking> $bookings
     */
    public function reject(array $bookings, Teacher $actor, ?string $reason): void
    {
        $reason   = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;
        $resolved = [];
        foreach ($bookings as $booking) {
            if (!$booking->isPending()) {
                continue;
            }
            $booking->setStatus(ResourceBookingStatus::Rejected)->setRejectionReason($reason)
                ->setResolutionSeen($booking->getTeacher()->getId()->equals($actor->getId()));
            $resolved[] = $booking;
        }
        $this->em->flush();

        foreach ($resolved as $booking) {
            $this->activityLog->log('resource_booking.rejected', $this->logData($booking));
        }
        $this->notifier->resolved($resolved, $actor);
    }

    /**
     * Las reservas sobre las que actúa una operación «sobre toda la serie»: la propia y las siguientes de su serie que
     * no han pasado (las pasadas conservan el historial). Sin serie, solo la propia.
     *
     * @return list<ResourceBooking>
     */
    public function seriesFrom(ResourceBooking $booking): array
    {
        $seriesId = $booking->getSeriesId();
        if ($seriesId === null) {
            return [$booking];
        }

        $today  = $this->planner->today();
        $result = [];
        foreach ($this->bookings->findBySeries($seriesId) as $member) {
            if ($member->getId()->equals($booking->getId()) || $member->getDate() >= $today) {
                $result[] = $member;
            }
        }

        return $result;
    }

    /** La reserva forma parte de una serie con otras reservas (para ofrecer «solo esta» / «toda la serie»). */
    public function hasSiblings(ResourceBooking $booking): bool
    {
        $seriesId = $booking->getSeriesId();

        return $seriesId !== null && count($this->bookings->findBySeries($seriesId)) > 1;
    }

    /**
     * @param iterable<\App\Entity\TimeSlot> $slots
     * @return list<string>
     */
    private function slotIds(iterable $slots): array
    {
        $ids = [];
        foreach ($slots as $slot) {
            $ids[] = $slot->getId()->toRfc4122();
        }
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function logData(ResourceBooking $booking): array
    {
        return [
            'entityId' => $booking->getId()->toRfc4122(),
            'resource' => $booking->getResource()->getName(),
            'teacher'  => $booking->getTeacher()->getName()->full(),
            'date'     => $booking->getDate()->format('Y-m-d'),
            'status'   => $booking->getStatus()->value,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ResourceBooking;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Repository\GroupRepository;
use App\Repository\SharedResourceRepository;
use App\Repository\TeacherRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\ResourceBookingVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Lee y valida los campos del formulario de una reserva (alta y edición). */
final class ResourceBookingInputReader
{
    public function __construct(
        private readonly SharedResourceRepository $resources,
        private readonly TimeSlotRepository $timeSlots,
        private readonly GroupRepository $groups,
        private readonly TeacherRepository $teachers,
        private readonly ResourceBookingAccess $access,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {}

    /**
     * @param ResourceBooking|null $editing la reserva que se edita: su recurso y su docente actuales se aceptan aunque hayan dejado de poder elegirse
     */
    public function read(Request $request, EducationalCentre $centre, AcademicYear $year, Teacher $actor, ?ResourceBooking $editing = null, bool $allowRepeat = true): BookingInput
    {
        $errors = [];

        $resource = $this->resources->findByIdAndCentre($request->request->getString('resource'), $centre);
        if ($resource === null) {
            $errors['resource'] = 'booking.error.resource';
        } elseif (!($editing !== null && $editing->getResource()->getId()->equals($resource->getId())) && !$this->authorization->isGranted(ResourceBookingVoter::BOOK, $resource)) {
            $resource = null;
            $errors['resource'] = 'booking.error.resource_denied';
        }

        $teacher = $actor;
        $teacherId = $request->request->getString('teacher');
        if ($teacherId !== '' && $teacherId !== $actor->getId()->toRfc4122()) {
            $candidate = $this->findYearTeacher($year, $teacherId, $editing);
            if ($candidate === null || $resource === null || !$this->access->managesCategory($actor, $resource->getCategory())) {
                // Solo la dirección y los responsables de la categoría reservan a nombre de otro docente.
                if ($editing !== null && $editing->getTeacher()->getId()->toRfc4122() === $teacherId) {
                    $teacher = $editing->getTeacher();
                } else {
                    $errors['teacher'] = 'booking.error.teacher';
                }
            } else {
                $teacher = $candidate;
            }
        }

        $date = $this->parseDate($request->request->getString('date'));
        if ($date === null) {
            $errors['date'] = 'booking.error.date';
        }

        $slots = [];
        foreach ($request->request->all('slots') as $slotId) {
            $slot = is_string($slotId) ? $this->timeSlots->findByAcademicYearAndId($year, $slotId) : null;
            if ($slot instanceof TimeSlot) {
                $slots[$slot->getId()->toRfc4122()] = $slot;
            }
        }
        if ($slots === []) {
            $errors['slots'] = 'booking.error.slots';
        }

        $availableGroups = [];
        foreach ($this->groups->findByActiveYearOfCentreOrderedByName($centre, $year) as $group) {
            $availableGroups[$group->getId()->toRfc4122()] = $group;
        }
        $groups = [];
        foreach ($request->request->all('groups') as $groupId) {
            if (is_string($groupId) && isset($availableGroups[$groupId])) {
                $groups[$groupId] = $availableGroups[$groupId];
            }
        }

        $description = trim($request->request->getString('description'));

        $repeat = $allowRepeat && $request->request->getBoolean('repeat');
        $until  = null;
        if ($repeat) {
            $until = $this->parseDate($request->request->getString('repeat_until'));
            if ($until === null || ($date !== null && $until < $date)) {
                $errors['repeat_until'] = 'booking.error.repeat_until';
            }
        }

        return new BookingInput(
            $resource,
            $teacher,
            $date,
            array_values($slots),
            array_values($groups),
            $description === '' ? null : mb_substr($description, 0, 2000),
            $repeat,
            $until,
            $errors,
        );
    }

    private function findYearTeacher(AcademicYear $year, string $teacherId, ?ResourceBooking $editing): ?Teacher
    {
        foreach ($this->teachers->findByAcademicYearOrderedByName($year) as $teacher) {
            if ($teacher->getId()->toRfc4122() === $teacherId) {
                return $teacher;
            }
        }

        return $editing !== null && $editing->getTeacher()->getId()->toRfc4122() === $teacherId ? $editing->getTeacher() : null;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }
}

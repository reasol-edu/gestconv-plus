<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\ResourceCategory;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Entity\TimeSlot;

/**
 * Mundo de pruebas de las reservas: un centro con un curso activo, un grupo, un administrador, tres docentes del
 * curso (uno de ellos responsable de la categoría), una categoría con un recurso y dos tramos de cada día laborable.
 * Lo usan los tests que extienden ControllerTestCase (necesitan persist()).
 */
trait ResourceBookingScenarioTrait
{
    /**
     * @return array{admin: Teacher, owner: Teacher, other: Teacher, manager: Teacher, centre: EducationalCentre, year: AcademicYear, group: Group, category: ResourceCategory, resource: SharedResource, slots: array<int, list<TimeSlot>>}
     */
    private function makeBookingWorld(bool $requiresApproval = false): array
    {
        $suffix = uniqid('', false);

        $admin   = (new Teacher(new PersonName('Admin', 'Reservas')))->setUsername('admin.book.' . $suffix)->setAdmin(true);
        $owner   = (new Teacher(new PersonName('Ana', 'Propietaria')))->setUsername('owner.book.' . $suffix);
        $other   = (new Teacher(new PersonName('Otro', 'Docente')))->setUsername('other.book.' . $suffix);
        $manager = (new Teacher(new PersonName('Marta', 'Responsable')))->setUsername('manager.book.' . $suffix);

        $centre = (new EducationalCentre())->setCode('7' . substr($suffix, 0, 7))->setName('IES Reservas')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $group  = (new Group())->setName('1ºA')->setCourse($course);
        $centre->setActiveAcademicYear($year);
        foreach ([$owner, $other, $manager] as $teacher) {
            $year->addTeacher($teacher);
        }

        $category = (new ResourceCategory())
            ->setEducationalCentre($centre)
            ->setName('Dependencias')
            ->setColor('#2563eb')
            ->setRequiresApproval($requiresApproval)
            ->addManager($manager);
        $resource = (new SharedResource())->setCategory($category)->setName('Aula de informática')->setLocation('Planta 2');

        $this->persist($admin, $owner, $other, $manager, $centre, $year, $course, $group, $category, $resource);

        $slots = [];
        for ($day = 0; $day < 5; ++$day) {
            foreach ([['1ª hora', '08:15', '09:15'], ['2ª hora', '09:15', '10:15']] as [$name, $start, $end]) {
                $slot = (new TimeSlot())
                    ->setName($name)
                    ->setDayOfWeek($day)
                    ->setStartTime(new \DateTimeImmutable($start))
                    ->setEndTime(new \DateTimeImmutable($end))
                    ->setAcademicYear($year);
                $this->persist($slot);
                $slots[$day][] = $slot;
            }
        }

        return [
            'admin'    => $admin,
            'owner'    => $owner,
            'other'    => $other,
            'manager'  => $manager,
            'centre'   => $centre,
            'year'     => $year,
            'group'    => $group,
            'category' => $category,
            'resource' => $resource,
            'slots'    => $slots,
        ];
    }

    /**
     * @param list<TimeSlot> $slots
     */
    private function makeBooking(
        AcademicYear $year,
        SharedResource $resource,
        Teacher $teacher,
        string $date,
        array $slots,
        ResourceBookingStatus $status = ResourceBookingStatus::Approved,
    ): ResourceBooking {
        $booking = (new ResourceBooking())
            ->setAcademicYear($year)
            ->setResource($resource)
            ->setTeacher($teacher)
            ->setDate(new \DateTimeImmutable($date))
            ->setTimeSlots($slots)
            ->setStatus($status);
        $this->persist($booking);

        return $booking;
    }
}

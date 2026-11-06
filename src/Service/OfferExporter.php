<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\GroupTeacher;
use App\Entity\Teacher;
use App\Repository\CourseRepository;
use App\Repository\GroupRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Exportación de la oferta formativa de un curso académico: cursos y, dentro de cada uno, sus grupos con el color,
 * los tutores y las materias que imparte cada docente. Mismo formato que lee {@see OfferImporter}.
 *
 * Los docentes van como `{username, name}` (ver {@see TeacherReference}): el usuario IdEA los identifica al importar
 * en otro curso o despliegue. No se exporta el alumnado: son datos personales, propios de cada curso, que tienen su
 * propia importación.
 */
class OfferExporter
{
    public const FORMAT  = 'gestconv.offer';
    public const VERSION = 1;

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly GroupRepository $groups,
        private readonly ClockInterface $clock,
    ) {}

    /** @return array<string, mixed> */
    public function export(EducationalCentre $centre, AcademicYear $year): array
    {
        $courses = [];
        foreach ($this->courses->findByAcademicYearOrdered($year) as $course) {
            $groups = [];
            foreach ($this->groups->findByCourseOrderedByName($course) as $group) {
                $tutors = array_map(
                    static fn (Teacher $teacher): array => TeacherReference::export($teacher),
                    $group->getTutors()->toArray(),
                );
                usort($tutors, static fn (array $a, array $b): int => strcmp($a['username'], $b['username']));

                $subjects = array_map(
                    static fn (GroupTeacher $assignment): array => [
                        'subject' => $assignment->getSubject(),
                        'teacher' => TeacherReference::export($assignment->getTeacher()),
                    ],
                    $group->getTeacherAssignments()->toArray(),
                );
                usort($subjects, static fn (array $a, array $b): int => [$a['subject'], $a['teacher']['username']] <=> [$b['subject'], $b['teacher']['username']]);

                $groups[] = [
                    'name'     => $group->getName(),
                    'details'  => $group->getDetails(),
                    'color'    => $group->getColor(),
                    'tutors'   => $tutors,
                    'subjects' => $subjects,
                ];
            }

            $courses[] = [
                'name'    => $course->getName(),
                'details' => $course->getDetails(),
                'groups'  => $groups,
            ];
        }

        return [
            'format'        => self::FORMAT,
            'version'       => self::VERSION,
            'exported_at'   => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'centre'        => ['code' => $centre->getCode(), 'name' => $centre->getName()],
            'academic_year' => $year->getName(),
            'courses'       => $courses,
        ];
    }
}

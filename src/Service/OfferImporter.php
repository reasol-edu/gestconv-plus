<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\Teacher;
use App\Repository\CourseRepository;
use App\Repository\GroupRepository;
use App\Repository\SanctionTaskRepository;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importación de la oferta formativa desde el formato de {@see OfferExporter}, siempre sobre un curso académico
 * concreto (el activo).
 *
 * - Los cursos se emparejan por nombre (sin distinguir mayúsculas) dentro del curso académico, y los grupos por nombre
 *   dentro de su curso. Un grupo bajo un curso nuevo es siempre nuevo.
 * - «Sustituir» sobrescribe los elementos emparejados con los datos del fichero (los que se hayan elegido importar);
 *   «ignorar» los deja como están y crea solo lo que falta. Ninguno de los dos borra cursos ni grupos que no estén en
 *   el fichero: los grupos tienen partes, sanciones, salidas, reservas y ausencias asociados.
 * - Los docentes se buscan por su usuario (IdEA) entre los del curso académico de destino: nunca se crean ni se añaden
 *   al curso. Los que no existen allí se omiten y se informa de ello.
 * - Al sustituir las materias, se conservan las asignaciones que tienen tareas de sanción, igual que al quitarlas a mano.
 *
 * @phpstan-type OfferTutor array{username: string, name: ?string}
 * @phpstan-type OfferSubject array{subject: string, username: string, name: ?string}
 * @phpstan-type OfferGroup array{name: string, details: ?string, color: ?string, tutors: list<OfferTutor>, subjects: list<OfferSubject>}
 * @phpstan-type OfferCourse array{name: string, details: ?string, groups: list<OfferGroup>}
 * @phpstan-type OfferFile array{academic_year: ?string, centre: ?string, courses: list<OfferCourse>}
 * @phpstan-type OfferPreview array{courses: list<array{status: string, groups: list<array{status: string, tutors: list<array{username: string, name: ?string, resolved: bool}>, subjects: list<array{subject: string, username: string, name: ?string, resolved: bool}>}>}>, unresolved: array<string, ?string>}
 */
class OfferImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourseRepository $courses,
        private readonly GroupRepository $groups,
        private readonly TeacherRepository $teachers,
        private readonly SanctionTaskRepository $sanctionTasks,
    ) {}

    /**
     * Valida y normaliza el contenido de un fichero. Devuelve null si no es una oferta formativa de GestConv+.
     *
     * @return OfferFile|null
     */
    public function parse(mixed $decoded): ?array
    {
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== OfferExporter::FORMAT) {
            return null;
        }
        $version = $decoded['version'] ?? 1;
        if (!is_int($version) || $version < 1 || $version > OfferExporter::VERSION) {
            return null;
        }

        $courses = [];
        foreach ((array) ($decoded['courses'] ?? []) as $courseData) {
            if (!is_array($courseData) || ($name = $this->cleanText($courseData['name'] ?? null, 255)) === null) {
                continue;
            }

            $groups = [];
            foreach ((array) ($courseData['groups'] ?? []) as $groupData) {
                if (!is_array($groupData) || ($groupName = $this->cleanText($groupData['name'] ?? null, 255)) === null) {
                    continue;
                }

                $tutors = [];
                foreach ((array) ($groupData['tutors'] ?? []) as $reference) {
                    $username = TeacherReference::username($reference);
                    if ($username !== null) {
                        $tutors[mb_strtolower($username)] = ['username' => $username, 'name' => TeacherReference::name($reference)];
                    }
                }

                // La clave evita duplicados: un mismo docente y materia solo puede asignarse una vez.
                $subjects = [];
                foreach ((array) ($groupData['subjects'] ?? []) as $subjectData) {
                    if (!is_array($subjectData)) {
                        continue;
                    }
                    $subject  = $this->cleanText($subjectData['subject'] ?? null, 255);
                    $username = TeacherReference::username($subjectData['teacher'] ?? null);
                    if ($subject !== null && $username !== null) {
                        $subjects[mb_strtolower($username) . '|' . mb_strtolower($subject)] = [
                            'subject'  => $subject,
                            'username' => $username,
                            'name'     => TeacherReference::name($subjectData['teacher'] ?? null),
                        ];
                    }
                }

                $color = $groupData['color'] ?? null;
                $groups[] = [
                    'name'     => $groupName,
                    'details'  => $this->cleanText($groupData['details'] ?? null, 5000),
                    'color'    => is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtolower($color) : null,
                    'tutors'   => array_values($tutors),
                    'subjects' => array_values($subjects),
                ];
            }

            $courses[] = ['name' => $name, 'details' => $this->cleanText($courseData['details'] ?? null, 5000), 'groups' => $groups];
        }

        $centre = $decoded['centre'] ?? null;

        return [
            'academic_year' => $this->cleanText($decoded['academic_year'] ?? null, 255),
            'centre'        => is_array($centre) ? $this->cleanText($centre['name'] ?? null, 255) : null,
            'courses'       => $courses,
        ];
    }

    /**
     * Qué pasaría con cada curso y grupo del fichero en el curso académico de destino, y qué docentes no existen allí.
     *
     * @param OfferFile $parsed resultado de {@see parse()}
     * @return OfferPreview
     */
    public function preview(array $parsed, AcademicYear $year): array
    {
        $available  = $this->availableTeachers($year);
        $unresolved = [];
        $courses    = [];

        $existingCourses = $this->existingCourses($year);
        foreach ($parsed['courses'] as $courseData) {
            $course         = $existingCourses[mb_strtolower($courseData['name'])] ?? null;
            $existingGroups = $course !== null ? $this->existingGroups($course) : [];

            $groups = [];
            foreach ($courseData['groups'] as $groupData) {
                $tutors = [];
                foreach ($groupData['tutors'] as $tutor) {
                    $resolved = isset($available[mb_strtolower($tutor['username'])]);
                    $tutors[] = $tutor + ['resolved' => $resolved];
                    if (!$resolved) {
                        $unresolved[$tutor['username']] ??= $tutor['name'];
                    }
                }
                $subjects = [];
                foreach ($groupData['subjects'] as $subject) {
                    $resolved   = isset($available[mb_strtolower($subject['username'])]);
                    $subjects[] = $subject + ['resolved' => $resolved];
                    if (!$resolved) {
                        $unresolved[$subject['username']] ??= $subject['name'];
                    }
                }

                $groups[] = [
                    'status'   => isset($existingGroups[mb_strtolower($groupData['name'])]) ? 'exists' : 'new',
                    'tutors'   => $tutors,
                    'subjects' => $subjects,
                ];
            }

            $courses[] = ['status' => $course !== null ? 'exists' : 'new', 'groups' => $groups];
        }

        ksort($unresolved);

        return ['courses' => $courses, 'unresolved' => $unresolved];
    }

    /**
     * @param OfferFile             $parsed        resultado de {@see parse()}
     * @param list<int>             $courseIndexes cursos elegidos (por posición en el fichero)
     * @param array<int, list<int>> $groupIndexes  grupos elegidos de cada curso; elegir un grupo implica importar su curso
     */
    public function import(array $parsed, AcademicYear $year, array $courseIndexes, array $groupIndexes, OfferImportOptions $options): OfferImportResult
    {
        $available = $this->availableTeachers($year);
        $existing  = $this->existingCourses($year);
        $stats     = [
            'courses_created' => 0, 'courses_updated' => 0, 'courses_ignored' => 0,
            'groups_created' => 0, 'groups_updated' => 0, 'groups_ignored' => 0,
            'subjects_added' => 0, 'subjects_removed' => 0, 'subjects_kept' => 0,
        ];
        /** @var array<string, string> $unresolved usuario => texto para mostrar */
        $unresolved = [];

        $wanted = array_unique([...$courseIndexes, ...array_keys(array_filter($groupIndexes, static fn (array $list): bool => $list !== []))]);
        sort($wanted);

        foreach ($wanted as $courseIndex) {
            $courseData = $parsed['courses'][$courseIndex] ?? null;
            if ($courseData === null) {
                continue;
            }

            $course     = $existing[mb_strtolower($courseData['name'])] ?? null;
            $courseNew  = $course === null;
            if ($courseNew) {
                $course = (new Course())->setName($courseData['name'])->setAcademicYear($year)->setDetails($courseData['details']);
                $this->em->persist($course);
                $existing[mb_strtolower($courseData['name'])] = $course;
                $stats['courses_created']++;
            } elseif ($options->replace) {
                $course->setDetails($courseData['details']);
                $stats['courses_updated']++;
            } else {
                $stats['courses_ignored']++;
            }

            $existingGroups = $courseNew ? [] : $this->existingGroups($course);
            foreach (array_unique($groupIndexes[$courseIndex] ?? []) as $groupIndex) {
                $groupData = $courseData['groups'][$groupIndex] ?? null;
                if ($groupData === null) {
                    continue;
                }

                $group = $existingGroups[mb_strtolower($groupData['name'])] ?? null;
                if ($group !== null && !$options->replace) {
                    $stats['groups_ignored']++;

                    continue;
                }

                $groupNew = $group === null;
                if ($groupNew) {
                    $group = (new Group())->setName($groupData['name'])->setCourse($course);
                    $this->em->persist($group);
                    $existingGroups[mb_strtolower($groupData['name'])] = $group;
                    $stats['groups_created']++;
                } else {
                    $stats['groups_updated']++;
                }
                $group->setDetails($groupData['details']);

                if ($options->color) {
                    $group->setColor($groupData['color']);
                }
                if ($options->tutors) {
                    $this->syncTutors($group, $groupData['tutors'], $available, $unresolved);
                }
                if ($options->subjects) {
                    $this->syncSubjects($group, $groupData['subjects'], $available, $unresolved, !$groupNew, $stats);
                }
            }
        }

        $this->em->flush();

        ksort($unresolved);

        return new OfferImportResult(
            $stats['courses_created'], $stats['courses_updated'], $stats['courses_ignored'],
            $stats['groups_created'], $stats['groups_updated'], $stats['groups_ignored'],
            $stats['subjects_added'], $stats['subjects_removed'], $stats['subjects_kept'],
            array_values($unresolved),
        );
    }

    /**
     * Deja como tutores exactamente los del fichero que existen en el curso de destino.
     *
     * @param list<array{username: string, name: ?string}> $tutors
     * @param array<string, Teacher>                      $available
     * @param array<string, string>                       $unresolved
     */
    private function syncTutors(Group $group, array $tutors, array $available, array &$unresolved): void
    {
        $wanted = [];
        foreach ($tutors as $tutor) {
            $teacher = $available[mb_strtolower($tutor['username'])] ?? null;
            if ($teacher === null) {
                $unresolved[$tutor['username']] = $this->describe($tutor['username'], $tutor['name']);

                continue;
            }
            $wanted[$teacher->getId()->toRfc4122()] = $teacher;
        }

        foreach ($group->getTutors()->toArray() as $current) {
            if (!isset($wanted[$current->getId()->toRfc4122()])) {
                $group->removeTutor($current);
            }
        }
        foreach ($wanted as $teacher) {
            $group->addTutor($teacher);
        }
    }

    /**
     * Deja como materias las del fichero cuyo docente existe en el curso de destino. Las asignaciones actuales que no
     * están en el fichero se quitan, salvo las que tienen tareas de sanción (se conservan y se cuentan).
     *
     * @param list<array{subject: string, username: string, name: ?string}> $subjects
     * @param array<string, Teacher>                                       $available
     * @param array<string, string>                                        $unresolved
     * @param array<string, int>                                           $stats
     */
    private function syncSubjects(Group $group, array $subjects, array $available, array &$unresolved, bool $existingGroup, array &$stats): void
    {
        $wanted = [];
        foreach ($subjects as $entry) {
            $teacher = $available[mb_strtolower($entry['username'])] ?? null;
            if ($teacher === null) {
                $unresolved[$entry['username']] = $this->describe($entry['username'], $entry['name']);

                continue;
            }
            $wanted[$teacher->getId()->toRfc4122() . '|' . mb_strtolower($entry['subject'])] = [$teacher, $entry['subject']];
        }

        $current = [];
        foreach ($existingGroup ? $group->getTeacherAssignments()->toArray() : [] as $assignment) {
            $current[$assignment->getTeacher()->getId()->toRfc4122() . '|' . mb_strtolower($assignment->getSubject())] = $assignment;
        }

        foreach ($current as $key => $assignment) {
            if (isset($wanted[$key])) {
                continue;
            }
            if ($this->sanctionTasks->findByGroupTeacher($assignment) !== []) {
                $stats['subjects_kept']++;

                continue;
            }
            $group->removeTeacherAssignment($assignment);
            $stats['subjects_removed']++;
        }

        foreach ($wanted as $key => [$teacher, $subject]) {
            if (!isset($current[$key])) {
                $group->addTeacher($teacher, $subject);
                $stats['subjects_added']++;
            }
        }
    }

    /** @return array<string, Teacher> docentes del curso de destino por usuario en minúsculas */
    private function availableTeachers(AcademicYear $year): array
    {
        $available = [];
        foreach ($this->teachers->findByAcademicYearOrderedByName($year) as $teacher) {
            $available[mb_strtolower($teacher->getUsername())] = $teacher;
        }

        return $available;
    }

    /** @return array<string, Course> */
    private function existingCourses(AcademicYear $year): array
    {
        $existing = [];
        foreach ($this->courses->findByAcademicYearOrdered($year) as $course) {
            $existing[mb_strtolower($course->getName())] = $course;
        }

        return $existing;
    }

    /** @return array<string, Group> */
    private function existingGroups(Course $course): array
    {
        $existing = [];
        foreach ($this->groups->findByCourseOrderedByName($course) as $group) {
            $existing[mb_strtolower($group->getName())] = $group;
        }

        return $existing;
    }

    private function describe(string $username, ?string $name): string
    {
        return $name !== null ? sprintf('%s (%s)', $username, $name) : $username;
    }

    private function cleanText(mixed $raw, int $maxLength): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        return $raw === '' ? null : mb_substr($raw, 0, $maxLength);
    }
}

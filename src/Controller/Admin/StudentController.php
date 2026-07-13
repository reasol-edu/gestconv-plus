<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Repository\CourseRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\GroupRepository;
use App\Repository\StudentRepository;
use App\Service\ActivityLogService;
use App\Service\CsvReader;
use App\Service\EntityChangeTracker;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/estudiantes')]
class StudentController extends AbstractController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = [
        'studentId', 'details',
        'tutorName1', 'tutorEmail1', 'tutorName2', 'tutorEmail2',
        'contactPhone1', 'contactPhone1Notes',
        'contactPhone2', 'contactPhone2Notes',
        'contactPhone3', 'contactPhone3Notes',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly StudentRepository $students,
        private readonly GroupRepository $groups,
        private readonly CourseRepository $courses,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly CsvReader $csvReader,
        private readonly ActivityLogService $activityLog,
        private readonly EntityChangeTracker $changeTracker,
    ) {}

    #[Route('', name: 'app_centre_students_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/student/index.html.twig', ['centre' => $centre]);
    }

    #[Route('/nuevo', name: 'app_centre_students_new')]
    public function new(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentreWithActiveYear($centreId);
        $this->denyIfViewingPastYear($centre);
        $errors = [];
        $values = $this->emptyValues();
        $selectedGroupIds = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_student', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = $this->valuesFromRequest($request);
            $selectedGroupIds = $request->request->all('groups');

            if ($values['firstName'] === '') {
                $errors['firstName'] = $this->t('student.error.first_name_required');
            }
            if ($values['lastName'] === '') {
                $errors['lastName'] = $this->t('student.error.last_name_required');
            }
            if ($values['studentId'] === '') {
                $errors['studentId'] = $this->t('student.error.student_id_required');
            } elseif ($this->students->findByStudentId($values['studentId']) !== null) {
                $errors['studentId'] = $this->t('student.error.student_id_duplicate');
            }

            if (empty($errors)) {
                $student = new Student(new PersonName($values['firstName'], $values['lastName']));
                $student->setStudentId($values['studentId']);
                $this->applyValues($student, $values);

                $centreGroupsById = $this->indexGroupsById($centre);
                foreach ($selectedGroupIds as $groupId) {
                    if (!is_string($groupId)) {
                        continue;
                    }
                    if (isset($centreGroupsById[$groupId])) {
                        $student->addGroup($centreGroupsById[$groupId]);
                    }
                }

                $this->em->persist($student);
                $this->em->flush();

                $this->activityLog->log('student.created', [
                    'entityId'  => $student->getId()->toRfc4122(),
                    'studentId' => $student->getStudentId(),
                    'firstName' => $student->getName()->getFirstName(),
                    'lastName'  => $student->getName()->getLastName(),
                ]);

                $this->addFlash('success', $this->t('student.flash.created'));

                return $this->redirectToRoute('app_centre_students_index', ['centreId' => $centre->getId()]);
            }
        }

        return $this->render('admin/student/new.html.twig', [
            'centre'           => $centre,
            'errors'           => $errors,
            'values'           => $values,
            'availableGroups'  => $this->groups->findByActiveYearOfCentreOrderedByName($centre),
            'selectedGroupIds' => $selectedGroupIds,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_centre_students_edit')]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre  = $this->requireCentre($centreId);
        $this->denyIfViewingPastYear($centre);
        $student = $this->requireStudent($id, $centre);

        $centreGroupsById = $this->indexGroupsById($centre);

        $errors = [];
        $values = [
            'firstName'          => $student->getName()->getFirstName(),
            'lastName'           => $student->getName()->getLastName(),
            'studentId'          => $student->getStudentId(),
            'details'            => $student->getDetails() ?? '',
            'tutorName1'         => $student->getTutorName1() ?? '',
            'tutorEmail1'        => $student->getTutorEmail1() ?? '',
            'tutorName2'         => $student->getTutorName2() ?? '',
            'tutorEmail2'        => $student->getTutorEmail2() ?? '',
            'contactPhone1'      => $student->getContactPhone1() ?? '',
            'contactPhone1Notes' => $student->getContactPhone1Notes() ?? '',
            'contactPhone2'      => $student->getContactPhone2() ?? '',
            'contactPhone2Notes' => $student->getContactPhone2Notes() ?? '',
            'contactPhone3'      => $student->getContactPhone3() ?? '',
            'contactPhone3Notes' => $student->getContactPhone3Notes() ?? '',
        ];

        $selectedGroupIds = [];
        foreach ($centreGroupsById as $gId => $group) {
            if ($student->getGroups()->contains($group)) {
                $selectedGroupIds[] = $gId;
            }
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_student_' . $student->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = $this->valuesFromRequest($request);
            $selectedGroupIds = $request->request->all('groups');

            if ($values['firstName'] === '') {
                $errors['firstName'] = $this->t('student.error.first_name_required');
            }
            if ($values['lastName'] === '') {
                $errors['lastName'] = $this->t('student.error.last_name_required');
            }
            if ($values['studentId'] === '') {
                $errors['studentId'] = $this->t('student.error.student_id_required');
            } else {
                $existing = $this->students->findByStudentId($values['studentId']);
                if ($existing !== null && !$existing->getId()->equals($student->getId())) {
                    $errors['studentId'] = $this->t('student.error.student_id_duplicate');
                }
            }

            if (empty($errors)) {
                $before     = $this->changeTracker->snapshot($student, self::LOGGED_FIELDS);
                $nameBefore = ['firstName' => $student->getName()->getFirstName(), 'lastName' => $student->getName()->getLastName()];

                $student->setName(new PersonName($values['firstName'], $values['lastName']))
                        ->setStudentId($values['studentId']);
                $this->applyValues($student, $values);

                foreach ($student->getGroups()->toArray() as $group) {
                    if (isset($centreGroupsById[$group->getId()->toRfc4122()])) {
                        $student->removeGroup($group);
                    }
                }
                foreach ($selectedGroupIds as $groupId) {
                    if (!is_string($groupId)) {
                        continue;
                    }
                    if (isset($centreGroupsById[$groupId])) {
                        $student->addGroup($centreGroupsById[$groupId]);
                    }
                }

                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $student, self::LOGGED_FIELDS);

                $nameAfter = ['firstName' => $student->getName()->getFirstName(), 'lastName' => $student->getName()->getLastName()];
                if ($nameBefore !== $nameAfter) {
                    $changes['name'] = ['before' => $nameBefore, 'after' => $nameAfter];
                }

                if ($changes !== []) {
                    $this->activityLog->log('student.updated', [
                        'entityId' => $student->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('student.flash.saved'));

                return $this->redirectToRoute('app_centre_students_index', ['centreId' => $centre->getId()]);
            }
        }

        return $this->render('admin/student/edit.html.twig', [
            'centre'           => $centre,
            'student'          => $student,
            'errors'           => $errors,
            'values'           => $values,
            'availableGroups'  => array_values($centreGroupsById),
            'selectedGroupIds' => $selectedGroupIds,
        ]);
    }

    #[Route('/importar', name: 'app_centre_students_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentreWithActiveYear($centreId);
        $this->denyIfViewingPastYear($centre);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/student/import.html.twig', ['centre' => $centre]);
        }

        // ── Paso 2: confirmación de la vista previa ──────────────────────────
        if ($request->request->getString('import_confirmed') === '1') {
            if (!$this->isCsrfTokenValid('import_students_confirm', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $importId = $request->request->getString('import_id');
            $savedId  = $request->getSession()->get('student_import_id');

            if ($importId === '' || $importId !== $savedId) {
                $this->addFlash('error', $this->t('students.import.error.expired'));
                return $this->redirectToRoute('app_centre_students_import', ['centreId' => $centre->getId()]);
            }

            $path = $this->getTempImportPath($importId);
            if (!file_exists($path)) {
                $this->addFlash('error', $this->t('students.import.error.expired'));
                return $this->redirectToRoute('app_centre_students_import', ['centreId' => $centre->getId()]);
            }

            $content = (string) file_get_contents($path);
            @unlink($path);
            $request->getSession()->remove('student_import_id');

            $allowedGroupIds = [];
            foreach ($request->request->all('groups') as $gid) {
                if (is_string($gid) && $gid !== '') {
                    $allowedGroupIds[] = $gid;
                }
            }

            $year          = $centre->getActiveAcademicYear();
            $coursesByName = $this->buildCoursesByName($centre);

            // Create new courses/groups from unambiguous new groups
            foreach ($request->request->all('new_groups') as $key) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $parts = explode('|||', $key, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                [$courseName, $groupName] = $parts;
                $courseLower = mb_strtolower($courseName);
                $course = $coursesByName[$courseLower] ?? null;
                if ($course === null && $year !== null) {
                    $course = (new Course())->setName($courseName)->setAcademicYear($year);
                    $this->em->persist($course);
                    $coursesByName[$courseLower] = $course;
                }
                if ($course !== null) {
                    $group = (new Group())->setName($groupName)->setCourse($course);
                    $this->em->persist($group);
                    $allowedGroupIds[] = $group->getId()->toRfc4122();
                }
            }

            // Resolve conflict groups: user chose a course for each ambiguous group name
            $cgNames   = $request->request->all('conflict_group_names');
            $cgCourses = $request->request->all('conflict_group_courses');
            foreach (array_keys($cgNames) as $i) {
                $groupName  = is_string($cgNames[$i])                              ? trim($cgNames[$i])   : '';
                $courseName = isset($cgCourses[$i]) && is_string($cgCourses[$i]) ? trim($cgCourses[$i]) : '';
                if ($groupName === '' || $courseName === '') {
                    continue;
                }
                $courseLower = mb_strtolower($courseName);
                $course = $coursesByName[$courseLower] ?? null;
                if ($course === null && $year !== null) {
                    $course = (new Course())->setName($courseName)->setAcademicYear($year);
                    $this->em->persist($course);
                    $coursesByName[$courseLower] = $course;
                }
                if ($course !== null) {
                    $group = (new Group())->setName($groupName)->setCourse($course);
                    $this->em->persist($group);
                    $allowedGroupIds[] = $group->getId()->toRfc4122();
                }
            }
            $this->em->flush();

            $groupsByName  = $this->buildGroupsByName($centre);
            $coursesByName = $this->buildCoursesByName($centre);
            $rows          = $this->csvReader->parse($content)['rows'];
            $result        = $this->processCsvImport($rows, $groupsByName, $coursesByName, dryRun: false, allowedGroupIds: $allowedGroupIds);
            $this->em->flush();

            $this->activityLog->log('student.imported', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
            ]);

            $this->buildImportFlash($result);

            return $this->redirectToRoute('app_centre_students_index', ['centreId' => $centre->getId()]);
        }

        // ── Paso 1: subida del fichero → vista previa ────────────────────────
        if (!$this->isCsrfTokenValid('import_students', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('csv');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('students.import.error.no_file'));
            return $this->render('admin/student/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $parsed  = $this->csvReader->parse($content);

        if ($parsed['headers'] === []) {
            $this->addFlash('error', $this->t('students.import.error.empty_file'));
            return $this->render('admin/student/import.html.twig', ['centre' => $centre]);
        }

        $required = ['Estado Matrícula', 'Nº Id. Escolar', 'Primer apellido', 'Segundo apellido', 'Nombre', 'Unidad', 'Curso'];
        $missing  = $this->csvReader->findMissingColumn($parsed['headers'], $required);
        if ($missing !== null) {
            $this->addFlash('error', $this->t('students.import.error.missing_column') . ' «' . $missing . '»');
            return $this->render('admin/student/import.html.twig', ['centre' => $centre]);
        }

        $groupsByName  = $this->buildGroupsByName($centre);
        $coursesByName = $this->buildCoursesByName($centre);
        $result        = $this->processCsvImport($parsed['rows'], $groupsByName, $coursesByName, dryRun: true);

        $importId = Uuid::v4()->toRfc4122();
        $dir      = dirname($this->getTempImportPath($importId));
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->getTempImportPath($importId), $content);
        $request->getSession()->set('student_import_id', $importId);

        return $this->render('admin/student/import_preview.html.twig', [
            'centre'              => $centre,
            'importId'            => $importId,
            'created'             => $result['created'],
            'updated'             => $result['updated'],
            'skipped'             => $result['skipped'],
            'newCourses'          => $result['newCourses'],
            'existingGroupStats'  => $result['existingGroupStats'],
            'conflictGroups'      => $result['conflictGroups'],
        ]);
    }

    private function getTempImportPath(string $importId): string
    {
        return (string) $this->getParameter('kernel.project_dir') . '/var/tmp/student-imports/' . $importId . '.csv';
    }

    /**
     * @param list<array<string, string>> $rows
     * @param array<string, Group>        $groupsByName  Keyed by mb_strtolower(name)
     * @param array<string, Course>       $coursesByName Keyed by mb_strtolower(name)
     * @param list<string>|null           $allowedGroupIds RFC4122 UUIDs; null = all (dry-run)
     * @return array{
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   newCourses: array<string, array{courseName: string, isNew: bool, groups: array<string, array{key: string, groupName: string, created: int, updated: int}>}>,
     *   existingGroupStats: array<string, array{group: Group, name: string, courseName: string, created: int, updated: int}>,
     *   conflictGroups: array<string, array{groupName: string, courseNames: list<string>, created: int, updated: int}>
     * }
     */
    private function processCsvImport(
        array $rows,
        array $groupsByName,
        array $coursesByName,
        bool $dryRun,
        ?array $allowedGroupIds = null,
    ): array {
        $created            = 0;
        $updated            = 0;
        $skipped            = 0;
        $newCourses         = [];
        $existingGroupStats = [];
        $conflictGroups     = [];

        // Pre-pass: for new groups, collect all course names per group to detect conflicts
        $groupCourseMap = []; // groupLower => [courseLower => courseName]
        foreach ($rows as $row) {
            $gn = $row['Unidad'] ?? '';
            $cn = $row['Curso']  ?? '';
            if ($gn === '' || $cn === '') {
                continue;
            }
            $gl = mb_strtolower($gn);
            if (isset($groupsByName[$gl])) {
                continue; // existing group — no conflict check needed
            }
            $groupCourseMap[$gl][mb_strtolower($cn)] = $cn;
        }
        /** @var array<string, array<string, string>> $conflictGroupNames */
        $conflictGroupNames = array_filter($groupCourseMap, static fn (array $c) => count($c) > 1);

        foreach ($rows as $row) {
            if (($row['Estado Matrícula'] ?? '') !== '') {
                $skipped++;
                continue;
            }

            $studentId  = $row['Nº Id. Escolar'] ?? '';
            $firstName  = $row['Nombre'] ?? '';
            $lastName1  = $row['Primer apellido'] ?? '';
            $lastName2  = $row['Segundo apellido'] ?? '';
            $groupName  = $row['Unidad'] ?? '';
            $courseName = $row['Curso'] ?? '';

            if ($studentId === '' || $firstName === '' || $lastName1 === '') {
                $skipped++;
                continue;
            }

            $lastName = $lastName2 !== '' ? $lastName1 . ' ' . $lastName2 : $lastName1;
            $group    = $groupName  !== '' ? ($groupsByName[mb_strtolower($groupName)]   ?? null) : null;
            $course   = $courseName !== '' ? ($coursesByName[mb_strtolower($courseName)] ?? null) : null;

            // ── Categorise for preview ────────────────────────────────────────
            if ($groupName !== '' && $courseName !== '') {
                $groupLower  = mb_strtolower($groupName);
                $courseLower = mb_strtolower($courseName);

                if ($group === null) {
                    if (isset($conflictGroupNames[$groupLower])) {
                        // Same group name appears under multiple course names → user must resolve
                        if (!isset($conflictGroups[$groupLower])) {
                            $conflictGroups[$groupLower] = [
                                'groupName'   => $groupName,
                                'courseNames' => array_values($conflictGroupNames[$groupLower]),
                                'created'     => 0,
                                'updated'     => 0,
                            ];
                        }
                    } else {
                        // New group with a single unambiguous course
                        $planKey = $courseName . '|||' . $groupName;
                        if (!isset($newCourses[$courseLower])) {
                            $newCourses[$courseLower] = [
                                'courseName' => $courseName,
                                'isNew'      => $course === null,
                                'groups'     => [],
                            ];
                        }
                        if (!isset($newCourses[$courseLower]['groups'][$planKey])) {
                            $newCourses[$courseLower]['groups'][$planKey] = [
                                'key'       => $planKey,
                                'groupName' => $groupName,
                                'created'   => 0,
                                'updated'   => 0,
                            ];
                        }
                    }
                } else {
                    $gId = $group->getId()->toRfc4122();
                    if (!isset($existingGroupStats[$gId])) {
                        $existingGroupStats[$gId] = [
                            'group'      => $group,
                            'name'       => $group->getName(),
                            'courseName' => $group->getCourse()->getName(),
                            'created'    => 0,
                            'updated'    => 0,
                        ];
                    }
                }
            }

            // ── Skip logic (real run only) ────────────────────────────────────
            if (!$dryRun && $allowedGroupIds !== null) {
                if ($groupName !== '' && $group === null) {
                    $skipped++;
                    continue;
                }
                if ($group !== null && !in_array($group->getId()->toRfc4122(), $allowedGroupIds, true)) {
                    $skipped++;
                    continue;
                }
            }

            $existing = $this->students->findByStudentId($studentId);
            $isNew    = $existing === null;

            if (!$dryRun) {
                if ($isNew) {
                    $student = new Student(new PersonName($firstName, $lastName));
                    $student->setStudentId($studentId);
                    $this->em->persist($student);
                    $created++;
                } else {
                    $existing->setName(new PersonName($firstName, $lastName));
                    $updated++;
                    $student = $existing;
                }

                $col = static fn (string $name): string => $row[$name] ?? '';
                $n   = static fn (string $v): ?string   => $v !== '' ? $v : null;

                $t1first = $col('Nombre Primer tutor');
                $t1last  = trim($col('Primer apellido Primer tutor') . ' ' . $col('Segundo apellido Primer tutor'));
                if ($t1first !== '' || $t1last !== '') {
                    $student->setTutorName1($n(trim(implode(', ', array_filter([$t1last, $t1first])))));
                }
                $student->setTutorEmail1($n($col('Correo Electrónico Primer tutor')));

                $t2first = $col('Nombre Segundo tutor');
                $t2last  = trim($col('Primer apellido Segundo tutor') . ' ' . $col('Segundo apellido Segundo tutor'));
                if ($t2first !== '' || $t2last !== '') {
                    $student->setTutorName2($n(trim(implode(', ', array_filter([$t2last, $t2first])))));
                }
                $student->setTutorEmail2($n($col('Correo Electrónico Segundo tutor')));

                $student->setContactPhone1($n($col('Teléfono Primer tutor')));
                $student->setContactPhone2($n($col('Teléfono Segundo tutor')));
                $student->setContactPhone3($n($col('Teléfono')));

                $obs = $col('Observaciones de la matrícula');
                if ($obs !== '') {
                    $student->setDetails($obs);
                }

                if ($group !== null && !$student->getGroups()->contains($group)) {
                    $student->addGroup($group);
                }
            } else {
                if ($isNew) {
                    $created++;
                } else {
                    $updated++;
                }

                // Accumulate per-group student counts for preview
                if ($groupName !== '' && $courseName !== '') {
                    $planKey     = $courseName . '|||' . $groupName;
                    $groupLower  = mb_strtolower($groupName);
                    $courseLower = mb_strtolower($courseName);
                    if ($group === null) {
                        if (isset($conflictGroups[$groupLower])) {
                            $conflictGroups[$groupLower][$isNew ? 'created' : 'updated']++;
                        } elseif (isset($newCourses[$courseLower]['groups'][$planKey])) {
                            $newCourses[$courseLower]['groups'][$planKey][$isNew ? 'created' : 'updated']++;
                        }
                    } elseif (isset($existingGroupStats[$group->getId()->toRfc4122()])) {
                        $existingGroupStats[$group->getId()->toRfc4122()][$isNew ? 'created' : 'updated']++;
                    }
                }
            }
        }

        ksort($newCourses);
        ksort($conflictGroups);
        uasort($existingGroupStats, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'created'            => $created,
            'updated'            => $updated,
            'skipped'            => $skipped,
            'newCourses'         => $newCourses,
            'existingGroupStats' => $existingGroupStats,
            'conflictGroups'     => $conflictGroups,
        ];
    }

    /** @param array{created: int, updated: int, skipped: int} $result */
    private function buildImportFlash(array $result): void
    {
        $this->addFlash('success', $this->translator->trans('students.import.flash.summary', [
            '%created%' => $result['created'],
            '%updated%' => $result['updated'],
            '%skipped%' => $result['skipped'],
        ], 'admin'));
    }

    /** @return array<string, Group> Keyed by mb_strtolower(name) */
    private function buildGroupsByName(EducationalCentre $centre): array
    {
        $map = [];
        foreach ($this->groups->findByActiveYearOfCentreOrderedByName($centre) as $group) {
            $map[mb_strtolower($group->getName())] = $group;
        }

        return $map;
    }

    /** @return array<string, Course> */
    private function buildCoursesByName(EducationalCentre $centre): array
    {
        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }
        $map = [];
        foreach ($this->courses->findByAcademicYearOrdered($year) as $course) {
            $map[mb_strtolower($course->getName())] = $course;
        }

        return $map;
    }

    #[Route('/{id}/eliminar', name: 'app_centre_students_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        $centre  = $this->requireCentre($centreId);
        $this->denyIfViewingPastYear($centre);
        $student = $this->requireStudent($id, $centre);

        if (!$this->isCsrfTokenValid('delete_student_' . $student->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityId  = $student->getId()->toRfc4122();
        $studentId = $student->getStudentId();

        $this->em->remove($student);
        $this->em->flush();

        $this->activityLog->log('student.deleted', [
            'entityId'  => $entityId,
            'studentId' => $studentId,
        ]);

        $this->addFlash('success', $this->t('student.flash.deleted'));

        return $this->redirectToRoute('app_centre_students_index', ['centreId' => $centre->getId()]);
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }

    private function requireCentreWithActiveYear(string $centreId): EducationalCentre
    {
        $centre = $this->requireCentre($centreId);
        if ($centre->getActiveAcademicYear() === null) {
            throw $this->createNotFoundException('No active academic year');
        }

        return $centre;
    }

    private function denyIfViewingPastYear(EducationalCentre $centre): void
    {
        if ($this->tenantContext->isViewingNonActiveYear($centre)) {
            throw $this->createAccessDeniedException('Write operations are not allowed while viewing a non-active academic year.');
        }
    }

    private function requireStudent(string $id, EducationalCentre $centre): Student
    {
        $student = $this->students->findById($id);
        if ($student === null || !$this->students->belongsToCentre($student, $centre)) {
            throw $this->createNotFoundException();
        }

        return $student;
    }

    /** @return array<string, Group> keyed by UUID string */
    private function indexGroupsById(EducationalCentre $centre): array
    {
        $result = [];
        foreach ($this->groups->findByActiveYearOfCentreOrderedByName($centre) as $group) {
            $result[$group->getId()->toRfc4122()] = $group;
        }

        return $result;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    /** @return array<string, string> */
    private function emptyValues(): array
    {
        return [
            'firstName' => '', 'lastName' => '', 'studentId' => '', 'details' => '',
            'tutorName1' => '', 'tutorEmail1' => '',
            'tutorName2' => '', 'tutorEmail2' => '',
            'contactPhone1' => '', 'contactPhone1Notes' => '',
            'contactPhone2' => '', 'contactPhone2Notes' => '',
            'contactPhone3' => '', 'contactPhone3Notes' => '',
        ];
    }

    /** @return array<string, string> */
    private function valuesFromRequest(Request $request): array
    {
        $s = static fn(string $k) => trim($request->request->getString($k));
        return [
            'firstName'          => $s('firstName'),
            'lastName'           => $s('lastName'),
            'studentId'          => $s('studentId'),
            'details'            => $s('details'),
            'tutorName1'         => $s('tutorName1'),
            'tutorEmail1'        => $s('tutorEmail1'),
            'tutorName2'         => $s('tutorName2'),
            'tutorEmail2'        => $s('tutorEmail2'),
            'contactPhone1'      => $s('contactPhone1'),
            'contactPhone1Notes' => $s('contactPhone1Notes'),
            'contactPhone2'      => $s('contactPhone2'),
            'contactPhone2Notes' => $s('contactPhone2Notes'),
            'contactPhone3'      => $s('contactPhone3'),
            'contactPhone3Notes' => $s('contactPhone3Notes'),
        ];
    }

    /** @param array<string, string> $values */
    private function applyValues(Student $student, array $values): void
    {
        $n = static fn(string $v): ?string => $v !== '' ? $v : null;
        $student->setDetails($n($values['details']))
                ->setTutorName1($n($values['tutorName1']))
                ->setTutorEmail1($n($values['tutorEmail1']))
                ->setTutorName2($n($values['tutorName2']))
                ->setTutorEmail2($n($values['tutorEmail2']))
                ->setContactPhone1($n($values['contactPhone1']))
                ->setContactPhone1Notes($n($values['contactPhone1Notes']))
                ->setContactPhone2($n($values['contactPhone2']))
                ->setContactPhone2Notes($n($values['contactPhone2Notes']))
                ->setContactPhone3($n($values['contactPhone3']))
                ->setContactPhone3Notes($n($values['contactPhone3Notes']));
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Controller\PastYearGuardTrait;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\GroupRepository;
use App\Repository\TeacherRepository;
use App\Service\CentreTeacherImporter;
use App\Service\CsvReader;
use App\Service\TeacherImportRow;
use App\Service\TenantContext;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/docentes-curso')]
class CentreTeacherController extends AbstractController
{
    use PastYearGuardTrait;
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly TeacherRepository $teachers,
        private readonly GroupRepository $groups,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly CsvReader $csvReader,
        private readonly CentreTeacherImporter $importer,
        private readonly ClockInterface $clock,
        private readonly UploadLimits $uploadLimits,
    ) {}

    #[Route('', name: 'app_centre_teachers_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/centre_teacher/index.html.twig', ['centre' => $centre]);
    }

    #[Route('/añadir', name: 'app_centre_teachers_add', methods: ['POST'])]
    public function add(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentreWithActiveYear($centreId);
        $this->denyIfViewingPastYear($centre);

        if (!$this->isCsrfTokenValid('add_centre_teacher', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $username = trim($request->request->getString('username'));
        $teacher  = $username !== '' ? $this->teachers->findByUsername($username) : null;

        if ($teacher === null) {
            return $this->redirectToRoute('app_centre_teachers_register', [
                'centreId' => $centre->getId(),
                'username' => $username,
            ]);
        }

        $year = $centre->getActiveAcademicYear();
        if ($year !== null && !$year->getTeachers()->contains($teacher)) {
            $year->addTeacher($teacher);
            $this->em->flush();
            $this->addFlash('success', $this->t('centre_teachers.flash.added'));
        }

        return $this->redirectToRoute('app_centre_teachers_index', ['centreId' => $centre->getId()]);
    }

    #[Route('/importar', name: 'app_centre_teachers_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentreWithActiveYear($centreId);
        $this->denyIfViewingPastYear($centre);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/centre_teacher/import.html.twig', ['centre' => $centre]);
        }

        $year        = $centre->getActiveAcademicYear();
        $currentUser = $this->getUser();
        if ($year === null) {
            throw $this->createNotFoundException('No active academic year');
        }
        if (!$currentUser instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        // ── Paso 2: confirmación de la vista previa ──────────────────────────
        if ($request->request->getString('import_confirmed') === '1') {
            if (!$this->isCsrfTokenValid('import_centre_teachers_confirm', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $importId = $request->request->getString('import_id');
            $path     = $this->getTempImportPath($importId);
            if ($importId === '' || $importId !== $request->getSession()->get('teacher_import_id') || !is_file($path)) {
                $this->addFlash('error', $this->t('centre_teachers.import.error.expired'));

                return $this->redirectToRoute('app_centre_teachers_import', ['centreId' => $centre->getId()]);
            }

            $content = (string) file_get_contents($path);
            @unlink($path);
            $request->getSession()->remove('teacher_import_id');

            try {
                $parsed = $this->importer->parse($content);
            } catch (\InvalidArgumentException) {
                $this->addFlash('error', $this->t('centre_teachers.import.error.expired'));

                return $this->redirectToRoute('app_centre_teachers_import', ['centreId' => $centre->getId()]);
            }

            $plan    = $this->importer->plan($year, $parsed['rows']);
            $summary = $this->importer->apply(
                $year,
                $plan,
                $this->stringList($request->request->all('usernames')),
                $request->request->getBoolean('import_email'),
                $request->request->getBoolean('remove_missing'),
                $this->stringList($request->request->all('remove_teachers')),
                $currentUser,
            );
            $this->em->flush();

            $this->addFlash('success', $this->translator->trans('centre_teachers.import.flash.summary', [
                '%created%' => $summary['created'],
                '%added%'   => $summary['added'],
                '%emails%'  => $summary['emails'],
                '%removed%' => $summary['removed'],
                '%skipped%' => $parsed['skipped'],
            ], 'admin'));

            return $this->redirectToRoute('app_centre_teachers_index', ['centreId' => $centre->getId()]);
        }

        // ── Paso 1: subida del fichero → vista previa ────────────────────────
        if (!$this->isCsrfTokenValid('import_centre_teachers', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file    = $request->files->get('csv');
        $problem = $this->uploadLimits->fileProblem($file);
        if ($problem !== null) {
            $this->addFlash('error', $problem);

            return $this->render('admin/centre_teacher/import.html.twig', ['centre' => $centre]);
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('centre_teachers.import.error.no_file'));

            return $this->render('admin/centre_teacher/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());

        try {
            $parsed = $this->importer->parse($content);
        } catch (\InvalidArgumentException $e) {
            $missing = str_starts_with($e->getMessage(), 'missing:') ? substr($e->getMessage(), 8) : null;
            $this->addFlash('error', $missing !== null
                ? $this->t('centre_teachers.import.error.missing_column') . ' «' . $missing . '»'
                : $this->t('centre_teachers.import.error.empty_file'));

            return $this->redirectToRoute('app_centre_teachers_import', ['centreId' => $centre->getId()]);
        }

        $plan = $this->importer->plan($year, $parsed['rows']);

        // El fichero se guarda tal cual y se vuelve a leer al confirmar: lo que se aplica nunca depende
        // de datos reenviados por el formulario, solo de qué filas se marcan.
        $this->purgeStaleTempImports();
        $importId = Uuid::v4()->toRfc4122();
        $tempPath = $this->getTempImportPath($importId);
        $dir      = dirname($tempPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($tempPath, $content);
        $request->getSession()->set('teacher_import_id', $importId);

        return $this->render('admin/centre_teacher/import_preview.html.twig', [
            'centre'     => $centre,
            'importId'   => $importId,
            'plan'       => $plan,
            'skipped'    => $parsed['skipped'],
            'hasEmail'   => array_filter($plan, static fn (TeacherImportRow $r): bool => $r->email !== null) !== [],
            'candidates' => $this->importer->findRemovalCandidates($year, $plan, $currentUser),
        ]);
    }

    private function getTempImportDir(): string
    {
        return (string) $this->getParameter('kernel.project_dir') . '/var/tmp/teacher-imports';
    }

    private function getTempImportPath(string $importId): string
    {
        // El id viene del formulario: solo se admite un UUID para impedir rutas arbitrarias.
        $safe = Uuid::isValid($importId) ? $importId : '00000000-0000-0000-0000-000000000000';

        return $this->getTempImportDir() . '/' . $safe . '.csv';
    }

    /** Borra las vistas previas abandonadas hace más de una hora: el CSV contiene nombres, usuarios y correos. */
    private function purgeStaleTempImports(): void
    {
        $limit = $this->clock->now()->getTimestamp() - 3600;
        foreach (glob($this->getTempImportDir() . '/*.csv') ?: [] as $stale) {
            if (is_file($stale) && filemtime($stale) < $limit) {
                @unlink($stale);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter($values, 'is_string'));
    }

    #[Route('/importar-asignaciones', name: 'app_centre_teachers_import_assignments')]
    public function importAssignments(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentreWithActiveYear($centreId);
        $this->denyIfViewingPastYear($centre);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/centre_teacher/import_assignments.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_teacher_assignments', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file    = $request->files->get('csv');
        $problem = $this->uploadLimits->fileProblem($file);
        if ($problem !== null) {
            $this->addFlash('error', $problem);

            return $this->render('admin/centre_teacher/import_assignments.html.twig', ['centre' => $centre]);
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('centre_teachers.import_assignments.error.no_file'));

            return $this->render('admin/centre_teacher/import_assignments.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $parsed  = $this->csvReader->parse($content);

        if ($parsed['headers'] === []) {
            $this->addFlash('error', $this->t('centre_teachers.import_assignments.error.empty_file'));

            return $this->redirectToRoute('app_centre_teachers_import_assignments', ['centreId' => $centre->getId()]);
        }

        $required = ['Unidad', 'Profesor/a', 'Materia'];
        $missing  = $this->csvReader->findMissingColumn($parsed['headers'], $required);
        if ($missing !== null) {
            $this->addFlash('error', $this->t('centre_teachers.import_assignments.error.missing_column') . ' «' . $missing . '»');

            return $this->redirectToRoute('app_centre_teachers_import_assignments', ['centreId' => $centre->getId()]);
        }

        /** @var array<string, Group> $groupsByName */
        $groupsByName = [];
        foreach ($this->groups->findByActiveYearOfCentreOrderedByName($centre) as $group) {
            $groupsByName[mb_strtolower($group->getName())] = $group;
        }

        $linked  = 0;
        $skipped = 0;
        /** @var array<string, true> $unknownTeachers */
        $unknownTeachers = [];
        /** @var array<string, true> $unknownGroups */
        $unknownGroups = [];

        foreach ($parsed['rows'] as $row) {
            $groupName  = $row['Unidad'] ?? '';
            $fullName   = $row['Profesor/a'] ?? '';
            $subject    = trim($row['Materia'] ?? '');
            $nameParts  = explode(', ', $fullName, 2);
            $lastName   = $nameParts[0];
            $firstName  = $nameParts[1] ?? '';

            if ($groupName === '' || $firstName === '' || $lastName === '' || $subject === '') {
                $skipped++;
                continue;
            }

            $group = $groupsByName[mb_strtolower($groupName)] ?? null;
            if ($group === null) {
                $unknownGroups[$groupName] = true;
                continue;
            }

            $teacher = $this->teachers->findByFullName($firstName, $lastName);
            if ($teacher === null) {
                $unknownTeachers[$fullName] = true;
                continue;
            }

            if (!$group->hasTeacherSubject($teacher, $subject)) {
                $group->addTeacher($teacher, $subject);
                $linked++;
            }
        }

        $this->em->flush();

        $summary = $this->translator->trans('centre_teachers.import_assignments.flash.summary', [
            '%linked%'  => $linked,
            '%skipped%' => $skipped,
        ], 'admin');

        if ($unknownTeachers !== []) {
            $summary .= ' ' . $this->translator->trans('centre_teachers.import_assignments.flash.unknown_teachers', [
                '%teachers%' => implode(', ', array_map(static fn (string $n) => '«' . $n . '»', array_keys($unknownTeachers))),
            ], 'admin');
        }

        if ($unknownGroups !== []) {
            $summary .= ' ' . $this->translator->trans('centre_teachers.import_assignments.flash.unknown_groups', [
                '%groups%' => implode(', ', array_map(static fn (string $g) => '«' . $g . '»', array_keys($unknownGroups))),
            ], 'admin');
        }

        $this->addFlash($unknownTeachers === [] && $unknownGroups === [] ? 'success' : 'error', $summary);

        return $this->redirectToRoute('app_centre_teachers_index', ['centreId' => $centre->getId()]);
    }

    #[Route('/registrar', name: 'app_centre_teachers_register')]
    public function register(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentreWithActiveYear($centreId);
        $this->denyIfViewingPastYear($centre);

        $errors = [];
        $values = [
            'first_name' => '',
            'last_name'  => '',
            'username'   => trim($request->query->getString('username')),
            'email'      => '',
            'password'   => '',
        ];
        $flags = ['admin' => false, 'active' => true, 'external' => true];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register_centre_teacher', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'first_name' => trim($request->request->getString('first_name')),
                'last_name'  => trim($request->request->getString('last_name')),
                'username'   => trim($request->request->getString('username')),
                'email'      => trim($request->request->getString('email')),
                'password'   => $request->request->getString('password'),
            ];
            $flags = [
                'admin'    => false,
                'active'   => $request->request->has('active'),
                'external' => $request->request->getString('auth_method') === 'external',
            ];

            $errors = $this->validateTeacher($values, !$flags['external']);

            if (empty($errors['username']) && $this->teachers->findByUsername($values['username']) !== null) {
                $errors['username'] = $this->t('teacher.error.username_duplicate');
            }

            if (empty($errors)) {
                $teacher = new Teacher(new PersonName($values['first_name'], $values['last_name']));
                $teacher->setUsername($values['username'])
                    ->setEmail($values['email'] !== '' ? $values['email'] : null)
                    ->setAdmin($flags['admin'])
                    ->setActive($flags['active'])
                    ->setExternal($flags['external']);

                if (!$flags['external']) {
                    $teacher->setPassword($this->hasher->hashPassword($teacher, $values['password']));
                }

                $this->em->persist($teacher);
                $centre->getActiveAcademicYear()?->addTeacher($teacher);
                $this->em->flush();

                $this->addFlash('success', $this->t('centre_teachers.flash.registered_and_added'));

                return $this->redirectToRoute('app_centre_teachers_index', ['centreId' => $centre->getId()]);
            }
        }

        return $this->render('admin/centre_teacher/register.html.twig', [
            'centre' => $centre,
            'errors' => $errors,
            'values' => $values,
            'flags'  => $flags,
        ]);
    }

    #[Route('/{teacherId}/materias', name: 'app_centre_teachers_subjects')]
    public function subjects(string $centreId, string $teacherId): Response
    {
        $centre  = $this->requireCentreWithActiveYear($centreId);
        $this->denyIfViewingPastYear($centre);
        $teacher = $this->teachers->findById($teacherId);

        $activeYear = $centre->getActiveAcademicYear();
        if ($teacher === null || $activeYear === null || !$activeYear->getTeachers()->contains($teacher)) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/centre_teacher/subjects.html.twig', [
            'centre'  => $centre,
            'teacher' => $teacher,
        ]);
    }

    #[Route('/{teacherId}/quitar', name: 'app_centre_teachers_remove', methods: ['POST'])]
    public function remove(string $centreId, string $teacherId, Request $request): Response
    {
        $centre  = $this->requireCentreWithActiveYear($centreId);
        $this->denyIfViewingPastYear($centre);
        $teacher = $this->teachers->findById($teacherId);

        if ($teacher === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('remove_centre_teacher_' . $teacher->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $year = $centre->getActiveAcademicYear();
        if ($year !== null && $year->getTeachers()->contains($teacher)) {
            $year->removeTeacher($teacher);
            $this->em->flush();
        }

        $this->addFlash('success', $this->t('centre_teachers.flash.removed'));

        return $this->redirectToRoute('app_centre_teachers_index', ['centreId' => $centre->getId()]);
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

    /**
     * @param  array<string, string> $values
     * @return array<string, string>
     */
    private function validateTeacher(array $values, bool $passwordRequired): array
    {
        $errors = [];

        if ($values['first_name'] === '') {
            $errors['first_name'] = $this->t('teacher.error.first_name_required');
        }

        if ($values['last_name'] === '') {
            $errors['last_name'] = $this->t('teacher.error.last_name_required');
        }

        if ($values['username'] === '') {
            $errors['username'] = $this->t('teacher.error.username_required');
        }

        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $this->t('teacher.error.email_invalid');
        }

        if ($passwordRequired && $values['password'] === '') {
            $errors['password'] = $this->t('teacher.error.password_required');
        }

        return $errors;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\AcademicYearRepository;
use App\Repository\EducationalCentreRepository;
use App\Repository\TeacherRepository;
use App\Service\ActivityLogService;
use App\Service\CommunicationMethodSeeder;
use App\Service\EntityChangeTracker;
use App\Service\IncidentBehaviorSeeder;
use App\Service\LocationOptionSeeder;
use App\Service\SanctionMeasureSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/centros')]
#[IsGranted('ROLE_ADMIN')]
class EducationalCentreController extends AbstractController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['code', 'name', 'city'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly AcademicYearRepository $years,
        private readonly TeacherRepository $teachers,
        private readonly TranslatorInterface $translator,
        private readonly IncidentBehaviorSeeder $behaviorSeeder,
        private readonly SanctionMeasureSeeder $sanctionMeasureSeeder,
        private readonly CommunicationMethodSeeder $communicationMethodSeeder,
        private readonly LocationOptionSeeder $locationOptionSeeder,
        private readonly ActivityLogService $activityLog,
        private readonly EntityChangeTracker $changeTracker,
    ) {}

    #[Route('', name: 'app_admin_centres_index')]
    public function index(): Response
    {
        return $this->render('admin/educational_centre/index.html.twig');
    }

    #[Route('/nuevo', name: 'app_admin_centres_new')]
    public function new(Request $request): Response
    {
        $errors = [];
        $values = ['code' => '', 'name' => '', 'city' => ''];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_centre', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'code' => trim($request->request->getString('code')),
                'name' => trim($request->request->getString('name')),
                'city' => trim($request->request->getString('city')),
            ];

            $errors = $this->validateCentre($values);

            if (empty($errors) && $this->centres->findByCode($values['code']) !== null) {
                $errors['code'] = $this->t('centre.error.code_duplicate');
            }

            if (empty($errors)) {
                $centre = (new EducationalCentre())
                    ->setCode($values['code'])
                    ->setName($values['name'])
                    ->setCity($values['city']);

                $year = (int) (new \DateTimeImmutable())->format('Y');
                $academicYear = (new AcademicYear())
                    ->setName($year . '-' . ($year + 1))
                    ->setEducationalCentre($centre);
                $centre->setActiveAcademicYear($academicYear);

                $this->em->persist($centre);
                $this->em->persist($academicYear);
                $this->behaviorSeeder->seedForCentre($centre);
                $this->sanctionMeasureSeeder->seedForCentre($centre);
                $this->communicationMethodSeeder->seedForCentre($centre);
                $this->locationOptionSeeder->seedForCentre($centre);
                $this->em->flush();

                $this->activityLog->log('educational_centre.created', [
                    'entityId' => $centre->getId()->toRfc4122(),
                    'code'     => $centre->getCode(),
                    'name'     => $centre->getName(),
                ]);
                $this->activityLog->log('academic_year.created', [
                    'entityId' => $academicYear->getId()->toRfc4122(),
                    'centreId' => $centre->getId()->toRfc4122(),
                    'name'     => $academicYear->getName(),
                ]);

                $this->addFlash('success', $this->t('centre.flash.created'));

                return $this->redirectToRoute('app_admin_centres_index');
            }
        }

        return $this->render('admin/educational_centre/new.html.twig', [
            'errors' => $errors,
            'values' => $values,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_centres_edit')]
    public function edit(string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($id);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $errors = [];

        /** @var Teacher[] $selectedAdmins */
        $selectedAdmins = $centre->getAdmins()->toArray();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_centre_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'code' => trim($request->request->getString('code')),
                'name' => trim($request->request->getString('name')),
                'city' => trim($request->request->getString('city')),
            ];

            $errors = $this->validateCentre($values);

            $existing = $this->centres->findByCode($values['code']);
            if (empty($errors['code']) && $existing !== null
                && $existing->getId()->toRfc4122() !== $id) {
                $errors['code'] = $this->t('centre.error.code_duplicate');
            }

            $submittedIds = array_values(array_filter(
                array_map(
                    static fn(mixed $v): string => \is_string($v) ? $v : '',
                    $request->request->all('admins')
                ),
                static fn(string $v): bool => $v !== ''
            ));

            if (!empty($errors)) {
                $selectedAdmins = [];
                foreach ($submittedIds as $adminId) {
                    $teacher = $this->teachers->findById($adminId);
                    if ($teacher !== null) {
                        $selectedAdmins[] = $teacher;
                    }
                }
            } else {
                $before = $this->changeTracker->snapshot($centre, self::LOGGED_FIELDS);

                $centre->setCode($values['code'])
                    ->setName($values['name'])
                    ->setCity($values['city']);

                foreach ($centre->getAdmins()->toArray() as $admin) {
                    $centre->removeAdmin($admin);
                }
                foreach ($submittedIds as $adminId) {
                    $teacher = $this->teachers->findById($adminId);
                    if ($teacher !== null) {
                        $centre->addAdmin($teacher);
                    }
                }

                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $centre, self::LOGGED_FIELDS);
                if ($changes !== []) {
                    $this->activityLog->log('educational_centre.updated', [
                        'entityId' => $centre->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('centre.flash.saved'));

                return $this->redirectToRoute('app_admin_centres_edit', ['id' => $id]);
            }
        }

        return $this->render('admin/educational_centre/edit.html.twig', [
            'centre'         => $centre,
            'errors'         => $errors,
            'selectedAdmins' => $selectedAdmins,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_admin_centres_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_centre_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $centre = $this->centres->findByIdWithActiveYear($id);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        try {
            $centre->setActiveAcademicYear(null);
            $this->em->flush();
            foreach ($this->years->findByCentreOrderedByName($centre) as $year) {
                $this->em->remove($year);
            }
            $this->em->remove($centre);
            $this->em->flush();
            $this->addFlash('success', $this->t('centre.flash.deleted'));
        } catch (\Exception) {
            $this->addFlash('error', $this->t('centre.flash.delete_error'));
        }

        return $this->redirectToRoute('app_admin_centres_index');
    }

    /**
     * @param  array<string, string> $values
     * @return array<string, string>
     */
    private function validateCentre(array $values): array
    {
        $errors = [];

        if ($values['code'] === '') {
            $errors['code'] = $this->t('centre.error.code_required');
        } elseif (\strlen($values['code']) > 8) {
            $errors['code'] = $this->t('centre.error.code_too_long');
        }

        if ($values['name'] === '') {
            $errors['name'] = $this->t('centre.error.name_required');
        }

        if ($values['city'] === '') {
            $errors['city'] = $this->t('centre.error.city_required');
        }

        return $errors;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}

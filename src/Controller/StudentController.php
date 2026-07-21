<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Repository\StudentRepository;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\StudentContactVisibility;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/alumnado')]
class StudentController extends AbstractController
{
    /** @var list<string> */
    private const CONTACT_FIELDS = [
        'details',
        'tutorName1', 'tutorEmail1', 'tutorName2', 'tutorEmail2',
        'contactPhone1', 'contactPhone1Notes',
        'contactPhone2', 'contactPhone2Notes',
        'contactPhone3', 'contactPhone3Notes',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StudentRepository $students,
        private readonly IncidentReportRepository $reports,
        private readonly SanctionRepository $sanctions,
        private readonly StudentContactVisibility $contactVisibility,
        private readonly EntityManagerInterface $em,
        private readonly EntityChangeTracker $changeTracker,
        private readonly ActivityLogService $activityLog,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/{id}', name: 'app_students_show', methods: ['GET'])]
    public function show(string $id, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $viewer = $this->getUser();
        if (!$viewer instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $student = $this->students->findById($id);
        if ($student === null || !$this->students->belongsToCentre($student, $centre)) {
            throw $this->createNotFoundException();
        }

        $year = $this->tenantContext->getViewYear($centre);

        /** @var list<IncidentReport> $reports */
        $reports = $year === null
            ? []
            : $this->reports->createFilteredQuery($centre, $viewer, $year, ['studentId' => $id])->getResult();
        /** @var list<Sanction> $sanctions */
        $sanctions = $year === null
            ? []
            : $this->sanctions->createFilteredQuery($centre, $viewer, $year, ['studentId' => $id])->getResult();

        $seriousCount    = 0;
        $prescribedCount = 0;
        foreach ($reports as $report) {
            foreach ($report->getBehaviors() as $behavior) {
                if ($behavior->isSerious()) {
                    ++$seriousCount;
                    break;
                }
            }
            if ($report->getPrescribedAt() !== null) {
                ++$prescribedCount;
            }
        }

        $today           = new \DateTimeImmutable('today');
        $activeSanctions = 0;
        foreach ($sanctions as $sanction) {
            if ($sanction->isNotified()
                && $sanction->getEffectiveFrom() !== null
                && $sanction->getEffectiveFrom() <= $today
                && ($sanction->getEffectiveTo() === null || $sanction->getEffectiveTo() >= $today)) {
                ++$activeSanctions;
            }
        }

        /** @var list<array{type: string, date: \DateTimeImmutable, report?: IncidentReport, sanction?: Sanction}> $timeline */
        $timeline = [];
        foreach ($reports as $report) {
            $timeline[] = ['type' => 'report', 'date' => $report->getOccurredAt(), 'report' => $report];
        }
        foreach ($sanctions as $sanction) {
            $timeline[] = ['type' => 'sanction', 'date' => $sanction->getCreatedAt(), 'sanction' => $sanction];
        }
        usort($timeline, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        $activeGroups = [];
        foreach ($student->getGroups() as $group) {
            if ($year !== null && $group->getAcademicYear() === $year) {
                $activeGroups[] = $group;
            }
        }

        $canSeeContact   = $this->contactVisibility->isVisibleTo($viewer, $centre, $student);
        $canEditContact  = $year !== null
            && !$this->tenantContext->isViewingNonActiveYear($centre)
            && $this->contactVisibility->canEditContact($viewer, $student, $year);

        $isTutorOfStudent = false;
        foreach ($activeGroups as $group) {
            if ($group->getTutors()->contains($viewer)) {
                $isTutorOfStudent = true;
                break;
            }
        }

        return $this->render('student/show.html.twig', [
            'centre'           => $centre,
            'student'          => $student,
            'activeGroups'     => $activeGroups,
            'timeline'         => $timeline,
            'reportCount'      => count($reports),
            'seriousCount'     => $seriousCount,
            'prescribedCount'  => $prescribedCount,
            'activeSanctions'  => $activeSanctions,
            'canSeeContact'    => $canSeeContact,
            'canEditContact'   => $canEditContact,
            'isTutorOfStudent' => $isTutorOfStudent,
        ]);
    }

    #[Route('/{id}/contacto', name: 'app_students_edit_contact', methods: ['POST'])]
    public function editContact(string $id, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $viewer = $this->getUser();
        if (!$viewer instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $student = $this->students->findById($id);
        if ($student === null || !$this->students->belongsToCentre($student, $centre)) {
            throw $this->createNotFoundException();
        }

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null
            || $this->tenantContext->isViewingNonActiveYear($centre)
            || !$this->contactVisibility->canEditContact($viewer, $student, $year)) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('edit_contact_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $s = static fn (string $k): string => trim($request->request->getString($k));
        $n = static fn (string $v): ?string => $v !== '' ? $v : null;

        $before = $this->changeTracker->snapshot($student, self::CONTACT_FIELDS);

        $student->setTutorName1($n($s('tutorName1')))
            ->setTutorEmail1($n($s('tutorEmail1')))
            ->setTutorName2($n($s('tutorName2')))
            ->setTutorEmail2($n($s('tutorEmail2')))
            ->setContactPhone1($n($s('contactPhone1')))
            ->setContactPhone1Notes($n($s('contactPhone1Notes')))
            ->setContactPhone2($n($s('contactPhone2')))
            ->setContactPhone2Notes($n($s('contactPhone2Notes')))
            ->setContactPhone3($n($s('contactPhone3')))
            ->setContactPhone3Notes($n($s('contactPhone3Notes')))
            ->setDetails($n($s('details')));

        $this->em->flush();

        $changes = $this->changeTracker->diff($before, $student, self::CONTACT_FIELDS);
        if ($changes !== []) {
            $this->activityLog->log('student.contact_updated', [
                'entityId' => $student->getId()->toRfc4122(),
                'changes'  => $changes,
            ]);
        }

        $this->addFlash('success', $this->translator->trans('student.show.contact_saved', [], 'admin'));

        return $this->redirectToRoute('app_students_show', ['id' => $id]);
    }
}

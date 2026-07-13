<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Repository\StudentRepository;
use App\Service\StudentContactVisibility;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/alumnado')]
class StudentController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StudentRepository $students,
        private readonly IncidentReportRepository $reports,
        private readonly SanctionRepository $sanctions,
        private readonly StudentContactVisibility $contactVisibility,
    ) {}

    #[Route('/{id}', name: 'app_students_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

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

        $canSeeContact = $this->contactVisibility->isVisibleTo($viewer, $centre, $student);

        return $this->render('student/show.html.twig', [
            'centre'          => $centre,
            'student'         => $student,
            'activeGroups'    => $activeGroups,
            'timeline'        => $timeline,
            'reportCount'     => count($reports),
            'seriousCount'    => $seriousCount,
            'prescribedCount' => $prescribedCount,
            'activeSanctions' => $activeSanctions,
            'canSeeContact'   => $canSeeContact,
        ]);
    }
}

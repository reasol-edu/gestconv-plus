<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\StudentExit;
use App\Entity\StudentPriorityPass;
use App\Entity\Teacher;
use App\Repository\StudentExitRepository;
use App\Repository\StudentPriorityPassRepository;
use App\Security\Voter\StudentExitVoter;
use App\Service\ActivityLogService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Estudiantes que están fuera del aula ahora mismo. Se refresca solo (sondeo) y el docente
 * conectado ve primero, aparte, las salidas que él mismo ha autorizado.
 */
#[AsLiveComponent]
class ExitCurrentListComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    /** @var list<StudentExit>|null */
    private ?array $current = null;

    public function __construct(
        private readonly StudentExitRepository $exits,
        private readonly StudentPriorityPassRepository $passRepository,
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogService $activityLog,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        $this->centre = $centre;
    }

    /** @return list<StudentExit> */
    private function current(): array
    {
        $year = $this->centre->getActiveAcademicYear();

        return $this->current ??= $year === null ? [] : $this->exits->findCurrent($year);
    }

    /** @return list<StudentExit> */
    public function getMine(): array
    {
        $user = $this->getUser();

        return array_values(array_filter(
            $this->current(),
            static fn (StudentExit $exit): bool => $exit->getAuthorizedBy() === $user,
        ));
    }

    /** @return list<StudentExit> */
    public function getOthers(): array
    {
        $user = $this->getUser();

        return array_values(array_filter(
            $this->current(),
            static fn (StudentExit $exit): bool => $exit->getAuthorizedBy() !== $user,
        ));
    }

    /** @return array<string, StudentPriorityPass> */
    public function getPasses(): array
    {
        $year = $this->centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->passRepository->findByStudentsIndexed(
            array_map(static fn (StudentExit $exit) => $exit->getStudent(), $this->current()),
            $year,
        );
    }

    public function canReturn(StudentExit $exit): bool
    {
        return $this->isGranted(StudentExitVoter::MARK_RETURNED, $exit);
    }

    #[LiveAction]
    public function markReturned(#[LiveArg] string $id): void
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        if ($this->tenantContext->isViewingNonActiveYear($this->centre)) {
            throw $this->createAccessDeniedException();
        }

        $exit = $this->exits->findById($id);
        if ($exit === null || $exit->getAcademicYear()->getEducationalCentre() !== $this->centre) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(StudentExitVoter::MARK_RETURNED, $exit);

        // Otro docente pudo registrar ya el regreso mientras esta pantalla seguía abierta.
        if (!$exit->isReturned()) {
            $exit->markReturned($user);
            $this->em->flush();

            $this->activityLog->log('student_exit.returned', [
                'entityId'  => $exit->getId()->toRfc4122(),
                'studentId' => $exit->getStudent()->getId()->toRfc4122(),
            ]);
        }

        $this->current = null;
    }
}

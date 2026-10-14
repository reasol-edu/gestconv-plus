<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Entity\Group;
use App\Entity\StudentExit;
use App\Entity\StudentPriorityPass;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\ExitReasonRepository;
use App\Repository\GroupRepository;
use App\Repository\StudentExitRepository;
use App\Repository\StudentPriorityPassRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\StudentExitVoter;
use App\Service\ActivityLogService;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Todas las salidas del curso, con filtros y orden, para el equipo directivo y la administración.
 */
#[AsLiveComponent]
class ExitHistoryListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    private const SORTABLE = ['exitedAt', 'student', 'group', 'teacher', 'reason'];

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $groupId = '';

    #[LiveProp(writable: true)]
    public string $reasonId = '';

    #[LiveProp(writable: true)]
    public string $teacherId = '';

    #[LiveProp(writable: true)]
    public string $status = '';

    #[LiveProp(writable: true)]
    public string $from = '';

    #[LiveProp(writable: true)]
    public string $to = '';

    #[LiveProp(writable: true)]
    public string $sort = 'exitedAt';

    #[LiveProp(writable: true)]
    public string $sortDir = 'desc';

    /** Salida cuyo borrado espera confirmación. */
    #[LiveProp]
    public string $confirmDeleteId = '';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogService $activityLog,
        private readonly StudentExitRepository $exits,
        private readonly ExitReasonRepository $reasons,
        private readonly GroupRepository $groups,
        private readonly TeacherRepository $teachers,
        private readonly StudentPriorityPassRepository $passRepository,
        private readonly AppSettings $appSettings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->centre = $centre;
        $this->denyAccessUnlessGranted(StudentExitVoter::MANAGE, $centre);
    }

    /** @return Group[] */
    public function getGroups(): array
    {
        return $this->groups->findByActiveYearOfCentreOrderedByName($this->centre, $this->tenantContext->getViewYear($this->centre));
    }

    /** @return list<ExitReason> */
    public function getReasons(): array
    {
        return $this->reasons->findByCentreOrdered($this->centre);
    }

    /** @return list<Teacher> */
    public function getTeachers(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);

        return $year === null ? [] : array_values($this->teachers->findByAcademicYearOrderedByName($year));
    }

    /** @return Paginator<StudentExit> */
    public function getPagination(): Paginator
    {
        $this->denyAccessUnlessGranted(StudentExitVoter::MANAGE, $this->centre);

        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return Paginator::fromArray([], 0, 1, $this->appSettings->getInt('page.size'));
        }

        return $this->paginate($this->exits->createFilteredQuery($year, [
            'search'    => trim($this->search),
            'groupId'   => $this->groupId,
            'reasonId'  => $this->reasonId,
            'teacherId' => $this->teacherId,
            'status'    => $this->status,
            'from'      => $this->from,
            'to'        => $this->to,
            'sort'      => $this->sort,
            'sortDir'   => $this->sortDir,
        ]));
    }

    /**
     * @param iterable<StudentExit> $exits
     * @return array<string, StudentPriorityPass>
     */
    public function passesFor(iterable $exits): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return [];
        }

        $students = [];
        foreach ($exits as $exit) {
            $students[] = $exit->getStudent();
        }

        return $this->passRepository->findByStudentsIndexed($students, $year);
    }

    /** Se puede borrar solo en el curso activo; al consultar un curso pasado el historial es de lectura. */
    public function canDelete(): bool
    {
        return !$this->tenantContext->isViewingNonActiveYear($this->centre);
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->groupId !== '' || $this->reasonId !== ''
            || $this->teacherId !== '' || $this->status !== '' || $this->from !== '' || $this->to !== '';
    }

    #[LiveAction]
    public function sortBy(#[LiveArg] string $column): void
    {
        if (!in_array($column, self::SORTABLE, true)) {
            return;
        }

        $this->sortDir = $this->sort === $column && $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->sort    = $column;
        $this->page    = 1;
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->search = $this->groupId = $this->reasonId = $this->teacherId = $this->status = $this->from = $this->to = '';
        $this->page   = 1;
    }

    /** Pide confirmación para borrar una salida (no borra nada todavía). */
    #[LiveAction]
    public function askDelete(#[LiveArg] string $id): void
    {
        $this->requireWritable();
        $this->confirmDeleteId = $this->findInCentre($id) === null ? '' : $id;
    }

    #[LiveAction]
    public function cancelDelete(): void
    {
        $this->confirmDeleteId = '';
    }

    /** Elimina de la base de datos la salida cuyo borrado se había pedido antes. */
    #[LiveAction]
    public function confirmDelete(#[LiveArg] string $id): void
    {
        $this->requireWritable();
        $exit = $this->findInCentre($id);
        if ($exit === null || $this->confirmDeleteId !== $id) {
            return;
        }

        $data = [
            'entityId'  => $exit->getId()->toRfc4122(),
            'studentId' => $exit->getStudent()->getId()->toRfc4122(),
            'groupId'   => $exit->getGroup()->getId()->toRfc4122(),
            'reasonId'  => $exit->getReason()->getId()->toRfc4122(),
        ];
        $this->em->remove($exit);
        $this->em->flush();
        $this->activityLog->log('student_exit.deleted', $data);

        $this->confirmDeleteId = '';
    }

    /** Solo administración (global o del centro) y sin estar viendo un curso pasado. */
    private function requireWritable(): void
    {
        $this->denyAccessUnlessGranted(StudentExitVoter::MANAGE, $this->centre);
        if ($this->tenantContext->isViewingNonActiveYear($this->centre)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function findInCentre(string $id): ?StudentExit
    {
        $exit = $this->exits->findById($id);

        return $exit !== null && $exit->getAcademicYear()->getEducationalCentre() === $this->centre ? $exit : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\IssuePriorityRepository;
use App\Repository\IssueRepository;
use App\Repository\IssueTypeRepository;
use App\Security\Voter\IssueVoter;
use App\Service\AppSettings;
use App\Service\IssueAccess;
use App\Service\IssueListFilter;
use App\Service\IssueManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Listado de incidencias: por defecto solo las abiertas (abiertas y en proceso), con interruptor para las cerradas,
 * filtros, búsqueda, orden, paginación, cifras por rol y cambio de estado en bloque.
 */
#[AsLiveComponent]
class IssueListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    /** '' = abiertas (abiertas y en proceso); 'all' = todas; o un estado concreto. */
    #[LiveProp(writable: true)]
    public string $status = '';

    #[LiveProp(writable: true)]
    public string $typeId = '';

    #[LiveProp(writable: true)]
    public string $priorityId = '';

    #[LiveProp(writable: true)]
    public string $scope = '';

    #[LiveProp(writable: true)]
    public bool $overdueOnly = false;

    #[LiveProp(writable: true)]
    public string $sort = 'created';

    #[LiveProp(writable: true)]
    public string $sortDir = 'desc';

    /** @var list<string> ids de las incidencias marcadas para el cambio en bloque */
    #[LiveProp(writable: true)]
    public array $selected = [];

    /** Se ha pedido eliminar las marcadas y falta confirmarlo. */
    #[LiveProp]
    public bool $confirmingDelete = false;

    #[LiveProp]
    public string $message = '';

    #[LiveProp]
    public string $messageKind = 'success';

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly IssueTypeRepository $types,
        private readonly IssuePriorityRepository $priorities,
        private readonly IssueAccess $access,
        private readonly IssueManager $manager,
        private readonly AppSettings $appSettings,
        private readonly TranslatorInterface $translator,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        // El acceso a la pantalla lo comprueba el controlador; aquí cada consulta ya se limita a lo que el docente puede ver.
        $this->getViewer();
        $this->centre = $centre;
    }

    public function getViewer(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** Gestiona incidencias ajenas (dirección o responsable de algún tipo): ve más filtros, cifras y el cambio en bloque. */
    public function getIsManager(): bool
    {
        return $this->access->hasManagerRole($this->getViewer(), $this->centre);
    }

    /** @return Paginator<Issue> */
    public function getPagination(): Paginator
    {
        $viewer = $this->getViewer();

        return $this->paginate($this->issues->createListQuery(
            $this->centre,
            $viewer,
            $this->access->isManagement($viewer, $this->centre),
            $this->access->managedTypeIds($viewer, $this->centre),
            $this->filter(),
        ));
    }

    /** @return array{open: int, in_progress: int, no_priority: int, overdue: int, closed: int} */
    public function getStats(): array
    {
        $viewer = $this->getViewer();

        return $this->issues->statistics(
            $this->centre,
            $viewer,
            $this->access->isManagement($viewer, $this->centre),
            $this->access->managedTypeIds($viewer, $this->centre),
            \Symfony\Component\Clock\now(),
        );
    }

    /** @return list<IssueType> */
    public function getAvailableTypes(): array
    {
        $types = $this->types->findByCentre($this->centre);
        usort($types, static fn (IssueType $a, IssueType $b): int => strcmp(mb_strtolower($a->getPath()), mb_strtolower($b->getPath())));

        return $types;
    }

    /** @return list<IssuePriority> */
    public function getAvailablePriorities(): array
    {
        return $this->priorities->findByCentreOrdered($this->centre);
    }

    public function getToday(): \DateTimeImmutable
    {
        return \Symfony\Component\Clock\now();
    }

    /** Puede eliminar incidencias: solo la dirección (administradores del centro y de la plataforma). */
    public function getCanDelete(): bool
    {
        return $this->access->isManagement($this->getViewer(), $this->centre);
    }

    public function canManage(Issue $issue): bool
    {
        return $this->isGranted(IssueVoter::MANAGE, $issue);
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->typeId !== '' || $this->priorityId !== '' || $this->scope !== '' || $this->overdueOnly;
    }

    // ── Acciones ──────────────────────────────────────────────────────────────

    #[LiveAction]
    public function sortBy(#[LiveArg] string $column): void
    {
        if ($this->sort === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort    = $column;
            $this->sortDir = $column === 'created' ? 'desc' : 'asc';
        }
        $this->page = 1;
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->search      = '';
        $this->status      = '';
        $this->typeId      = '';
        $this->priorityId  = '';
        $this->scope       = '';
        $this->overdueOnly = false;
        $this->page        = 1;
    }

    /** Atajo de las cifras de la cabecera. */
    #[LiveAction]
    public function quickFilter(#[LiveArg] string $name): void
    {
        $this->clearFilters();
        match ($name) {
            'open'        => $this->status = IssueStatus::Open->value,
            'in_progress' => $this->status = IssueStatus::InProgress->value,
            'no_priority' => $this->priorityId = IssueListFilter::NO_PRIORITY,
            'overdue'     => $this->overdueOnly = true,
            'closed'      => $this->status = 'closed',
            default       => null,
        };
    }

    #[LiveAction]
    public function toggleAllVisible(): void
    {
        $ids = [];
        foreach ($this->getPagination()->getItems() as $issue) {
            if ($this->canManage($issue)) {
                $ids[] = $issue->getId()->toRfc4122();
            }
        }
        $this->selected = count(array_intersect($ids, $this->selected)) === count($ids) ? [] : $ids;
    }

    /** Cambia el estado de las incidencias marcadas. Las que no se pueden cambiar (sin prioridad, sin permiso) se cuentan aparte. */
    #[LiveAction]
    public function bulkStatus(#[LiveArg] string $to): void
    {
        $status = IssueStatus::tryFrom($to);
        if ($status === null || $status === IssueStatus::Duplicate || $this->selected === []) {
            return;
        }

        $actor = $this->getViewer();
        $done  = 0;
        $failed = 0;
        foreach ($this->issues->findByCentreAndIds($this->centre, $this->selected) as $issue) {
            if (!$this->isGranted(IssueVoter::MANAGE, $issue)) {
                ++$failed;

                continue;
            }
            if ($issue->getStatus() === $status) {
                continue;
            }
            $this->manager->transition($issue, $actor, $status) === null ? ++$done : ++$failed;
        }

        $this->selected    = [];
        $this->messageKind = $failed > 0 ? 'warning' : 'success';
        $this->message     = $this->translator->trans('issue.list.bulk_result', ['%done%' => $done, '%failed%' => $failed], 'admin');
    }

    /** Pide confirmación para eliminar las marcadas (no borra nada todavía). */
    #[LiveAction]
    public function askBulkDelete(): void
    {
        $this->confirmingDelete = $this->getCanDelete() && $this->selected !== [];
    }

    #[LiveAction]
    public function cancelBulkDelete(): void
    {
        $this->confirmingDelete = false;
    }

    /** Elimina las marcadas, con todo lo suyo. Solo la dirección; el resto se ignora. */
    #[LiveAction]
    public function bulkDelete(): void
    {
        $confirmed              = $this->confirmingDelete;
        $this->confirmingDelete = false;
        if (!$confirmed || $this->selected === []) {
            return;
        }

        $deleted = 0;
        foreach ($this->issues->findByCentreAndIds($this->centre, $this->selected) as $issue) {
            if ($this->isGranted(IssueVoter::DELETE, $issue)) {
                $this->manager->delete($issue);
                ++$deleted;
            }
        }

        $this->selected    = [];
        $this->page        = 1;
        $this->messageKind = $deleted > 0 ? 'success' : 'warning';
        $this->message     = $this->translator->trans('issue.list.bulk_deleted', ['%count%' => $deleted], 'admin');
    }

    // ── Ayudas ────────────────────────────────────────────────────────────────

    private function filter(): IssueListFilter
    {
        $statuses = match ($this->status) {
            ''      => IssueStatus::openValues(),
            'all'   => [],
            'closed' => array_map(static fn (IssueStatus $s): string => $s->value, IssueStatus::closedStatuses()),
            default => IssueStatus::tryFrom($this->status) !== null ? [$this->status] : IssueStatus::openValues(),
        };

        $scope = $this->getIsManager() || $this->scope !== IssueListFilter::SCOPE_MANAGED ? $this->scope : '';

        return new IssueListFilter(
            search: trim($this->search),
            statuses: $statuses,
            typeIds: $this->typeIdsWithDescendants(),
            priority: $this->priorityId,
            scope: in_array($scope, [IssueListFilter::SCOPE_MINE, IssueListFilter::SCOPE_MANAGED, IssueListFilter::SCOPE_ASSIGNED], true) ? $scope : '',
            overdueOnly: $this->overdueOnly,
            sort: in_array($this->sort, IssueListFilter::SORTS, true) ? $this->sort : 'created',
            sortDir: $this->sortDir === 'asc' ? 'asc' : 'desc',
            today: \Symfony\Component\Clock\now(),
        );
    }

    /**
     * El tipo elegido y todos los que cuelgan de él.
     *
     * @return list<string>
     */
    private function typeIdsWithDescendants(): array
    {
        if ($this->typeId === '') {
            return [];
        }

        $ids = [];
        foreach ($this->types->findByCentre($this->centre) as $type) {
            if ($type->getId()->toRfc4122() === $this->typeId) {
                $ids[] = $this->typeId;

                continue;
            }
            foreach ($type->getAncestors() as $ancestor) {
                if ($ancestor->getId()->toRfc4122() === $this->typeId) {
                    $ids[] = $type->getId()->toRfc4122();

                    break;
                }
            }
        }

        // Un tipo que no es del centro no encuentra nada, en lugar de quitar el filtro.
        return $ids !== [] ? $ids : ['00000000-0000-0000-0000-000000000000'];
    }
}

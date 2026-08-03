<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\DailyNoteRepository;
use App\Repository\DailyNoteTypeRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class DailyNoteStudentListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp]
    public Teacher $viewer;

    #[LiveProp(writable: true)]
    public string $typeId = '';

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $groupId = '';

    public function __construct(
        private readonly DailyNoteRepository $notes,
        private readonly DailyNoteTypeRepository $types,
        private readonly AppSettings $appSettings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre, Teacher $viewer, string $initialTypeId = ''): void
    {
        $this->centre = $centre;
        $this->viewer = $viewer;

        $types = $this->types->findByCentreOrdered($centre);
        foreach ($types as $type) {
            if ($type->getId()->toRfc4122() === $initialTypeId) {
                $this->typeId = $initialTypeId;

                return;
            }
        }
        $this->typeId = $types !== [] ? $types[0]->getId()->toRfc4122() : '';
    }

    /** @return list<DailyNoteType> */
    public function getTypes(): array
    {
        return $this->types->findByCentreOrdered($this->centre);
    }

    public function getSelectedType(): ?DailyNoteType
    {
        return $this->typeId !== '' ? $this->types->findById($this->typeId) : null;
    }

    /** @return Group[] */
    public function getAvailableGroups(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        $type = $this->getSelectedType();
        if ($year === null || $type === null) {
            return [];
        }

        return $this->notes->findGroupsWithNotesByType($this->centre, $this->viewer, $year, $type);
    }

    /** @return Paginator<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, activeCount: int, inactiveCount: int, activeFrom: ?\DateTimeImmutable, activeTo: ?\DateTimeImmutable}> */
    public function getPagination(): Paginator
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        $type = $this->getSelectedType();

        if ($year instanceof AcademicYear && $type !== null) {
            $rows = $this->notes->findStudentSummaryByType($this->centre, $this->viewer, $year, $type, [
                'search'  => trim($this->search),
                'groupId' => trim($this->groupId),
            ]);
        } else {
            $rows = [];
        }

        $pageSize = $this->appSettings->getInt('page.size');
        $page     = max(1, $this->page);
        $total    = count($rows);
        $offset   = ($page - 1) * $pageSize;

        return Paginator::fromArray(array_slice($rows, $offset, $pageSize), $total, $page, $pageSize);
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->groupId !== '';
    }

    #[LiveAction]
    public function resetTypeFilters(): void
    {
        $this->groupId = '';
        $this->page    = 1;
    }
}

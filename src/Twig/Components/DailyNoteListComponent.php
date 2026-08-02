<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\DailyNote;
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
class DailyNoteListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public bool $ownOnly = false;

    #[LiveProp(writable: true)]
    public bool $tutoringOnly = false;

    #[LiveProp(writable: true)]
    public string $groupId = '';

    #[LiveProp(writable: true)]
    public string $typeId = '';

    public function __construct(
        private readonly DailyNoteRepository $notes,
        private readonly DailyNoteTypeRepository $types,
        private readonly AppSettings $appSettings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        $this->centre = $centre;
    }

    /** @return Group[] */
    public function getGroups(): array
    {
        $user = $this->getUser();
        $year = $this->tenantContext->getViewYear($this->centre);
        if (!$user instanceof Teacher || $year === null) {
            return [];
        }

        return $this->notes->findGroupsWithNotes($this->centre, $user, $year);
    }

    /** @return DailyNoteType[] */
    public function getTypes(): array
    {
        return $this->types->findByCentreOrdered($this->centre);
    }

    /** @return Paginator<DailyNote> */
    public function getPagination(): Paginator
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return Paginator::fromArray([], 0, max(1, $this->page), $this->appSettings->getInt('page.size'));
        }

        $filters = [
            'search'       => $this->search,
            'ownOnly'      => $this->ownOnly,
            'tutoringOnly' => $this->tutoringOnly,
            'groupId'      => $this->groupId,
            'typeId'       => $this->typeId,
        ];

        return $this->paginate($this->notes->createFilteredQuery(
            $this->centre,
            $user,
            $year,
            $filters,
        ));
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->ownOnly
            || $this->tutoringOnly
            || $this->groupId !== ''
            || $this->typeId !== '';
    }

    #[LiveAction]
    public function toggleOwnOnly(): void
    {
        $this->ownOnly      = !$this->ownOnly;
        $this->tutoringOnly = false;
        $this->page         = 1;
    }

    #[LiveAction]
    public function toggleTutoringOnly(): void
    {
        $this->tutoringOnly = !$this->tutoringOnly;
        $this->ownOnly      = false;
        $this->page         = 1;
    }
}

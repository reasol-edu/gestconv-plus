<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\SchoolEvent;
use App\Entity\SchoolEventType;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\GroupRepository;
use App\Repository\SchoolEventRepository;
use App\Repository\SchoolEventTypeRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class SchoolEventListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $groupId = '';

    #[LiveProp(writable: true)]
    public string $typeId = '';

    public function __construct(
        private readonly SchoolEventRepository $events,
        private readonly SchoolEventTypeRepository $types,
        private readonly GroupRepository $groups,
        private readonly AppSettings $appSettings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::PUBLISH_EVENTS, $centre);
        $this->centre = $centre;
    }

    /** @return Group[] */
    public function getAvailableGroups(): array
    {
        return $this->groups->findByActiveYearOfCentreOrderedByName(
            $this->centre,
            $this->tenantContext->getViewYear($this->centre),
        );
    }

    /** @return list<SchoolEventType> */
    public function getAvailableTypes(): array
    {
        return $this->types->findByCentreOrdered($this->centre);
    }

    /** @return Paginator<SchoolEvent> */
    public function getPagination(): Paginator
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return Paginator::fromQuery($this->events->findNoneQuery(), 1, $this->appSettings->getInt('page.size'));
        }

        // Quien publica sin gestionar todas las actividades (dirección, administradores o coordinación) solo lista las suyas.
        $user    = $this->getUser();
        $viewer  = !$this->isGranted(EducationalCentreVoter::MANAGE_ALL_EVENTS, $this->centre) && $user instanceof Teacher ? $user : null;

        return $this->paginate(
            $this->events->createFilteredQuery($year, trim($this->search), trim($this->groupId), $viewer, trim($this->typeId)),
        );
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->groupId !== '' || $this->typeId !== '';
    }
}

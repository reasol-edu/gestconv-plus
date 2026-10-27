<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\ResourceCategory;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\GroupRepository;
use App\Repository\ResourceBookingRepository;
use App\Repository\ResourceCategoryRepository;
use App\Repository\SharedResourceRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AppSettings;
use App\Service\ResourceBookingAccess;
use App\Service\ResourceBookingFilters;
use App\Service\ResourceBookingPlanner;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Listado paginado y filtrable de reservas. Por defecto, las del propio docente; quien gestiona alguna categoría
 * (o la dirección) puede pasar a «todas las que gestiona» y filtrar por docente.
 */
#[AsLiveComponent]
class ResourceBookingListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    public const SCOPE_MINE    = 'mine';
    public const SCOPE_MANAGED = 'managed';

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $scope = self::SCOPE_MINE;

    #[LiveProp(writable: true)]
    public string $categoryId = '';

    #[LiveProp(writable: true)]
    public string $resourceId = '';

    #[LiveProp(writable: true)]
    public string $teacherId = '';

    #[LiveProp(writable: true)]
    public string $groupId = '';

    #[LiveProp(writable: true)]
    public string $status = '';

    #[LiveProp(writable: true)]
    public string $dateFrom = '';

    #[LiveProp(writable: true)]
    public string $dateTo = '';

    public function __construct(
        private readonly ResourceBookingRepository $bookings,
        private readonly ResourceCategoryRepository $categories,
        private readonly SharedResourceRepository $resources,
        private readonly GroupRepository $groups,
        private readonly TeacherRepository $teachers,
        private readonly ResourceBookingAccess $access,
        private readonly ResourceBookingPlanner $planner,
        private readonly AppSettings $appSettings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre, string $scope = self::SCOPE_MINE, string $status = '', ?string $dateFrom = null): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::BOOKINGS, $centre);
        $this->centre = $centre;
        $this->scope  = $scope;
        $this->status = $status;
        // Lo ya pasado no interesa de entrada: se ven las reservas desde hoy (vaciando la fecha se ve todo).
        $this->dateFrom = $dateFrom ?? $this->planner->today()->format('Y-m-d');
    }

    /** Puede ver las reservas de otros: es dirección o responsable de alguna categoría. */
    public function canSeeOthers(): bool
    {
        $user = $this->getUser();

        return $user instanceof Teacher && $this->access->hasManagerRole($user, $this->centre);
    }

    /** @return list<ResourceCategory> */
    public function getAvailableCategories(): array
    {
        return $this->categories->findByCentreOrdered($this->centre);
    }

    /** @return list<SharedResource> */
    public function getAvailableResources(): array
    {
        return $this->resources->findByCentreOrdered($this->centre);
    }

    /** @return list<Group> */
    public function getAvailableGroups(): array
    {
        return array_values($this->groups->findByActiveYearOfCentreOrderedByName($this->centre, $this->tenantContext->getViewYear($this->centre)));
    }

    /** @return list<Teacher> */
    public function getAvailableTeachers(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);

        return $year === null ? [] : array_values($this->teachers->findByAcademicYearOrderedByName($year));
    }

    /** @return list<ResourceBookingStatus> */
    public function getStatuses(): array
    {
        return ResourceBookingStatus::cases();
    }

    /** @return Paginator<ResourceBooking> */
    public function getPagination(): Paginator
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        $user = $this->getUser();
        if ($year === null || !$user instanceof Teacher) {
            return Paginator::fromQuery($this->bookings->findNoneQuery(), 1, $this->appSettings->getInt('page.size'));
        }

        $managed = $this->scope === self::SCOPE_MANAGED && $this->canSeeOthers();

        $filters = new ResourceBookingFilters(
            categoryId: trim($this->categoryId),
            resourceId: trim($this->resourceId),
            // En «mis reservas» el docente es siempre el propio, diga lo que diga el filtro.
            teacherId: $managed ? trim($this->teacherId) : $user->getId()->toRfc4122(),
            groupId: trim($this->groupId),
            status: trim($this->status),
            dateFrom: $this->parseDate($this->dateFrom),
            dateTo: $this->parseDate($this->dateTo),
        );

        if (!$managed) {
            $query = $this->bookings->createListQuery($year, $filters, $user);
        } elseif ($this->access->isManagement($user, $this->centre)) {
            $query = $this->bookings->createListQuery($year, $filters);
        } else {
            $query = $this->bookings->createListQuery($year, $filters, $user, $this->access->managedCategories($user, $this->centre));
        }

        $pagination = $this->paginate($query);
        $this->bookings->preload($pagination->getItems());

        return $pagination;
    }

    public function hasActiveFilters(): bool
    {
        return $this->categoryId !== '' || $this->resourceId !== '' || $this->teacherId !== '' || $this->groupId !== ''
            || $this->status !== '' || $this->dateTo !== '' || $this->dateFrom !== $this->planner->today()->format('Y-m-d');
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ResourceBooking;
use App\Entity\ResourceCategory;
use App\Entity\Teacher;
use App\Repository\ResourceCategoryRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Quién gestiona las reservas: la dirección (administradores de la plataforma y del centro) gestiona todas;
 * los responsables de una categoría, las de los recursos de esa categoría; y cada docente, las suyas.
 */
final class ResourceBookingAccess implements ResetInterface
{
    /** @var array<string, list<ResourceCategory>> */
    private array $managed = [];

    public function __construct(
        private readonly ResourceCategoryRepository $categories,
    ) {}

    public function isManagement(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $teacher->isAdmin() || $centre->getAdmins()->contains($teacher);
    }

    public function managesCategory(Teacher $teacher, ResourceCategory $category): bool
    {
        return $this->isManagement($teacher, $category->getEducationalCentre()) || $category->isManagedBy($teacher);
    }

    /** Puede gestionar las reservas ajenas de este recurso (dirección o responsable de su categoría). */
    public function managesBooking(Teacher $teacher, ResourceBooking $booking): bool
    {
        return $this->managesCategory($teacher, $booking->getResource()->getCategory());
    }

    /** Puede editar o eliminar la reserva: es suya o la gestiona. */
    public function canEdit(Teacher $teacher, ResourceBooking $booking): bool
    {
        return $booking->getTeacher()->getId()->equals($teacher->getId()) || $this->managesBooking($teacher, $booking);
    }

    /**
     * Las categorías cuyas reservas gestiona el docente fuera de la dirección (sus responsabilidades).
     *
     * @return list<ResourceCategory>
     */
    public function managedCategories(Teacher $teacher, EducationalCentre $centre): array
    {
        $key = $teacher->getId()->toRfc4122() . '|' . $centre->getId()->toRfc4122();

        return $this->managed[$key] ??= $this->categories->findManagedBy($centre, $teacher);
    }

    /** Es dirección o responsable de alguna categoría del centro. */
    public function hasManagerRole(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $this->isManagement($teacher, $centre) || $this->managedCategories($teacher, $centre) !== [];
    }

    public function reset(): void
    {
        $this->managed = [];
    }
}

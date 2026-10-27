<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ResourceBooking;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Service\Feature;
use App\Service\FeatureToggle;
use App\Service\ResourceBookingAccess;
use App\Service\TeacherYearRoles;
use App\Service\TenantContextInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Reservas de recursos compartidos.
 *
 *  - BOOK (sujeto SharedResource): reservar el recurso. Cualquier docente del curso, salvo en las categorías
 *    restringidas (solo sus responsables y la dirección); nunca un recurso o categoría inactivos.
 *  - EDIT (sujeto ResourceBooking): editar o eliminar la reserva: su docente, los responsables de la categoría y la dirección.
 *  - MANAGE (sujeto ResourceBooking): gestionar las reservas ajenas y validarlas: responsables de la categoría y dirección.
 *
 * Con las reservas desactivadas en el centro, nadie.
 *
 * @extends Voter<string, SharedResource|ResourceBooking>
 */
final class ResourceBookingVoter extends Voter
{
    public const BOOK   = 'resource_booking.book';
    public const EDIT   = 'resource_booking.edit';
    public const MANAGE = 'resource_booking.manage';

    public function __construct(
        private readonly ResourceBookingAccess $access,
        private readonly FeatureToggle $features,
        private readonly TeacherYearRoles $roles,
        private readonly TenantContextInterface $tenant,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::BOOK            => $subject instanceof SharedResource,
            self::EDIT, self::MANAGE => $subject instanceof ResourceBooking,
            default               => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        if ($subject instanceof SharedResource) {
            $category = $subject->getCategory();
            $centre   = $category->getEducationalCentre();
            if (!$this->features->isEnabledForCentre(Feature::Bookings, $centre) || !$subject->isActive() || !$category->isActive()) {
                return false;
            }

            if ($this->access->managesCategory($user, $category)) {
                return true;
            }

            if ($category->isRestricted()) {
                return false;
            }

            $year = $this->tenant->getViewYear($centre);

            return $year !== null && $this->roles->belongsToYear($user, $year);
        }

        /** @var ResourceBooking $subject */
        $centre = $subject->getResource()->getCategory()->getEducationalCentre();
        if (!$this->features->isEnabledForCentre(Feature::Bookings, $centre)) {
            return false;
        }

        return match ($attribute) {
            self::MANAGE => $this->access->managesBooking($user, $subject),
            default      => $this->access->canEdit($user, $subject),
        };
    }
}

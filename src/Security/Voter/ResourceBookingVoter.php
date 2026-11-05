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
 *  - REPORT_ISSUE (sujeto ResourceBooking): registrar una incidencia: solo el docente de la reserva aprobada, y si no tiene
 *    ya una sin solucionar. No depende de la fecha: las incidencias se suelen registrar después de usar el recurso.
 *  - RESPOND_ISSUE (sujeto ResourceBooking): marcar como recibida o solucionada la incidencia abierta de una reserva:
 *    responsables de la categoría y dirección.
 *  - VIEW_ISSUE (sujeto ResourceBooking): ver la marca y el detalle de la incidencia: el docente de la reserva, los
 *    responsables de la categoría y la dirección (el resto del calendario no la ve).
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
    public const REPORT_ISSUE  = 'resource_booking.report_issue';
    public const RESPOND_ISSUE = 'resource_booking.respond_issue';
    public const VIEW_ISSUE    = 'resource_booking.view_issue';

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
            self::EDIT, self::MANAGE, self::REPORT_ISSUE, self::RESPOND_ISSUE, self::VIEW_ISSUE => $subject instanceof ResourceBooking,
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
            self::MANAGE        => $this->access->managesBooking($user, $subject),
            self::REPORT_ISSUE  => $subject->isApproved()
                && $subject->getTeacher()->getId()->equals($user->getId())
                && !$subject->hasOpenIssue(),
            self::RESPOND_ISSUE => $subject->hasOpenIssue() && $this->access->managesBooking($user, $subject),
            // Quien ve la reserva como propia o la gestiona es quien puede ver su incidencia.
            default             => $this->access->canEdit($user, $subject),
        };
    }
}

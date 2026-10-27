<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Service\AppSettingsInterface;
use App\Service\Feature;
use App\Service\FeatureToggle;
use App\Service\ResourceBookingAccess;
use App\Service\TeacherYearRoles;
use App\Service\TenantContextInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, EducationalCentre>
 */
final class EducationalCentreVoter extends Voter
{
    /** Access to the educational centre management section. Subject: EducationalCentre */
    public const SECTION = 'educational_centre.section';

    /**
     * Publish (create, edit and delete) school events. Management (global admins and the centre's
     * admins) and the centre's activity coordinators always can; the rest of the centre's teachers
     * only when the `events.publish_scope` setting is `all`, and then only their own activities.
     * Subject: EducationalCentre
     */
    public const PUBLISH_EVENTS = 'educational_centre.publish_events';

    /**
     * See, edit and delete every school event of the centre, whoever published it: management and the
     * activity coordinators. Subject: EducationalCentre
     */
    public const MANAGE_ALL_EVENTS = 'educational_centre.manage_all_events';

    /**
     * Access to the resource bookings section: the centre's teachers of the viewed year, its management, and the
     * managers of any resource category; never with the Bookings feature off. Subject: EducationalCentre
     */
    public const BOOKINGS = 'educational_centre.bookings';

    public function __construct(
        private readonly AppSettingsInterface $settings,
        private readonly TeacherYearRoles $roles,
        private readonly TenantContextInterface $tenant,
        private readonly FeatureToggle $features,
        private readonly ResourceBookingAccess $bookingAccess,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::SECTION, self::PUBLISH_EVENTS, self::MANAGE_ALL_EVENTS, self::BOOKINGS], true) && $subject instanceof EducationalCentre;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var EducationalCentre $subject */
        $isManagement = $user->isAdmin() || $subject->getAdmins()->contains($user);

        if ($attribute === self::SECTION) {
            return $isManagement;
        }

        if ($attribute === self::BOOKINGS) {
            if (!$this->features->isEnabledForCentre(Feature::Bookings, $subject)) {
                return false;
            }

            if ($this->bookingAccess->hasManagerRole($user, $subject)) {
                return true;
            }

            $year = $this->tenant->getViewYear($subject);

            return $year !== null && $this->roles->belongsToYear($user, $year);
        }

        // Con las actividades desactivadas en el centro nadie las publica ni las gestiona, ni siquiera la dirección.
        if (!$this->features->isEnabledForCentre(Feature::Events, $subject)) {
            return false;
        }

        // La dirección, los administradores y la coordinación de actividades gestionan todas las del centro.
        if ($isManagement || $subject->getActivityCoordinators()->contains($user)) {
            return true;
        }

        if ($attribute !== self::PUBLISH_EVENTS || $this->settings->getForCentre('events.publish_scope', $subject) !== 'all') {
            return false;
        }

        $year = $this->tenant->getViewYear($subject);

        return $year !== null && $this->roles->belongsToYear($user, $year);
    }
}

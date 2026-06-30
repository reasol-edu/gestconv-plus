<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\IncidentReport;
use App\Entity\Teacher;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, IncidentReport>
 */
final class IncidentReportVoter extends Voter
{
    public const VIEW      = 'incident_report.view';
    public const EDIT      = 'incident_report.edit';
    public const DELETE    = 'incident_report.delete';
    public const PRESCRIBE = 'incident_report.prescribe';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::PRESCRIBE], true)
            && $subject instanceof IncidentReport;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var IncidentReport $subject */

        // Global admin: always allowed
        if ($user->isAdmin()) {
            return true;
        }

        // Resolve the educational centre through the report's group
        $centre = $subject->getGroup()
            ->getProgrammeYear()
            ->getProgramme()
            ->getAcademicYear()
            ->getEducationalCentre();

        // Centre admin: always allowed
        if ($centre->getAdmins()->contains($user)) {
            return true;
        }

        // Non-admin, non-centre-admin
        return match ($attribute) {
            self::VIEW      => $subject->getRegisteredBy() === $user
                               || $subject->getGroup()->getTutors()->contains($user),
            self::EDIT      => $subject->getRegisteredBy() === $user,
            self::DELETE,
            self::PRESCRIBE => false,
            default         => false,
        };
    }
}

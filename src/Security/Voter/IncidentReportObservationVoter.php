<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\IncidentReportObservation;
use App\Entity\Teacher;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, IncidentReportObservation>
 */
final class IncidentReportObservationVoter extends Voter
{
    public const EDIT      = 'incident_report_observation.edit';
    public const EDIT_DATE = 'incident_report_observation.edit_date';
    public const DELETE    = 'incident_report_observation.delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::EDIT_DATE, self::DELETE], true)
            && $subject instanceof IncidentReportObservation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var IncidentReportObservation $subject */

        if ($user->isAdmin()) {
            return true;
        }

        $centre = $subject->getIncidentReport()->getGroup()->getAcademicYear()->getEducationalCentre();

        if ($centre->getAdmins()->contains($user)) {
            return true;
        }

        if ($attribute === self::EDIT_DATE) {
            return false;
        }

        if ($subject->getRegisteredBy() !== $user) {
            return false;
        }

        $editableUntil = $subject->getCreatedAt()->add(new \DateInterval('PT1H'));

        return new \DateTimeImmutable() < $editableUntil;
    }
}

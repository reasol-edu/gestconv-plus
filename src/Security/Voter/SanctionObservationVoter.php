<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SanctionObservation;
use App\Entity\Teacher;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, SanctionObservation>
 */
final class SanctionObservationVoter extends Voter
{
    public const EDIT      = 'sanction_observation.edit';
    public const EDIT_DATE = 'sanction_observation.edit_date';
    public const DELETE    = 'sanction_observation.delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EDIT, self::EDIT_DATE, self::DELETE], true)
            && $subject instanceof SanctionObservation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var SanctionObservation $subject */

        if ($user->isAdmin()) {
            return true;
        }

        $centre = $subject->getSanction()->getGroup()->getAcademicYear()?->getEducationalCentre();
        if ($centre === null) {
            return false;
        }

        if ($centre->getAdmins()->contains($user) || $centre->getCommitteeMembers()->contains($user)) {
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

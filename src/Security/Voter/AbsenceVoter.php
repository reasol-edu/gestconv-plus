<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Absence;
use App\Entity\Teacher;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Absence>
 */
final class AbsenceVoter extends Voter
{
    public const VIEW   = 'absence.view';
    public const EDIT   = 'absence.edit';
    public const DELETE = 'absence.delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Absence;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var Absence $subject */

        // Global admin: always allowed
        if ($user->isAdmin()) {
            return true;
        }

        // Owner: full access
        if ($subject->getTeacher() === $user) {
            return true;
        }

        // Centre admin: view-only oversight
        $centre = $subject->getAcademicYear()->getEducationalCentre();

        return $attribute === self::VIEW && $centre->getAdmins()->contains($user);
    }
}

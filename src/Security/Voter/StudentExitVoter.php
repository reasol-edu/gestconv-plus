<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EducationalCentre;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, StudentExit|EducationalCentre>
 */
final class StudentExitVoter extends Voter
{
    /** Subject: EducationalCentre. Cualquier docente del centro puede autorizar una salida. */
    public const AUTHORIZE   = 'student_exit.authorize';
    /** Subject: StudentExit. Quien la autorizó, tutores del grupo, equipo directivo y administración. */
    public const MARK_RETURNED = 'student_exit.mark_returned';
    /** Subject: EducationalCentre. Equipo directivo y administración: historial completo y pase prioritario. */
    public const MANAGE      = 'student_exit.manage';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::AUTHORIZE, self::MANAGE => $subject instanceof EducationalCentre,
            self::MARK_RETURNED           => $subject instanceof StudentExit,
            default                       => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        if ($attribute === self::MARK_RETURNED) {
            /** @var StudentExit $subject */
            $centre = $subject->getAcademicYear()->getEducationalCentre();

            return $user->isAdmin()
                || $centre->getAdmins()->contains($user)
                || $subject->getAuthorizedBy() === $user
                || $subject->getGroup()->getTutors()->contains($user);
        }

        /** @var EducationalCentre $subject */
        $isManagement = $user->isAdmin() || $subject->getAdmins()->contains($user);

        return match ($attribute) {
            self::AUTHORIZE => true,
            self::MANAGE    => $isManagement,
            default         => false,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SanctionTask;
use App\Entity\Teacher;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, SanctionTask>
 */
final class SanctionTaskVoter extends Voter
{
    public const VIEW = 'sanction_task.view';
    public const EDIT = 'sanction_task.edit';

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof SanctionTask;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var SanctionTask $subject */

        // Global admin or centre admin: full access over any task, not just their own
        $centre = $subject->getSanction()->getAcademicYear()->getEducationalCentre();
        if ($user->isAdmin() || $centre->getAdmins()->contains($user)) {
            return true;
        }

        $isAssignedTeacher = $subject->getGroupTeacher()->getTeacher() === $user;

        return match ($attribute) {
            self::EDIT => $isAssignedTeacher,
            self::VIEW => $isAssignedTeacher || $this->authorizationChecker->isGranted(SanctionVoter::VIEW, $subject->getSanction()),
            default    => false,
        };
    }
}

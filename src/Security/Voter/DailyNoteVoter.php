<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\DailyNote;
use App\Entity\Teacher;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, DailyNote>
 */
final class DailyNoteVoter extends Voter
{
    public const VIEW              = 'daily_note.view';
    public const EDIT_OBSERVATIONS = 'daily_note.edit_observations';
    public const DELETE_OWN        = 'daily_note.delete_own';
    public const MANAGE            = 'daily_note.manage';
    public const IGNORE            = 'daily_note.ignore';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT_OBSERVATIONS, self::DELETE_OWN, self::MANAGE, self::IGNORE], true)
            && $subject instanceof DailyNote;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var DailyNote $subject */

        $centre = $subject->getGroup()->getAcademicYear()->getEducationalCentre();

        if ($user->isAdmin() || $centre->getAdmins()->contains($user)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW              => $subject->getRegisteredBy() === $user
                                        || $subject->getGroup()->getTutors()->contains($user),
            self::EDIT_OBSERVATIONS,
            self::DELETE_OWN        => $subject->getRegisteredBy() === $user && $this->withinOwnWindow($subject),
            self::IGNORE            => $subject->getGroup()->getTutors()->contains($user),
            self::MANAGE            => false,
            default                 => false,
        };
    }

    private function withinOwnWindow(DailyNote $note): bool
    {
        $editableUntil = $note->getCreatedAt()->add(new \DateInterval('PT30M'));

        return $this->clock->now() < $editableUntil;
    }
}

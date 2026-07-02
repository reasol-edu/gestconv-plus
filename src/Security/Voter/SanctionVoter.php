<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Service\AppSettingsInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Sanction>
 */
final class SanctionVoter extends Voter
{
    public const VIEW   = 'sanction.view';
    public const CREATE = 'sanction.create';
    public const EDIT   = 'sanction.edit';
    public const DELETE = 'sanction.delete';
    public const NOTIFY = 'sanction.notify';

    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::NOTIFY], true)
            && $subject instanceof Sanction;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var Sanction $subject */

        if ($user->isAdmin()) {
            return true;
        }

        $centre = $subject->getGroup()
            ->getProgrammeYear()
            ->getProgramme()
            ->getAcademicYear()
            ->getEducationalCentre();

        if ($centre->getAdmins()->contains($user) || $centre->getCommitteeMembers()->contains($user)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $subject->getReports()->exists(
                static fn(int $k, \App\Entity\IncidentReport $r): bool =>
                    $r->getRegisteredBy() === $user
            ) || $subject->getGroup()->getTutors()->contains($user)
                || $centre->getCounselors()->contains($user),
            self::NOTIFY => match ($this->settings->getForCentre('notifications.sanction_notifier', $centre)) {
                'report_teacher' => $subject->getRegisteredBy() === $user,
                'group_tutor'    => $subject->getGroup()->getTutors()->contains($user),
                default          => $subject->getRegisteredBy() === $user
                                     || $subject->getGroup()->getTutors()->contains($user),
            },
            self::EDIT,
            self::DELETE => false,
            default      => false,
        };
    }
}

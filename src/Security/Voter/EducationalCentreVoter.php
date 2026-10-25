<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Service\AppSettingsInterface;
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
     * admins) always can; the rest of the centre's teachers only when the `events.publish_scope`
     * setting is `all`. Subject: EducationalCentre
     */
    public const PUBLISH_EVENTS = 'educational_centre.publish_events';

    public function __construct(
        private readonly AppSettingsInterface $settings,
        private readonly TeacherYearRoles $roles,
        private readonly TenantContextInterface $tenant,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::SECTION, self::PUBLISH_EVENTS], true) && $subject instanceof EducationalCentre;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        /** @var EducationalCentre $subject */
        if ($user->isAdmin() || $subject->getAdmins()->contains($user)) {
            return true;
        }

        if ($attribute !== self::PUBLISH_EVENTS || $this->settings->getForCentre('events.publish_scope', $subject) !== 'all') {
            return false;
        }

        $year = $this->tenant->getViewYear($subject);

        return $year !== null && $this->roles->belongsToYear($user, $year);
    }
}

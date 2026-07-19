<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\TimeSlotRepository;
use App\Service\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TenantContextExtension extends AbstractExtension
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Security $security,
        private readonly GroupRepository $groups,
        private readonly TimeSlotRepository $timeSlots,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('active_centre', $this->getActiveCentre(...)),
            new TwigFunction('can_switch_centre', $this->canSwitchCentre(...)),
            new TwigFunction('view_year', $this->getViewYear(...)),
            new TwigFunction('can_switch_year', $this->canSwitchYear(...)),
            new TwigFunction('is_viewing_past_year', $this->isViewingPastYear(...)),
            new TwigFunction('is_centre_admin', $this->isCentreAdmin(...)),
            new TwigFunction('belongs_to_view_year', $this->belongsToViewYear(...)),
            new TwigFunction('tutors_any_group', $this->tutorsAnyGroup(...)),
            new TwigFunction('teaches_any_group', $this->teachesAnyGroup(...)),
            new TwigFunction('is_guard_any_time_slot', $this->isGuardAnyTimeSlot(...)),
        ];
    }

    public function getActiveCentre(): ?EducationalCentre
    {
        return $this->context->getSelectedCentre();
    }

    public function canSwitchCentre(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        return $this->context->canSwitchCentre($user);
    }

    public function getViewYear(): ?AcademicYear
    {
        $centre = $this->context->getSelectedCentre();
        if ($centre === null) {
            return null;
        }

        return $this->context->getViewYear($centre);
    }

    public function canSwitchYear(): bool
    {
        return $this->isCentreAdmin();
    }

    public function isViewingPastYear(): bool
    {
        $centre = $this->context->getSelectedCentre();
        if ($centre === null) {
            return false;
        }

        return $this->context->isViewingNonActiveYear($centre);
    }

    public function isCentreAdmin(): bool
    {
        $centre = $this->context->getSelectedCentre();
        if ($centre === null) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        return $user->isAdmin() || $centre->getAdmins()->contains($user);
    }

    public function belongsToViewYear(): bool
    {
        $centre = $this->context->getSelectedCentre();
        if ($centre === null) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        $year = $this->context->getViewYear($centre);

        return $year !== null && $year->getTeachers()->contains($user);
    }

    public function tutorsAnyGroup(): bool
    {
        $centre = $this->context->getSelectedCentre();
        if ($centre === null) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        $year = $this->context->getViewYear($centre);

        return $year !== null && $this->groups->hasTutoredGroupsInYear($centre, $user, $year);
    }

    public function teachesAnyGroup(): bool
    {
        $centre = $this->context->getSelectedCentre();
        if ($centre === null) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        $year = $this->context->getViewYear($centre);

        return $year !== null && $this->groups->hasTeachingGroupsInYear($centre, $user, $year);
    }

    public function isGuardAnyTimeSlot(): bool
    {
        $centre = $this->context->getSelectedCentre();
        if ($centre === null) {
            return false;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            return false;
        }

        $year = $this->context->getViewYear($centre);

        return $year !== null && $this->timeSlots->hasGuardDutyInYear($centre, $user, $year);
    }
}

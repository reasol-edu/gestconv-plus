<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\Sanction;
use App\Repository\SanctionRepository;
use App\Service\CalendarMonthGridBuilder;
use App\Service\GroupColorPalette;
use App\Service\TenantContext;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

#[AsLiveComponent]
class CalendarComponent extends AbstractCalendarComponent
{
    /** @var list<Sanction>|null */
    private ?array $sanctionsCache = null;

    public function __construct(
        TenantContext $tenantContext,
        TranslatorInterface $translator,
        private readonly SanctionRepository $sanctionRepository,
        private readonly CalendarMonthGridBuilder $gridBuilder,
        private readonly GroupColorPalette $colorPalette,
    ) {
        parent::__construct($tenantContext, $translator);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWeeks(): array
    {
        $centre       = $this->getTenantContext()->getSelectedCentre();
        $academicYear = $centre !== null ? $this->getTenantContext()->getViewYear($centre) : null;
        if ($centre === null || $academicYear === null) {
            return [];
        }

        $sanctions = $this->getSanctionsForYear($academicYear);

        return $this->gridBuilder->build(
            $this->year,
            $this->month,
            $sanctions,
            static function (Sanction $sanction): ?array {
                $start = $sanction->getEffectiveFrom();
                if ($start === null) {
                    return null;
                }

                return [
                    'id'    => $sanction->getId()->toRfc4122(),
                    'start' => $start,
                    'end'   => $sanction->getEffectiveTo() ?? $start,
                ];
            },
            function (Sanction $sanction): array {
                $group = $sanction->getGroup();

                return [
                    'label'   => $sanction->getStudent()->getName()->full() . ' · ' . $group->getName(),
                    'details' => $sanction->getCalendarLabel() ?? trim(strip_tags($sanction->getDetails())),
                    'color'   => $this->colorPalette->colorFor($group->getId()->toRfc4122()),
                ];
            },
        );
    }

    /**
     * @return list<Sanction>
     */
    private function getSanctionsForYear(AcademicYear $academicYear): array
    {
        if ($this->sanctionsCache === null) {
            $this->sanctionsCache = $this->sanctionRepository->findWithDatesForAcademicYear($academicYear);
        }

        return $this->sanctionsCache;
    }
}

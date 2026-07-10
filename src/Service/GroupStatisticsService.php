<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Repository\GroupRepository;
use App\Repository\IncidentReportRepository;

/**
 * Builds the "Estadísticas por grupo" report: for every group of the given academic year,
 * grouped by programme, the incident/sanction figures within a date range, with aggregated
 * subtotals per programme and a grand total.
 */
class GroupStatisticsService
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly IncidentReportRepository $incidentReports,
    ) {}

    public function build(
        EducationalCentre $centre,
        AcademicYear $year,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): GroupStatisticsReport {
        $groups  = $this->groups->findByActiveYearOfCentreWithProgramme($centre, $year);
        $reports = $this->incidentReports->findForGroupStats($centre, $year, $from, $to);

        /** @var array<string, list<IncidentReport>> $reportsByGroup */
        $reportsByGroup = [];
        foreach ($reports as $report) {
            $reportsByGroup[$report->getGroup()->getId()->toRfc4122()][] = $report;
        }

        /** @var array<string, \App\Entity\Programme> $programmeById */
        $programmeById = [];
        /** @var array<string, list<Group>> $groupsByProgramme */
        $groupsByProgramme = [];
        /** @var array<string, list<IncidentReport>> $reportsByProgramme */
        $reportsByProgramme = [];

        foreach ($groups as $group) {
            $programme = $group->getProgrammeYear()->getProgramme();
            $pid       = $programme->getId()->toRfc4122();

            $programmeById[$pid] ??= $programme;
            $groupsByProgramme[$pid][] = $group;

            $groupReports = $reportsByGroup[$group->getId()->toRfc4122()] ?? [];
            foreach ($groupReports as $report) {
                $reportsByProgramme[$pid][] = $report;
            }
        }

        $programmes = [];
        foreach ($groupsByProgramme as $pid => $programmeGroups) {
            $rows = [];
            foreach ($programmeGroups as $group) {
                $rows[] = $this->buildRow($group, $reportsByGroup[$group->getId()->toRfc4122()] ?? []);
            }

            $programmes[] = new ProgrammeStatistics(
                $programmeById[$pid],
                $rows,
                $this->buildRow(null, $reportsByProgramme[$pid] ?? []),
            );
        }

        return new GroupStatisticsReport($from, $to, $programmes, $this->buildRow(null, $reports));
    }

    /**
     * @param list<IncidentReport> $reports
     */
    private function buildRow(?Group $group, array $reports): GroupStatisticsRow
    {
        $studentIds  = [];
        $sanctionIds = [];

        $reportsNormal = $reportsSerious = 0;
        $notifiedNormal = $notifiedSerious = 0;
        $sanctionedNormal = $sanctionedSerious = 0;
        $prescribedNormal = $prescribedSerious = 0;

        foreach ($reports as $report) {
            $studentIds[$report->getStudent()->getId()->toRfc4122()] = true;
            $serious = $this->isSerious($report);

            if ($serious) {
                ++$reportsSerious;
            } else {
                ++$reportsNormal;
            }

            if ($report->isNotified()) {
                if ($serious) {
                    ++$notifiedSerious;
                } else {
                    ++$notifiedNormal;
                }
            }

            $sanction = $report->getSanction();
            if ($sanction !== null) {
                if ($serious) {
                    ++$sanctionedSerious;
                } else {
                    ++$sanctionedNormal;
                }
                $sanctionIds[$sanction->getId()->toRfc4122()] = true;
            }

            if ($report->isPrescribed()) {
                if ($serious) {
                    ++$prescribedSerious;
                } else {
                    ++$prescribedNormal;
                }
            }
        }

        return new GroupStatisticsRow(
            $group,
            count($studentIds),
            $reportsNormal,
            $reportsSerious,
            $notifiedNormal,
            $notifiedSerious,
            $sanctionedNormal,
            $sanctionedSerious,
            $prescribedNormal,
            $prescribedSerious,
            count($sanctionIds),
        );
    }

    private function isSerious(IncidentReport $report): bool
    {
        foreach ($report->getBehaviors() as $behavior) {
            if ($behavior->getCategory()->isSerious()) {
                return true;
            }
        }

        return false;
    }
}

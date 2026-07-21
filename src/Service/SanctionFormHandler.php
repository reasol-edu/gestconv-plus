<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SanctionFormData;
use App\Dto\SanctionFormResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionMeasureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Caso de uso de alta/edición de sanciones: valida el formulario, aplica los
 * datos a la entidad, enlaza partes y dispara notificaciones y tareas.
 */
class SanctionFormHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanctionMeasureRepository $measures,
        private readonly IncidentReportRepository $reports,
        private readonly IncidentEmailNotifier $notifier,
        private readonly SanctionTaskGenerator $taskGenerator,
        private readonly TranslatorInterface $translator,
    ) {}

    public function validate(SanctionFormData $data, EducationalCentre $centre): SanctionFormResult
    {
        $errors = [];

        if ($data->reportIds === []) {
            $errors['reports'] = $this->t('sanction.error.no_reports');
        }

        if ($data->details === '') {
            $errors['details'] = $this->t('sanction.error.details_required');
        }

        if (!$data->noMeasure && $data->measureIds === []) {
            $errors['measures'] = $this->t('sanction.error.no_measures');
        }

        if ($data->noMeasure && $data->noMeasureReason === '') {
            $errors['no_measure_reason'] = $this->t('sanction.error.no_measure_reason_required');
        }

        $errors += $this->validateFollowup($data, $data->noMeasure);

        /** @var list<\App\Entity\SanctionMeasure> $selectedMeasures */
        $selectedMeasures = [];
        if (!$data->noMeasure) {
            foreach ($data->measureIds as $mid) {
                $m = $this->measures->findById($mid);
                if ($m !== null && $m->getEducationalCentre() === $centre) {
                    $selectedMeasures[] = $m;
                }
            }
        }

        $requiresDates = false;
        foreach ($selectedMeasures as $m) {
            if ($m->hasDateRange()) {
                $requiresDates = true;
                break;
            }
        }

        $effectiveFrom = null;
        $effectiveTo   = null;

        if ($requiresDates) {
            if ($data->effectiveFromRaw === '') {
                $errors['effective_from'] = $this->t('sanction.error.effective_from_required');
            } else {
                $effectiveFrom = \DateTimeImmutable::createFromFormat('Y-m-d', $data->effectiveFromRaw) ?: null;
                if ($effectiveFrom === null) {
                    $errors['effective_from'] = $this->t('sanction.error.effective_from_invalid');
                }
            }
            if ($data->effectiveToRaw === '') {
                $errors['effective_to'] = $this->t('sanction.error.effective_to_required');
            } else {
                $effectiveTo = \DateTimeImmutable::createFromFormat('Y-m-d', $data->effectiveToRaw) ?: null;
                if ($effectiveTo === null) {
                    $errors['effective_to'] = $this->t('sanction.error.effective_to_invalid');
                }
            }
        }

        return new SanctionFormResult($errors, $selectedMeasures, $requiresDates, $effectiveFrom, $effectiveTo);
    }

    /** @return array<string, string> */
    public function validateFollowup(SanctionFormData $data, bool $noMeasure): array
    {
        if (!$noMeasure && $data->familyClaimed === true && $data->familyClaimAttitude === '') {
            return ['family_claim_attitude' => $this->t('sanction.error.family_claim_attitude_required')];
        }

        return [];
    }

    /**
     * @return array{sanction: Sanction, linkedReports: list<IncidentReport>, tasks: list<SanctionTask>}
     */
    public function create(
        SanctionFormData $data,
        SanctionFormResult $result,
        Student $student,
        Group $group,
        Teacher $registeredBy,
    ): array {
        $sanction = (new Sanction())
            ->setAcademicYear($group->getAcademicYear())
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($registeredBy);

        $this->applyFormData($sanction, $data, $result);
        $this->applyFollowup($sanction, $data, $data->noMeasure);

        $this->em->persist($sanction);

        /** @var list<IncidentReport> $linkedReports */
        $linkedReports = [];
        foreach ($this->resolveLinkableReports($data->reportIds, $sanction) as $report) {
            $report->setSanction($sanction);
            $linkedReports[] = $report;
        }

        $this->em->flush();

        foreach ($linkedReports as $linkedReport) {
            $this->notifier->reportSanctioned($linkedReport, $registeredBy);
        }

        $tasks = $this->taskGenerator->generateFor($sanction);

        return ['sanction' => $sanction, 'linkedReports' => $linkedReports, 'tasks' => $tasks];
    }

    /**
     * Edición completa: reemplaza medidas y partes enlazados, notifica los partes
     * nuevos y regenera tareas si procede.
     *
     * @return array{previouslyLinkedIds: list<string>, currentLinkedIds: list<string>} ambos ordenados
     */
    public function update(Sanction $sanction, SanctionFormData $data, SanctionFormResult $result, Teacher $actor): array
    {
        $previouslyLinkedIds = array_map(
            static fn (IncidentReport $r): string => $r->getId()->toRfc4122(),
            $sanction->getReports()->toArray(),
        );

        $this->applyFormData($sanction, $data, $result);
        $this->applyFollowup($sanction, $data, $data->noMeasure);

        foreach ($sanction->getReports()->toArray() as $r) {
            /** @var IncidentReport $r */
            $r->setSanction(null);
        }

        /** @var list<IncidentReport> $newlyLinkedReports */
        $newlyLinkedReports = [];
        foreach ($this->resolveLinkableReports($data->reportIds, $sanction) as $report) {
            $report->setSanction($sanction);

            if (!in_array($report->getId()->toRfc4122(), $previouslyLinkedIds, true)) {
                $newlyLinkedReports[] = $report;
            }
        }

        $this->em->flush();

        foreach ($newlyLinkedReports as $newlyLinkedReport) {
            $this->notifier->reportSanctioned($newlyLinkedReport, $actor);
        }

        $this->taskGenerator->generateFor($sanction);

        $currentLinkedIds = array_map(
            static fn (IncidentReport $r): string => $r->getId()->toRfc4122(),
            $sanction->getReports()->toArray(),
        );
        sort($previouslyLinkedIds);
        sort($currentLinkedIds);

        return ['previouslyLinkedIds' => $previouslyLinkedIds, 'currentLinkedIds' => $currentLinkedIds];
    }

    /**
     * Edición de solo seguimiento (EDIT_FOLLOWUP): únicamente los campos posteriores
     * a la notificación; el resto de la sanción queda intacto.
     */
    public function updateFollowup(Sanction $sanction, SanctionFormData $data): void
    {
        $this->applyFollowup($sanction, $data, $sanction->isNoMeasureApplied());
        $this->em->flush();
    }

    private function applyFormData(Sanction $sanction, SanctionFormData $data, SanctionFormResult $result): void
    {
        $sanction->setDetails($data->details)
                 ->setCalendarLabel($data->calendarLabel)
                 ->setNoMeasureApplied($data->noMeasure)
                 ->setNoMeasureReason($data->noMeasure ? ($data->noMeasureReason ?: null) : null)
                 ->setEffectiveFrom($result->requiresDates ? $result->effectiveFrom : null)
                 ->setEffectiveTo($result->requiresDates ? $result->effectiveTo : null);

        foreach ($sanction->getMeasures()->toArray() as $m) {
            $sanction->removeMeasure($m);
        }
        foreach ($result->measures as $m) {
            $sanction->addMeasure($m);
        }
    }

    private function applyFollowup(Sanction $sanction, SanctionFormData $data, bool $noMeasure): void
    {
        $sanction->setMeasuresEffective($noMeasure ? null : $data->measuresEffective)
                 ->setFamilyClaimed($noMeasure ? null : $data->familyClaimed)
                 ->setFamilyClaimAttitude(!$noMeasure && $data->familyClaimed === true ? ($data->familyClaimAttitude ?: null) : null)
                 ->setRegisteredInSeneca($data->registeredInSeneca);
    }

    /**
     * Filtra los partes seleccionados a los realmente enlazables a esta sanción:
     * mismo alumno y grupo, no prescritos y sin otra sanción asociada.
     *
     * @param list<string> $reportIds
     * @return list<IncidentReport>
     */
    private function resolveLinkableReports(array $reportIds, Sanction $sanction): array
    {
        $linkable = [];
        foreach ($reportIds as $rid) {
            $report = $this->reports->findById($rid);
            if ($report === null
                || $report->getStudent() !== $sanction->getStudent()
                || $report->getGroup() !== $sanction->getGroup()
                || $report->isPrescribed()
                || ($report->getSanction() !== null && $report->getSanction() !== $sanction)) {
                continue;
            }
            $linkable[] = $report;
        }

        return $linkable;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}

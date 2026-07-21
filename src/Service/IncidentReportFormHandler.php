<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\IncidentReportFormData;
use App\Dto\IncidentReportFormResult;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentReport;
use App\Entity\LocationOption;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\LocationOptionRepository;
use App\Repository\StudentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Caso de uso de alta/edición de partes: valida el formulario común a ambos
 * flujos y crea los partes numerados en transacción.
 */
class IncidentReportFormHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IncidentReportRepository $reports,
        private readonly IncidentBehaviorRepository $behaviors,
        private readonly LocationOptionRepository $locations,
        private readonly StudentRepository $students,
        private readonly GroupRepository $groups,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * En el alta ($forCreation) exige alumnado seleccionado y, si no se indica
     * fecha, usa el momento actual; en edición una fecha vacía se ignora
     * (se conserva la existente).
     */
    public function validate(IncidentReportFormData $data, EducationalCentre $centre, bool $forCreation): IncidentReportFormResult
    {
        $errors = [];

        if ($forCreation && $data->studentPairs === []) {
            $errors['students'] = $this->t('incident.error.no_students');
        }

        $location = null;
        if ($data->locationId !== '') {
            $location = $this->locations->findById($data->locationId);
            if ($location === null || $location->getEducationalCentre() !== $centre) {
                $location = null;
            }
        }

        if ($location === null) {
            $errors['location'] = $this->t('incident.error.no_location');
        }

        if ($data->behaviorIds === []) {
            $errors['behaviors'] = $this->t('incident.error.no_behaviors');
        }

        if ($data->description === '') {
            $errors['description'] = $this->t('incident.error.description_required');
        }

        $occurredAt = null;
        if ($data->occurredAtRaw !== '') {
            try {
                $occurredAt = new \DateTimeImmutable($data->occurredAtRaw);
            } catch (\Exception) {
                $errors['occurred_at'] = $this->t('incident.field.occurred_at') . ' inválida.';
            }
        } elseif ($forCreation) {
            $occurredAt = new \DateTimeImmutable();
        }

        /** @var list<IncidentBehavior> $selectedBehaviors */
        $selectedBehaviors = [];
        foreach ($data->behaviorIds as $bid) {
            $b = $this->behaviors->findById($bid);
            if ($b !== null && $b->getEducationalCentre() === $centre) {
                $selectedBehaviors[] = $b;
            }
        }

        return new IncidentReportFormResult($errors, $location, $occurredAt, $selectedBehaviors);
    }

    /**
     * Crea un parte por cada par alumno::grupo válido, numerándolos en
     * transacción con bloqueo del curso académico.
     *
     * @param list<IncidentBehavior> $behaviors
     * @return list<IncidentReport>
     */
    public function create(
        IncidentReportFormData $data,
        EducationalCentre $centre,
        Teacher $registeredBy,
        LocationOption $location,
        \DateTimeImmutable $occurredAt,
        array $behaviors,
    ): array {
        $tasksCompleted = $data->tasksCompleted();

        /** @var list<IncidentReport> $createdReports */
        $createdReports = $this->em->wrapInTransaction(function () use (
            $data,
            $centre,
            $registeredBy,
            $location,
            $occurredAt,
            $behaviors,
            $tasksCompleted,
        ): array {
            /** @var array<string, int> $nextNumbers siguiente número por ID de curso académico */
            $nextNumbers = [];

            /** @var list<IncidentReport> $createdReports */
            $createdReports = [];

            foreach ($data->studentPairs as $pair) {
                $parts = explode('::', $pair, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                [$studentId, $groupId] = $parts;

                $student = $this->students->findById($studentId);
                $group   = $this->groups->findByIdAndCentre($groupId, $centre);

                if ($student === null || $group === null) {
                    continue;
                }

                $academicYear = $group->getAcademicYear();
                $yearKey      = $academicYear->getId()->toRfc4122();

                if (!array_key_exists($yearKey, $nextNumbers)) {
                    // El lock serializa los registros concurrentes del mismo curso:
                    // sin él, dos peticiones podrían leer el mismo MAX(number) y chocar
                    // contra el índice único uq_ir_year_number.
                    $this->em->lock($academicYear, LockMode::PESSIMISTIC_WRITE);
                    $nextNumbers[$yearKey] = $this->reports->nextNumberForYear($academicYear);
                } else {
                    $nextNumbers[$yearKey]++;
                }

                $report = new IncidentReport();
                $report->setAcademicYear($academicYear)
                       ->setNumber($nextNumbers[$yearKey])
                       ->setStudent($student)
                       ->setGroup($group)
                       ->setRegisteredBy($registeredBy)
                       ->setOccurredAt($occurredAt)
                       ->setLocation($location)
                       ->setDescription($data->description)
                       ->setExpelledFromClass($data->expelled)
                       ->setAssignedTasks($data->expelled ? $data->assignedTasks : null)
                       ->setTasksCompleted($tasksCompleted);

                foreach ($behaviors as $beh) {
                    $report->addBehavior($beh);
                }

                $this->em->persist($report);
                $createdReports[] = $report;
            }

            return $createdReports;
        });

        return $createdReports;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}

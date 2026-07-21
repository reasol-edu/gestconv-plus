<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\TasksCompletionStatus;
use Symfony\Component\HttpFoundation\Request;

final readonly class IncidentReportFormData
{
    /**
     * @param list<string> $studentPairs pares "studentId::groupId" (solo alta)
     * @param list<string> $behaviorIds
     */
    public function __construct(
        public array $studentPairs,
        public array $behaviorIds,
        public string $occurredAtRaw,
        public string $locationId,
        public string $description,
        public bool $expelled,
        public ?string $assignedTasks,
        public string $tasksCompletedRaw,
        public string $registeredByRaw,
        public string $studentGroupRaw,
        public string $prescribedAtRaw,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            studentPairs: array_values(array_filter($request->request->all('students'), 'is_string')),
            behaviorIds: array_values(array_filter($request->request->all('behaviors'), 'is_string')),
            occurredAtRaw: trim($request->request->getString('occurred_at')),
            locationId: trim($request->request->getString('location_id')),
            description: trim($request->request->getString('description')),
            expelled: $request->request->getString('expelled_from_class') === 'yes',
            assignedTasks: trim($request->request->getString('assigned_tasks')) ?: null,
            tasksCompletedRaw: $request->request->getString('tasks_completed'),
            registeredByRaw: trim($request->request->getString('registered_by')),
            studentGroupRaw: trim($request->request->getString('student_group')),
            prescribedAtRaw: trim($request->request->getString('prescribed_at')),
        );
    }

    public function tasksCompleted(): ?TasksCompletionStatus
    {
        if (!$this->expelled || $this->tasksCompletedRaw === '') {
            return null;
        }

        return TasksCompletionStatus::tryFrom($this->tasksCompletedRaw);
    }

    /** @return array<string, mixed> */
    public function toTemplateArray(): array
    {
        return [
            'students'       => $this->studentPairs,
            'behaviorIds'    => $this->behaviorIds,
            'occurredAt'     => $this->occurredAtRaw,
            'locationId'     => $this->locationId,
            'description'    => $this->description,
            'expelled'       => $this->expelled,
            'assignedTasks'  => $this->assignedTasks ?? '',
            'tasksCompleted' => $this->tasksCompletedRaw !== '' ? $this->tasksCompletedRaw : 'unknown',
        ];
    }
}

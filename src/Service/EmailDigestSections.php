<?php

declare(strict_types=1);

namespace App\Service;

/** Sections of the daily digest email and the section each notification event belongs to. */
final class EmailDigestSections
{
    /** Display order. */
    public const ORDER = ['prescription', 'reports', 'sanctions', 'tasks', 'notes'];

    /** @var array<string, string> */
    private const BY_EVENT = [
        'report_prescription_warning'   => 'prescription',
        'sanction_prescription_warning' => 'prescription',
        'report_created'                => 'reports',
        'report_notified'               => 'reports',
        'report_modified'               => 'reports',
        'report_deleted'                => 'reports',
        'report_prescribed'             => 'reports',
        'report_auto_prescribed'        => 'reports',
        'report_sanctioned'             => 'reports',
        'report_sanctionable_committee' => 'reports',
        'sanction_notified'             => 'sanctions',
        'sanction_prescribed'           => 'sanctions',
        'sanction_auto_prescribed'      => 'sanctions',
        'sanction_task_assigned'        => 'tasks',
        'sanction_task_reminder'        => 'tasks',
        'daily_note_threshold'          => 'notes',
    ];

    /** @return list<string> */
    public static function events(): array
    {
        return array_keys(self::BY_EVENT);
    }

    public static function forEvent(string $eventKey): string
    {
        return self::BY_EVENT[$eventKey] ?? 'reports';
    }
}

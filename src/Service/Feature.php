<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Secciones de la aplicación que un administrador global o de centro puede desactivar
 * (ajustes features.*, activados por defecto). Ver {@see FeatureToggle}.
 */
enum Feature: string
{
    case Exits         = 'exits';
    case Absences      = 'absences';
    case DailyNotes    = 'daily_notes';
    case SanctionTasks = 'sanction_tasks';
    case Events        = 'events';

    public function setting(): string
    {
        return 'features.' . $this->value;
    }

    /**
     * Prefijos de nombre de ruta que pertenecen a cada sección, para bloquearla entera.
     *
     * @return list<string>
     */
    public function routePrefixes(): array
    {
        return match ($this) {
            self::Exits         => ['app_exits', 'app_students_priority_pass'],
            self::Absences      => ['app_absences'],
            self::DailyNotes    => ['app_daily_notes'],
            self::SanctionTasks => ['app_sanction_tasks'],
            self::Events        => ['app_events'],
        };
    }

    /**
     * Prefijos de las rutas de los catálogos de la sección. Llevan el centro en la dirección
     * (/centro/{centreId}/…), que no tiene por qué ser el seleccionado, así que se comprueba ese.
     *
     * @return list<string>
     */
    public function catalogRoutePrefixes(): array
    {
        return match ($this) {
            self::Exits         => ['app_centre_exit_reasons'],
            self::DailyNotes    => ['app_centre_daily_note_types'],
            self::Events        => ['app_centre_school_event_types'],
            self::Absences,
            self::SanctionTasks => [],
        };
    }

    /**
     * Nombres de los Live Components de cada sección: sus actualizaciones llegan por una ruta
     * común (ux_live_component), así que se bloquean aparte para que no queden accesibles.
     *
     * @return list<string>
     */
    public function liveComponents(): array
    {
        return match ($this) {
            self::Exits         => ['ExitHistoryListComponent', 'StudentExitListComponent', 'ExitCurrentListComponent'],
            self::Absences      => ['Admin:AbsenceListComponent'],
            self::DailyNotes    => ['DailyNoteListComponent', 'DailyNoteStudentHistoryComponent', 'DailyNoteStudentListComponent'],
            self::SanctionTasks => [],
            self::Events        => ['SchoolEventListComponent'],
        };
    }
}

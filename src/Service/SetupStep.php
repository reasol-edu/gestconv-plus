<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Pasos de la lista de comprobación de inicio de curso (la del capítulo «Preparar el curso académico» del manual).
 * Casi todos se detectan solos a partir de los datos del curso activo; los que no se pueden detectar (revisar los
 * catálogos o los ajustes: los de serie siempre existen) los confirma a mano quien administra el centro.
 */
enum SetupStep: string
{
    case ActiveYear     = 'active_year';
    case Teachers       = 'teachers';
    case Students       = 'students';
    case Tutors         = 'tutors';
    case Catalogues     = 'catalogues';
    case GroupTeachers  = 'group_teachers';
    case TimeSlots      = 'time_slots';
    case NonWorkingDays = 'non_working_days';
    case Roles          = 'roles';
    case Settings       = 'settings';

    /** Se confirma a mano: no hay forma de saber desde los datos si el centro lo ha revisado. */
    public function isManual(): bool
    {
        return match ($this) {
            self::Catalogues, self::Settings => true,
            default => false,
        };
    }

    /** Imprescindible para dar el curso por preparado (los tramos horarios lo son además si el centro usa ausencias y guardias). */
    public function isRequiredByDefault(): bool
    {
        return match ($this) {
            self::ActiveYear, self::Teachers, self::Students, self::Tutors, self::Catalogues => true,
            default => false,
        };
    }

    /** Ruta de la pantalla donde se hace el paso; todas llevan `centreId`. */
    public function route(): string
    {
        return match ($this) {
            self::ActiveYear     => 'app_centre_years_index',
            self::Teachers       => 'app_centre_teachers_import',
            self::Students       => 'app_centre_students_import',
            self::Tutors         => 'app_centre_offer_index',
            self::Catalogues     => 'app_centre_incident_behaviors_index',
            self::GroupTeachers  => 'app_centre_teachers_import_assignments',
            self::TimeSlots      => 'app_centre_time_slots_index',
            self::NonWorkingDays => 'app_centre_non_working_days_index',
            self::Roles          => 'app_centre_profiles_index',
            self::Settings       => 'app_centre_settings',
        };
    }
}

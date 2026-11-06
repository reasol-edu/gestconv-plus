<?php

declare(strict_types=1);

namespace App\Entity;

/** Dónde se ofrecen las ubicaciones de una categoría: solo al registrar partes o en todos los sitios que usan ubicaciones. */
enum LocationScope: string
{
    /** Solo en los partes de convivencia («Dónde sucedió»). */
    case Reports = 'reports';

    /** En los partes, las incidencias (dependencia) y las actividades (sugerencias del lugar). */
    case All = 'all';
}

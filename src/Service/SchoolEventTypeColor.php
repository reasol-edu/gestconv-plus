<?php

declare(strict_types=1);

namespace App\Service;

/** Valida el color (#rrggbb) de un tipo de actividad. */
final class SchoolEventTypeColor
{
    /** El color en minúsculas si es un #rrggbb válido; null si no lo es (o está vacío). */
    public static function normalize(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $raw = strtolower(trim($raw));

        return preg_match('/^#[0-9a-f]{6}$/', $raw) === 1 ? $raw : null;
    }
}

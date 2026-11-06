<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Teacher;

/**
 * Cómo se escribe un docente en las exportaciones JSON: `{"username": "...", "name": "..."}`.
 *
 * El **usuario** (el usuario IdEA de Séneca) es la clave con la que se localiza al docente al importar, de modo que
 * el fichero sirve entre cursos académicos y entre despliegues distintos, donde los identificadores internos no
 * coinciden. El **nombre** es solo informativo: permite mostrar a quién se refiere una referencia aunque el usuario
 * no exista en el destino.
 *
 * Al leer se acepta también el formato anterior, un texto con solo el usuario.
 */
final class TeacherReference
{
    /** @return array{username: string, name: string} */
    public static function export(Teacher $teacher): array
    {
        return ['username' => $teacher->getUsername(), 'name' => $teacher->getName()->full()];
    }

    /**
     * El usuario de una referencia (texto o `{username, name}`), o null si no hay.
     */
    public static function username(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = $raw['username'] ?? null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        return $raw === '' ? null : $raw;
    }

    /** El nombre informativo de una referencia, o null si no lo trae. */
    public static function name(mixed $raw): ?string
    {
        if (!is_array($raw) || !is_string($raw['name'] ?? null)) {
            return null;
        }
        $name = trim($raw['name']);

        return $name === '' ? null : mb_substr($name, 0, 255);
    }
}

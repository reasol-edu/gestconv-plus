<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Qué se importa de la oferta formativa y qué se hace con lo que ya existe.
 *
 * Las tres primeras opciones deciden qué información de los grupos se aplica: con una desactivada, esa información
 * no se toca en los grupos que ya existen y queda vacía en los nuevos. `$replace` decide qué ocurre con los cursos y
 * grupos que ya existen (se emparejan por nombre): sustituir los datos seleccionados por los del fichero, o ignorarlos y
 * crear solo lo que falta.
 */
final readonly class OfferImportOptions
{
    public function __construct(
        public bool $tutors = true,
        public bool $subjects = true,
        public bool $color = true,
        public bool $replace = false,
    ) {}
}

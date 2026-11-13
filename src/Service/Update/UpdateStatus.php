<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Resultado de comparar la versión instalada con la última publicada.
 *
 * `state`: «newer» (hay una versión posterior; `version` y `url` dicen cuál), «current» (la instalada es la última o más
 * nueva), «unknown» (no se pudo consultar) o «disabled» (el ajuste lo desactiva: no se hizo ninguna petición).
 */
final readonly class UpdateStatus
{
    public const string NEWER    = 'newer';
    public const string CURRENT  = 'current';
    public const string UNKNOWN  = 'unknown';
    public const string DISABLED = 'disabled';

    public function __construct(
        public string $state,
        public ?string $version = null,
        public ?string $url = null,
    ) {}

    public function isNewer(): bool
    {
        return $this->state === self::NEWER;
    }
}

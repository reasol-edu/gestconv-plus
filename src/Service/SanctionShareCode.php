<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sanction;
use Symfony\Component\Clock\ClockInterface;

/**
 * Código del enlace público de las tareas de una sanción.
 *
 * Son 10 caracteres del alfabeto Base32 (RFC 4648: A-Z y 2-7), es decir 50 bits de entropía:
 * inviable de adivinar con el límite de intentos fallidos por IP, y corto para teclearlo a mano.
 * Se guarda sin guiones y se muestra agrupado de 5 en 5 (ABCDE-FGHJK).
 */
class SanctionShareCode
{
    public const LENGTH     = 10;
    public const GROUP_SIZE = 5;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    /** ABCDEFGHIJ → ABCDE-FGHIJ */
    public function format(string $code): string
    {
        return implode('-', str_split($code, self::GROUP_SIZE));
    }

    /**
     * Admite minúsculas, espacios y guiones (también los tipográficos), y corrige los dígitos
     * que se confunden con letras (0 → O, 1 → I). Devuelve null si no puede ser un código válido.
     */
    public function normalize(string $input): ?string
    {
        $code = strtoupper(preg_replace('/[\s\-‐‑‒–—]+/u', '', $input) ?? '');
        $code = strtr($code, ['0' => 'O', '1' => 'I']);

        return preg_match('/^[A-Z2-7]{' . self::LENGTH . '}$/', $code) === 1 ? $code : null;
    }

    /** El enlace sirve hasta el último día de la sanción incluido; sin fecha de fin no hay enlace válido. */
    public function isOpen(Sanction $sanction): bool
    {
        return $sanction->getShareCode() !== null && $this->canIssue($sanction);
    }

    /** Se puede crear o regenerar el enlace mientras la sanción tenga fecha de fin y no haya pasado. */
    public function canIssue(Sanction $sanction): bool
    {
        $until = $sanction->getEffectiveTo();

        return $until !== null && $this->clock->now()->format('Y-m-d') <= $until->format('Y-m-d');
    }
}

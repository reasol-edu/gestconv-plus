<?php

declare(strict_types=1);

namespace App\Service;

/** Formato de números para mostrar (horas en el generador de calendarios y la calculadora de fechas). */
final class NumberLabel
{
    /** 87.5 → "87,5", 100.0 → "100": hasta dos decimales, sin ceros finales; $thousands separa los millares. */
    public static function format(float $value, string $thousands = ''): string
    {
        $number = number_format($value, 2, ',', $thousands);

        return str_contains($number, ',') ? rtrim(rtrim($number, '0'), ',') : $number;
    }
}

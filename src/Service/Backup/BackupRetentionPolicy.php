<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Cuántas copias automáticas se conservan, como unas pocas opciones ya preparadas (el ajuste `backup.auto_policy`) en lugar
 * de tres números que afinar. Cada una conserva la copia más reciente de cada uno de los últimos N días, de las últimas N
 * semanas y de los últimos N meses **que tengan copia**: la copia nocturna es la única que se hace, las más antiguas son
 * simplemente las que sobreviven a la poda, así que una política nunca cuesta más de diarias + semanales + mensuales ficheros.
 */
enum BackupRetentionPolicy: string
{
    case Off         = 'off';
    case Basic       = 'basic';
    case Recommended = 'recommended';
    case Extended    = 'extended';

    public static function fromSetting(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Off) : self::Off;
    }

    public function isOn(): bool
    {
        return $this !== self::Off;
    }

    /** @return array{daily: int, weekly: int, monthly: int} */
    public function tiers(): array
    {
        return match ($this) {
            self::Off         => ['daily' => 0, 'weekly' => 0, 'monthly' => 0],
            self::Basic       => ['daily' => 7, 'weekly' => 0, 'monthly' => 0],
            self::Recommended => ['daily' => 7, 'weekly' => 4, 'monthly' => 3],
            self::Extended    => ['daily' => 14, 'weekly' => 8, 'monthly' => 12],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\StudentExit;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cuánto lleva fuera un estudiante, calculado siempre en el servidor con el reloj de la
 * aplicación: el navegador solo suma segundos desde esa cifra (no interpreta la hora de salida,
 * porque su reloj y su zona horaria pueden no coincidir con los del centro).
 */
final class ExitTiming
{
    /** Minutos fuera a partir de los cuales el aviso sube de nivel: ámbar, naranja, rojo. */
    public const LEVEL_MINUTES = [10, 20, 30];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {}

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** Segundos que lleva fuera (hasta ahora) o que estuvo fuera (si ya volvió). */
    public function seconds(StudentExit $exit): int
    {
        return $exit->secondsOut($this->clock->now());
    }

    /** 0 = normal; 1, 2, 3 = cada vez más urgente. Solo tiene sentido mientras sigue fuera. */
    public function level(int $seconds): int
    {
        $level = 0;
        foreach (self::LEVEL_MINUTES as $i => $minutes) {
            if ($seconds >= $minutes * 60) {
                $level = $i + 1;
            }
        }

        return $level;
    }

    /** «Hace 12 segundos», «Hace 5 minutos», «Hace 1 h 05 min». */
    public function ago(int $seconds): string
    {
        if ($seconds < 60) {
            return $this->translator->trans($seconds === 1 ? 'exit.ago.seconds_one' : 'exit.ago.seconds_other', ['%count%' => $seconds], 'admin');
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $this->translator->trans($minutes === 1 ? 'exit.ago.minutes_one' : 'exit.ago.minutes_other', ['%count%' => $minutes], 'admin');
        }

        return $this->translator->trans('exit.ago.hours', [
            '%hours%'   => intdiv($minutes, 60),
            '%minutes%' => sprintf('%02d', $minutes % 60),
        ], 'admin');
    }

    /** Duración total para columnas de tiempo fuera: «12 min», «1 h 05 min». */
    public function duration(int $seconds): string
    {
        $minutes = intdiv($seconds + 30, 60);
        if ($minutes < 60) {
            return $this->translator->trans('exit.duration.minutes', ['%minutes%' => $minutes], 'admin');
        }

        return $this->translator->trans('exit.duration.hours', [
            '%hours%'   => intdiv($minutes, 60),
            '%minutes%' => sprintf('%02d', $minutes % 60),
        ], 'admin');
    }

    /**
     * Plantillas de texto para el controlador de Stimulus que va sumando segundos.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [
            'secondsOne'   => $this->translator->trans('exit.ago.seconds_one', ['%count%' => '%count%'], 'admin'),
            'secondsOther' => $this->translator->trans('exit.ago.seconds_other', ['%count%' => '%count%'], 'admin'),
            'minutesOne'   => $this->translator->trans('exit.ago.minutes_one', ['%count%' => '%count%'], 'admin'),
            'minutesOther' => $this->translator->trans('exit.ago.minutes_other', ['%count%' => '%count%'], 'admin'),
            'hours'        => $this->translator->trans('exit.ago.hours', ['%hours%' => '%hours%', '%minutes%' => '%minutes%'], 'admin'),
        ];
    }

    /** Fecha de salida a mostrar: solo la hora si es de hoy; fecha y hora si no. */
    public function isToday(\DateTimeImmutable $at): bool
    {
        return $at->format('Y-m-d') === $this->clock->now()->format('Y-m-d');
    }
}

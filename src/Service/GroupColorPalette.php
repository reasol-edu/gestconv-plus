<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;

/**
 * Color de cada grupo, que se aplica a todo lo relacionado con él (barras del calendario, listados del
 * panel de inicio, guardias y tablón). Un grupo usa el color que le haya puesto la oferta formativa
 * (Group::getColor()) o, si no tiene, uno calculado de forma determinista a partir de su id: el mismo
 * grupo obtiene siempre el mismo color y los distintos grupos se distinguen entre sí.
 *
 * Del color base (#rrggbb) se derivan el fondo, el borde y el texto con la misma lógica para los dos
 * orígenes, de modo que cualquier color elegido da un contraste legible. Se aplican con estilos en
 * línea, porque un color personalizado no existe como clase de Tailwind.
 */
final class GroupColorPalette
{
    /** Colores base (tono 500 de Tailwind) entre los que se reparte el color por defecto. */
    private const array PALETTE = [
        '#3b82f6', // azul
        '#a855f7', // morado
        '#f59e0b', // ámbar
        '#ec4899', // rosa
        '#14b8a6', // verde azulado
        '#f43f5e', // rojo rosado
        '#6366f1', // índigo
        '#84cc16', // lima
        '#06b6d4', // cian
        '#f97316', // naranja
        '#d946ef', // fucsia
        '#10b981', // esmeralda
    ];

    /** Color base que se calcula para un grupo sin color personalizado. */
    public function defaultHex(string $groupId): string
    {
        return self::PALETTE[crc32($groupId) % count(self::PALETTE)];
    }

    /** Color base efectivo del grupo: el personalizado, o el calculado. */
    public function hexFor(Group $group): string
    {
        return $group->getColor() ?? $this->defaultHex($group->getId()->toRfc4122());
    }

    /**
     * @return array{hex: string, bg: string, text: string, border: string, style: string}
     */
    public function forGroup(Group $group): array
    {
        return $this->fromHex($this->hexFor($group));
    }

    /**
     * @param string|null $custom color personalizado del grupo (#rrggbb) si lo tiene
     *
     * @return array{hex: string, bg: string, text: string, border: string, style: string}
     */
    public function colorFor(string $groupId, ?string $custom = null): array
    {
        return $this->fromHex($custom ?? $this->defaultHex($groupId));
    }

    /**
     * @return array{hex: string, bg: string, text: string, border: string, style: string}
     */
    public function fromHex(string $hex): array
    {
        $bg     = $this->mix($hex, '#ffffff', 0.84);
        $text   = $this->mix($hex, '#000000', 0.55);
        $border = $this->mix($hex, '#ffffff', 0.55);

        return [
            'hex'    => $hex,
            'bg'     => $bg,
            'text'   => $text,
            'border' => $border,
            'style'  => sprintf('background-color:%s;color:%s;border-color:%s', $bg, $text, $border),
        ];
    }

    /** Mezcla $hex con $other: con $amount 0.84 resulta un 84 % de $other y un 16 % de $hex. */
    private function mix(string $hex, string $other, float $amount): string
    {
        $from = $this->rgb($hex);
        $to   = $this->rgb($other);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($from[0] + ($to[0] - $from[0]) * $amount),
            (int) round($from[1] + ($to[1] - $from[1]) * $amount),
            (int) round($from[2] + ($to[2] - $from[2]) * $amount),
        );
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}

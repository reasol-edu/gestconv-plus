<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

/**
 * Forma común de las observaciones de partes y sanciones, para poder
 * compartir la lógica de edición en un único servicio.
 */
interface ObservationInterface
{
    public function getId(): Uuid;

    public function getRegisteredBy(): Teacher;

    public function getRegisteredAt(): \DateTimeImmutable;

    public function getText(): string;

    public function getCreatedAt(): \DateTimeImmutable;

    public function setRegisteredAt(\DateTimeImmutable $registeredAt): static;

    public function setText(string $text): static;
}

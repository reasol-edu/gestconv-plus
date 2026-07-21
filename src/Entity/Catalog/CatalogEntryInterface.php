<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use App\Entity\EducationalCentre;
use Symfony\Component\Uid\Uuid;

/**
 * Contrato común a las entidades de entrada de los catálogos administrables
 * (IncidentBehavior, LocationOption, SanctionMeasure, CommunicationMethod).
 */
interface CatalogEntryInterface
{
    public function getId(): Uuid;

    public function getEducationalCentre(): EducationalCentre;

    public function setEducationalCentre(EducationalCentre $educationalCentre): static;

    public function getName(): string;

    public function setName(string $name): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function isActive(): bool;

    public function setActive(bool $active): static;
}

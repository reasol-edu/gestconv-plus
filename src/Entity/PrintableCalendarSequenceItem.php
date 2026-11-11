<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** One item of a PrintableCalendarSequence (e.g. «UD2»): a description and the hours it takes. */
#[ORM\Entity]
class PrintableCalendarSequenceItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PrintableCalendarSequence $sequence;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column]
    private float $hours;

    #[ORM\Column]
    private int $position;

    public function __construct(PrintableCalendarSequence $sequence, string $description, float $hours, int $position)
    {
        $this->sequence    = $sequence;
        $this->description = $description;
        $this->hours       = $hours;
        $this->position    = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getHours(): float
    {
        return $this->hours;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}

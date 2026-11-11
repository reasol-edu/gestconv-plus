<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A sequence of a PrintableCalendar (e.g. the didactic units of a module): a name, a start date, a
 * Monday-Friday hours pattern, two colours and an ordered list of items, each with its own number
 * of hours. The items are laid out one after another from the start date, walking the days like a
 * "from the start, with hours" period does, and painted with the two colours alternating so each
 * one is told apart — see PrintableCalendarSequenceScheduler and PrintableCalendarPdfBuilder.
 */
#[ORM\Entity]
class PrintableCalendarSequence
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'sequences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PrintableCalendar $calendar;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    /** Colour of the 1st, 3rd, 5th... item. */
    #[ORM\Column(length: 7)]
    private string $colorA;

    /** Colour of the 2nd, 4th, 6th... item. */
    #[ORM\Column(length: 7)]
    private string $colorB;

    #[ORM\Column(nullable: true)]
    private ?float $mondayHours = null;

    #[ORM\Column(nullable: true)]
    private ?float $tuesdayHours = null;

    #[ORM\Column(nullable: true)]
    private ?float $wednesdayHours = null;

    #[ORM\Column(nullable: true)]
    private ?float $thursdayHours = null;

    #[ORM\Column(nullable: true)]
    private ?float $fridayHours = null;

    /** Whether each day cell of the sequence shows the hours assigned to that day, or just the day number. */
    #[ORM\Column(options: ['default' => true])]
    private bool $showHours = true;

    /** Whether the sequence's items are listed in the journey summary above the calendar. */
    #[ORM\Column(options: ['default' => true])]
    private bool $showJourneySummary = true;

    /** Whether the days of the calendar's periods are skipped when laying the items out, like the non-working days. */
    #[ORM\Column(options: ['default' => false])]
    private bool $excludePeriods = false;

    #[ORM\Column]
    private int $position;

    /** @var Collection<int, PrintableCalendarSequenceItem> */
    #[ORM\OneToMany(targetEntity: PrintableCalendarSequenceItem::class, mappedBy: 'sequence', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    public function __construct(PrintableCalendar $calendar, string $name, \DateTimeImmutable $startDate, string $colorA, string $colorB, int $position)
    {
        $this->calendar  = $calendar;
        $this->name      = $name;
        $this->startDate = $startDate;
        $this->colorA    = $colorA;
        $this->colorB    = $colorB;
        $this->position  = $position;
        $this->items     = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getColorA(): string
    {
        return $this->colorA;
    }

    public function setColorA(string $colorA): static
    {
        $this->colorA = $colorA;

        return $this;
    }

    public function getColorB(): string
    {
        return $this->colorB;
    }

    public function setColorB(string $colorB): static
    {
        $this->colorB = $colorB;

        return $this;
    }

    /** The colour of the item at this 0-based index: the two colours alternate. */
    public function colorForIndex(int $index): string
    {
        return $index % 2 === 0 ? $this->colorA : $this->colorB;
    }

    /** @return array<int, ?float> hours for ISO weekday 1 (Monday) .. 5 (Friday) */
    public function weekdayHours(): array
    {
        return [
            1 => $this->mondayHours,
            2 => $this->tuesdayHours,
            3 => $this->wednesdayHours,
            4 => $this->thursdayHours,
            5 => $this->fridayHours,
        ];
    }

    /** @param array<int, ?float> $hours hours for ISO weekday 1 (Monday) .. 5 (Friday) */
    public function setWeekdayHours(array $hours): static
    {
        $this->mondayHours    = $hours[1] ?? null;
        $this->tuesdayHours   = $hours[2] ?? null;
        $this->wednesdayHours = $hours[3] ?? null;
        $this->thursdayHours  = $hours[4] ?? null;
        $this->fridayHours    = $hours[5] ?? null;

        return $this;
    }

    public function isShowHours(): bool
    {
        return $this->showHours;
    }

    public function setShowHours(bool $showHours): static
    {
        $this->showHours = $showHours;

        return $this;
    }

    public function isShowJourneySummary(): bool
    {
        return $this->showJourneySummary;
    }

    public function setShowJourneySummary(bool $showJourneySummary): static
    {
        $this->showJourneySummary = $showJourneySummary;

        return $this;
    }

    public function isExcludePeriods(): bool
    {
        return $this->excludePeriods;
    }

    public function setExcludePeriods(bool $excludePeriods): static
    {
        $this->excludePeriods = $excludePeriods;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** @return Collection<int, PrintableCalendarSequenceItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(string $description, float $hours): PrintableCalendarSequenceItem
    {
        $item = new PrintableCalendarSequenceItem($this, $description, $hours, $this->items->count());
        $this->items->add($item);

        return $item;
    }

    /** Drops every item: the sequence's items are replaced as a whole on each save, in their submitted order. */
    public function clearItems(): void
    {
        $this->items->clear();
    }
}

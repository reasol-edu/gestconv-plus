<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\PrintableCalendar;
use App\Entity\PrintableCalendarSequence;
use App\Entity\PrintableCalendarSequenceItem;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything the calendar generator does with its sequences outside the PDF: the rows of the form
 * (read, validate, show again), the data of an existing calendar (duplicate, export), the JSON
 * export/import and persisting them (create or reconcile on edit). Kept apart from the controller
 * so that the sequences — more involved than periods, with items inside — don't swell it further.
 *
 * The form rows (strings, as submitted) and the parsed sequences (typed, validated) are the two
 * shapes everything here converts between:
 *
 * @phpstan-type SequenceRow array{id: string, name: string, startDate: string, colorA: string, colorB: string, showHours: string, showJourneySummary: string, mondayHours: string, tuesdayHours: string, wednesdayHours: string, thursdayHours: string, fridayHours: string, items: list<array{description: string, hours: string}>, paste: string}
 * @phpstan-type ParsedSequence array{id: ?string, name: string, startDate: \DateTimeImmutable, colorA: string, colorB: string, showHours: bool, showJourneySummary: bool, weekdayHours: array<int, ?float>, items: list<array{description: string, hours: float}>}
 */
final class PrintableCalendarSequenceForm
{
    public const string DEFAULT_COLOR_A = '#bfdbfe';
    public const string DEFAULT_COLOR_B = '#fde68a';

    private const string COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    private const array WEEKDAY_KEYS = [1 => 'mondayHours', 2 => 'tuesdayHours', 3 => 'wednesdayHours', 4 => 'thursdayHours', 5 => 'fridayHours'];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly PrintableCalendarSequenceScheduler $scheduler,
    ) {}

    /**
     * The dates each item of a sequence would get, for the form to show while it's being filled in
     * (nothing is saved or validated beyond what's needed to calculate): the very same scheduling
     * the PDF uses. Everything comes as the raw text of the fields; an item without valid hours (or
     * a sequence without a valid start date or hours on any weekday) simply gets no dates.
     *
     * @param array<int, string> $weekdayHours text of the hours of each ISO weekday 1..5
     * @param list<string>       $itemHours    text of the hours of each item, in order
     *
     * @return array{items: list<?string>, summary: ?string, warning: ?string} each item's «dd/mm/yyyy - dd/mm/yyyy» (or one date), a line with the total and the end date, and a warning if the hours don't fit
     */
    public function preview(AcademicYear $year, string $startDate, array $weekdayHours, array $itemHours): array
    {
        $none  = ['items' => array_map(static fn (string $hours): ?string => null, $itemHours), 'summary' => null, 'warning' => null];
        $start = self::parseDate(trim($startDate));
        $week  = array_map(static fn (string $hours): ?float => self::parseNumber($hours), $weekdayHours);
        if ($start === null || !self::hasPositiveHours($week)) {
            return $none;
        }

        // Only the items with valid hours take part; each keeps its position in the list.
        $valid = [];
        foreach ($itemHours as $i => $text) {
            $hours = self::parseNumber($text);
            if ($hours !== null && $hours > 0.0) {
                $valid[$i] = $hours;
            }
        }
        if ($valid === []) {
            return $none;
        }

        $schedule = $this->scheduler->schedule($year, $start, $week, array_values($valid));
        $items    = $none['items'];
        $end      = null;
        foreach (array_keys($valid) as $n => $i) {
            $from = $schedule['items'][$n]['startDate'];
            $to   = $schedule['items'][$n]['endDate'];
            if ($from === null || $to === null) {
                continue;
            }
            $items[$i] = $from->format('d/m/Y') . ($to == $from ? '' : ' - ' . $to->format('d/m/Y'));
            $end       = $to;
        }

        $total = array_sum($valid);

        return [
            'items'   => array_values($items),
            'summary' => $end === null ? null : $this->translator->trans('form.sequences.preview_summary', [
                '%hours%' => $this->translator->trans('pdf.hours', ['%count%' => $total, '%hours%' => NumberLabel::format($total)], 'utilities'),
                '%date%'  => $end->format('d/m/Y'),
            ], 'utilities'),
            'warning' => $schedule['completed'] ? null : $this->t('form.sequences.preview_incomplete'),
        ];
    }

    /** @return SequenceRow a new, empty row of the form */
    public function blankRow(): array
    {
        return [
            'id' => '', 'name' => '', 'startDate' => '', 'colorA' => self::DEFAULT_COLOR_A, 'colorB' => self::DEFAULT_COLOR_B,
            'showHours' => '1', 'showJourneySummary' => '1',
            'mondayHours' => '', 'tuesdayHours' => '', 'wednesdayHours' => '', 'thursdayHours' => '', 'fridayHours' => '',
            'items' => [], 'paste' => '',
        ];
    }

    /** @return list<SequenceRow> */
    public function rowsFor(PrintableCalendar $calendar): array
    {
        $rows = [];
        foreach ($calendar->getSequences() as $sequence) {
            $row = [
                'id'                 => $sequence->getId()->toRfc4122(),
                'name'               => $sequence->getName(),
                'startDate'          => $sequence->getStartDate()->format('Y-m-d'),
                'colorA'             => $sequence->getColorA(),
                'colorB'             => $sequence->getColorB(),
                'showHours'          => $sequence->isShowHours() ? '1' : '',
                'showJourneySummary' => $sequence->isShowJourneySummary() ? '1' : '',
            ] + array_map(self::number(...), array_combine(array_values(self::WEEKDAY_KEYS), $sequence->weekdayHours()));
            $row['items'] = array_map(
                static fn (PrintableCalendarSequenceItem $item): array => ['description' => $item->getDescription(), 'hours' => NumberLabel::format($item->getHours())],
                array_values($sequence->getItems()->toArray()),
            );
            $row['paste'] = '';
            /** @var SequenceRow $row */
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Reads and validates the submitted sequences. A row left completely blank is ignored; the
     * «Pegar elementos» text (one `description; hours` per line, or tab-separated as a spreadsheet
     * pastes it) is turned into items appended to the sequence's own.
     *
     * @param array<array-key, mixed> $rawSequences
     *
     * @return array{0: list<SequenceRow>, 1: list<ParsedSequence>, 2: array<string, string>} the rows to show again, the valid sequences and the errors
     */
    public function read(array $rawSequences): array
    {
        $rows   = [];
        $parsed = [];
        $errors = [];

        foreach ($rawSequences as $raw) {
            if (!\is_array($raw) || ($raw['remove'] ?? '') === '1') {
                continue;
            }

            $row = $this->blankRow();
            foreach (['id', 'name', 'startDate', 'colorA', 'colorB', 'mondayHours', 'tuesdayHours', 'wednesdayHours', 'thursdayHours', 'fridayHours'] as $field) {
                $row[$field] = \is_string($raw[$field] ?? null) ? trim($raw[$field]) : $row[$field];
            }
            $row['showHours']          = ($raw['showHours'] ?? '') === '1' ? '1' : '';
            $row['showJourneySummary'] = ($raw['showJourneySummary'] ?? '') === '1' ? '1' : '';

            $items = [];
            $itemError = false;
            foreach (\is_array($raw['items'] ?? null) ? $raw['items'] : [] as $rawItem) {
                if (!\is_array($rawItem)) {
                    continue;
                }
                $description = \is_string($rawItem['description'] ?? null) ? trim($rawItem['description']) : '';
                $hours       = \is_string($rawItem['hours'] ?? null) ? trim($rawItem['hours']) : '';
                if ($description === '' && $hours === '') {
                    continue;
                }
                $items[] = ['description' => $description, 'hours' => $hours];
            }
            $paste = \is_string($raw['paste'] ?? null) ? $raw['paste'] : '';
            foreach ($this->parsePaste($paste) as $line) {
                $items[] = $line;
                $itemError = $itemError || self::parseNumber($line['hours']) === null;
            }
            $row['items'] = $items;

            // Entirely blank: the spare row of the form, nobody filled it in.
            if ($row['id'] === '' && $row['name'] === '' && $row['startDate'] === '' && $items === [] && trim($paste) === ''
                && array_filter(array_intersect_key($row, array_flip(self::WEEKDAY_KEYS))) === []) {
                continue;
            }
            $rows[] = $row;

            $startDate = self::parseDate($row['startDate']);
            if ($row['name'] === '' || mb_strlen($row['name']) > 255 || $startDate === null || !self::validColor($row['colorA']) || !self::validColor($row['colorB'])) {
                $errors['sequences'] = $this->t('form.error.sequence_fields');

                continue;
            }

            $weekdayHours = [];
            foreach (self::WEEKDAY_KEYS as $iso => $field) {
                $weekdayHours[$iso] = self::parseNumber($row[$field]);
            }
            if (!self::hasPositiveHours($weekdayHours)) {
                $errors['sequences'] = $this->t('form.error.sequence_hours');

                continue;
            }

            $parsedItems = [];
            $valid       = $items !== [] && !$itemError;
            foreach ($items as $item) {
                $hours = self::parseNumber($item['hours']);
                if ($item['description'] === '' || mb_strlen($item['description']) > 255 || $hours === null || $hours <= 0.0) {
                    $valid = false;

                    break;
                }
                $parsedItems[] = ['description' => $item['description'], 'hours' => $hours];
            }
            if (!$valid) {
                $errors['sequences'] = $this->t('form.error.sequence_items');

                continue;
            }

            $parsed[] = [
                'id'                 => $row['id'] !== '' ? $row['id'] : null,
                'name'               => $row['name'],
                'startDate'          => $startDate,
                'colorA'             => $row['colorA'],
                'colorB'             => $row['colorB'],
                'showHours'          => $row['showHours'] === '1',
                'showJourneySummary' => $row['showJourneySummary'] === '1',
                'weekdayHours'       => $weekdayHours,
                'items'              => $parsedItems,
            ];
        }

        return [$rows, $parsed, $errors];
    }

    /**
     * «UD1; 55», «UD2 ⇥ 10» (tab, as pasted from a spreadsheet): the text before the LAST separator
     * is the description and what follows, the hours. A comma is NOT a separator, since «7,5» is a
     * decimal number. A line without hours keeps an empty value so the validation reports it.
     *
     * @return list<array{description: string, hours: string}>
     */
    private function parsePaste(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cut = max((int) strrpos($line, ';'), (int) strrpos($line, "\t"));
            if ($cut > 0) {
                $lines[] = ['description' => trim(substr($line, 0, $cut)), 'hours' => trim(substr($line, $cut + 1))];
            } else {
                $lines[] = ['description' => $line, 'hours' => ''];
            }
        }

        return $lines;
    }

    /** @return list<ParsedSequence> the sequences of an existing calendar, to duplicate or export it */
    public function dataFromCalendar(PrintableCalendar $calendar): array
    {
        $data = [];
        foreach ($calendar->getSequences() as $sequence) {
            $data[] = [
                'id'                 => null,
                'name'               => $sequence->getName(),
                'startDate'          => $sequence->getStartDate(),
                'colorA'             => $sequence->getColorA(),
                'colorB'             => $sequence->getColorB(),
                'showHours'          => $sequence->isShowHours(),
                'showJourneySummary' => $sequence->isShowJourneySummary(),
                'weekdayHours'       => $sequence->weekdayHours(),
                'items'              => array_map(
                    static fn (PrintableCalendarSequenceItem $item): array => ['description' => $item->getDescription(), 'hours' => $item->getHours()],
                    array_values($sequence->getItems()->toArray()),
                ),
            ];
        }

        return $data;
    }

    /**
     * @param list<ParsedSequence> $sequences
     *
     * @return list<array<string, mixed>>
     */
    public function exportPayload(array $sequences): array
    {
        return array_map(static fn (array $s): array => [
            'name'               => $s['name'],
            'startDate'          => $s['startDate']->format('Y-m-d'),
            'colorA'             => $s['colorA'],
            'colorB'             => $s['colorB'],
            'showHours'          => $s['showHours'],
            'showJourneySummary' => $s['showJourneySummary'],
            'mondayHours'        => $s['weekdayHours'][1],
            'tuesdayHours'       => $s['weekdayHours'][2],
            'wednesdayHours'     => $s['weekdayHours'][3],
            'thursdayHours'      => $s['weekdayHours'][4],
            'fridayHours'        => $s['weekdayHours'][5],
            'items'              => $s['items'],
        ], $sequences);
    }

    /**
     * The inverse of exportPayload(), from an untrusted decoded JSON value: validates everything
     * from scratch. A file without the key (exported before sequences existed) has none. Returns
     * null when anything is invalid.
     *
     * @return list<ParsedSequence>|null
     */
    public function parseImport(mixed $raw): ?array
    {
        if ($raw === null) {
            return [];
        }
        if (!\is_array($raw)) {
            return null;
        }

        $sequences = [];
        foreach ($raw as $s) {
            if (!\is_array($s)) {
                return null;
            }
            $name      = \is_string($s['name'] ?? null) ? trim($s['name']) : '';
            $startDate = \is_string($s['startDate'] ?? null) ? self::parseDate($s['startDate']) : null;
            $colorA    = $s['colorA'] ?? null;
            $colorB    = $s['colorB'] ?? null;
            if ($name === '' || mb_strlen($name) > 255 || $startDate === null
                || !\is_string($colorA) || !self::validColor($colorA) || !\is_string($colorB) || !self::validColor($colorB)) {
                return null;
            }
            foreach (['showHours', 'showJourneySummary'] as $flag) {
                if (isset($s[$flag]) && !\is_bool($s[$flag])) {
                    return null;
                }
            }

            $weekdayHours = [];
            foreach (self::WEEKDAY_KEYS as $iso => $field) {
                $value = $s[$field] ?? null;
                if ($value !== null && !\is_int($value) && !\is_float($value)) {
                    return null;
                }
                $weekdayHours[$iso] = $value === null ? null : (float) $value;
            }
            if (!self::hasPositiveHours($weekdayHours) || !\is_array($s['items'] ?? null) || $s['items'] === []) {
                return null;
            }

            $items = [];
            foreach ($s['items'] as $item) {
                $description = \is_array($item) && \is_string($item['description'] ?? null) ? trim($item['description']) : '';
                $hours       = \is_array($item) && (\is_int($item['hours'] ?? null) || \is_float($item['hours'] ?? null)) ? (float) $item['hours'] : 0.0;
                if ($description === '' || mb_strlen($description) > 255 || $hours <= 0.0) {
                    return null;
                }
                $items[] = ['description' => $description, 'hours' => $hours];
            }

            $sequences[] = [
                'id'                 => null,
                'name'               => $name,
                'startDate'          => $startDate,
                'colorA'             => $colorA,
                'colorB'             => $colorB,
                'showHours'          => ($s['showHours'] ?? true) === true,
                'showJourneySummary' => ($s['showJourneySummary'] ?? true) === true,
                'weekdayHours'       => $weekdayHours,
                'items'              => $items,
            ];
        }

        return $sequences;
    }

    /** @param list<ParsedSequence> $sequences */
    public function apply(PrintableCalendar $calendar, array $sequences): void
    {
        foreach ($sequences as $entry) {
            $this->fill($calendar->addSequence($entry['name'], $entry['startDate'], $entry['colorA'], $entry['colorB']), $entry);
        }
    }

    /**
     * Keeps the sequences already saved that are still submitted (updated in place, in the order
     * submitted), adds the new ones and drops the rest. Their items are replaced as a whole.
     *
     * @param list<ParsedSequence> $sequences
     */
    public function reconcile(PrintableCalendar $calendar, array $sequences): void
    {
        $existing = [];
        foreach ($calendar->getSequences() as $sequence) {
            $existing[$sequence->getId()->toRfc4122()] = $sequence;
        }

        $kept     = [];
        $position = 0;
        foreach ($sequences as $entry) {
            if ($entry['id'] !== null && isset($existing[$entry['id']])) {
                $sequence = $existing[$entry['id']];
                $kept[]   = $entry['id'];
                $sequence->setName($entry['name'])->setStartDate($entry['startDate'])->setColorA($entry['colorA'])->setColorB($entry['colorB']);
                $sequence->clearItems();
            } else {
                $sequence = $calendar->addSequence($entry['name'], $entry['startDate'], $entry['colorA'], $entry['colorB']);
            }
            $sequence->setPosition($position++);
            $this->fill($sequence, $entry);
        }

        foreach ($existing as $id => $sequence) {
            if (!\in_array($id, $kept, true)) {
                $calendar->getSequences()->removeElement($sequence);
            }
        }
    }

    /** @param ParsedSequence $entry */
    private function fill(PrintableCalendarSequence $sequence, array $entry): void
    {
        $sequence->setShowHours($entry['showHours'])
            ->setShowJourneySummary($entry['showJourneySummary'])
            ->setWeekdayHours($entry['weekdayHours']);
        foreach ($entry['items'] as $item) {
            $sequence->addItem($item['description'], $item['hours']);
        }
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'utilities');
    }

    private static function validColor(string $value): bool
    {
        return preg_match(self::COLOR_PATTERN, $value) === 1;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }

    /** "4,5" or "4.5" → 4.5; anything else → null. */
    private static function parseNumber(string $text): ?float
    {
        $text = str_replace([' ', ','], ['', '.'], trim($text));

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    /** @param array<int, ?float> $weekdayHours */
    private static function hasPositiveHours(array $weekdayHours): bool
    {
        foreach ($weekdayHours as $hours) {
            if ($hours !== null && $hours > 0.0) {
                return true;
            }
        }

        return false;
    }

    private static function number(?float $value): string
    {
        return $value === null ? '' : NumberLabel::format($value);
    }
}

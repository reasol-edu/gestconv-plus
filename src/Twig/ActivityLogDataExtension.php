<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Turns the arbitrary JSON `data` payload of an ActivityLog entry into a readable list of
 * label/value rows for the admin activity log screen, instead of dumping raw JSON.
 */
final class ActivityLogDataExtension extends AbstractExtension
{
    /**
     * Keys whose values are enum-like strings stored raw in the log: translation key prefix and domain.
     * Unknown values fall back to the raw string.
     */
    private const VALUE_TRANSLATIONS = [
        'result'         => ['notification.result.', 'notifications'],
        'source'         => ['activity_log.value.source.', 'admin'],
        'tasksCompleted' => ['incident.tasks_completed.', 'admin'],
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('activity_log_rows', $this->formatRows(...)),
        ];
    }

    /**
     * @param array<string, mixed>|null $data
     * @return list<array{type: 'change', label: string, before: string, after: string}|array{type: 'field', label: string, value: string}>
     */
    public function formatRows(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $rows = [];

        $changes = $data['changes'] ?? null;
        if (is_array($changes)) {
            foreach ($changes as $field => $change) {
                if (!is_array($change)) {
                    continue;
                }

                $rows[] = [
                    'type'   => 'change',
                    'label'  => $this->fieldLabel((string) $field, ['activity_log.change.', 'activity_log.field.']),
                    'before' => $this->formatValue($change['before'] ?? null, (string) $field),
                    'after'  => $this->formatValue($change['after'] ?? null, (string) $field),
                ];
            }
        }

        foreach ($data as $key => $value) {
            if ($key === 'changes') {
                continue;
            }

            $rows[] = [
                'type'  => 'field',
                'label' => $this->fieldLabel((string) $key, ['activity_log.field.']),
                'value' => $this->formatValue($value, (string) $key),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $prefixes translation key prefixes to try, in order
     */
    private function fieldLabel(string $key, array $prefixes): string
    {
        foreach ($prefixes as $prefix) {
            $translationKey = $prefix . $key;
            $label          = $this->translator->trans($translationKey, [], 'admin');
            if ($label !== $translationKey) {
                return $label;
            }
        }

        return $this->humanize($key);
    }

    private function humanize(string $key): string
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $key));
        $spaced = is_string($spaced) ? mb_strtolower($spaced) : mb_strtolower($key);

        return ucfirst(trim($spaced));
    }

    private function formatValue(mixed $value, string $key): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $this->translator->trans($value ? 'activity_log.value.yes' : 'activity_log.value.no', [], 'admin');
        }

        if (is_array($value)) {
            if ($value === []) {
                return '—';
            }

            $items = array_map(fn (mixed $item): string => $this->formatValue($item, $key), $value);
            if (count($items) > 5) {
                return implode(', ', array_slice($items, 0, 5)) . sprintf(' … (+%d)', count($items) - 5);
            }

            return implode(', ', $items);
        }

        if (!is_scalar($value)) {
            return '';
        }

        $string = (string) $value;

        if (isset(self::VALUE_TRANSLATIONS[$key])) {
            [$prefix, $domain] = self::VALUE_TRANSLATIONS[$key];
            $translated        = $this->translator->trans($prefix . $string, [], $domain);
            if ($translated !== $prefix . $string) {
                return $translated;
            }
        }

        return $this->formatDate($string) ?? $string;
    }

    /** Dates are stored as ATOM strings (see EntityChangeTracker); show them in the app's format. */
    private function formatDate(string $value): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);
        if ($date === false) {
            return null;
        }

        $dateOnly = $date->format('H:i:s') === '00:00:00';

        return $date->format($this->translator->trans($dateOnly ? 'format.date' : 'format.datetime'));
    }
}

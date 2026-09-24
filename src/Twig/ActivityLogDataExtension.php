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
                    'label'  => $this->fieldLabel((string) $field),
                    'before' => $this->formatValue($change['before'] ?? null),
                    'after'  => $this->formatValue($change['after'] ?? null),
                ];
            }
        }

        foreach ($data as $key => $value) {
            if ($key === 'changes') {
                continue;
            }

            $rows[] = [
                'type'  => 'field',
                'label' => $this->fieldLabel((string) $key),
                'value' => $this->formatValue($value),
            ];
        }

        return $rows;
    }

    private function fieldLabel(string $key): string
    {
        $translationKey = 'activity_log.field.' . $key;
        $label          = $this->translator->trans($translationKey, [], 'admin');

        return $label !== $translationKey ? $label : $this->humanize($key);
    }

    private function humanize(string $key): string
    {
        $spaced = preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $key));
        $spaced = is_string($spaced) ? mb_strtolower($spaced) : mb_strtolower($key);

        return ucfirst(trim($spaced));
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Sí' : 'No';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '—';
            }

            $items = array_map($this->formatValue(...), $value);
            if (count($items) > 5) {
                return implode(', ', array_slice($items, 0, 5)) . sprintf(' … (+%d)', count($items) - 5);
            }

            return implode(', ', $items);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}

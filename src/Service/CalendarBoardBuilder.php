<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Construye la vista de "modo tablón" del calendario: para cada día visible
 * agrupa las sanciones que lo cubren por grupo, de forma independiente de
 * cómo se hayan obtenido esos datos (sin depender de entidades Doctrine).
 */
final class CalendarBoardBuilder
{
    public function __construct(
        private readonly GroupColorPalette $colorPalette,
    ) {}

    /**
     * @param list<array{groupId: string, groupName: string, student: string, details: string, from: \DateTimeImmutable, to: ?\DateTimeImmutable}> $items
     * @param list<\DateTimeImmutable>                                                                                                              $days
     *
     * @return list<array{day: \DateTimeImmutable, groups: list<array{name: string, color: array{bg: string, text: string, border: string}, items: list<array{student: string, details: string, from: \DateTimeImmutable, to: ?\DateTimeImmutable}>}>}>
     */
    public function build(array $items, array $days): array
    {
        $result = [];

        foreach ($days as $day) {
            $dayStart = $day->setTime(0, 0, 0);

            /** @var array<string, array{name: string, color: array{bg: string, text: string, border: string}, items: list<array{student: string, details: string, from: \DateTimeImmutable, to: ?\DateTimeImmutable}>}> $groups */
            $groups = [];

            foreach ($items as $item) {
                $from = $item['from']->setTime(0, 0, 0);
                $to   = ($item['to'] ?? $item['from'])->setTime(0, 0, 0);

                if ($dayStart < $from || $dayStart > $to) {
                    continue;
                }

                $groups[$item['groupId']] ??= [
                    'name'  => $item['groupName'],
                    'color' => $this->colorPalette->colorFor($item['groupId']),
                    'items' => [],
                ];

                $groups[$item['groupId']]['items'][] = [
                    'student' => $item['student'],
                    'details' => $item['details'],
                    'from'    => $item['from'],
                    'to'      => $item['to'],
                ];
            }

            usort($groups, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

            foreach ($groups as &$group) {
                usort($group['items'], static fn (array $a, array $b): int => $a['student'] <=> $b['student']);
            }
            unset($group);

            $result[] = ['day' => $day, 'groups' => $groups];
        }

        return $result;
    }
}
